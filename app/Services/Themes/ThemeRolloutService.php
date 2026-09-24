<?php

namespace App\Services\Themes;

use App\Enums\ThemeInstallationStatus;
use App\Jobs\ThemeInstallJob;
use App\Jobs\ThemeUpdateJob;
use App\Models\Deployment;
use App\Models\Site;
use App\Models\SiteThemeInstallation;
use App\Models\Theme;
use App\Models\User;
use App\Services\Agent\ControlPlaneAgentContract;
use App\Services\Agent\SiteAgentClient;
use App\Services\Agent\SiteHealthChecker;
use App\Services\Agent\ThemeAgentResult;
use App\Services\GitHub\GitHubAppClient;
use App\Services\GitHub\GitHubCredentialsException;
use Illuminate\Support\Facades\DB;

class ThemeRolloutService
{
    public function __construct(
        private readonly ThemeVisibilityGate $visibility = new ThemeVisibilityGate,
        private readonly ThemeVersionGate $versions = new ThemeVersionGate,
        private readonly SiteAgentClient $agent = new SiteAgentClient,
        private readonly GitHubAppClient $github = new GitHubAppClient,
        private readonly ThemeSmokeCheck $smoke = new ThemeSmokeCheck,
    ) {}

    /**
     * @param  array{ref?: string, activate?: bool, sync?: bool, confirmed?: bool}  $options
     */
    public function assign(Site $site, Theme $theme, ?User $actor, ?string $ip, array $options = []): SiteThemeInstallation
    {
        $this->visibility->assertAssignable($theme, $site);
        $this->assertMutableTheme($theme);

        // theme.json minimum_deamon_version: a theme built for a newer CMS calls core
        // views and routes this site does not have yet. Refuse before anything moves.
        if ($this->versions->shouldSkip($site, $theme)) {
            throw new ThemeRolloutException(__('sites.theme_flash.cms_too_old', [
                'minimum' => (string) $theme->minimum_deamon_version,
                'reported' => (string) $site->reportedDeamonVersion(),
            ]));
        }

        $activate = (bool) ($options['activate'] ?? false);
        if ($activate && ! ($options['confirmed'] ?? false)) {
            throw new ThemeRolloutException(
                'Activating a theme on a live site requires confirmation. This replaces the CMS active theme.',
            );
        }

        if (! $site->hasAgentSecret()) {
            throw new ThemeRolloutException(
                'Site has no agent secret. Inject CONTROL_PLANE_AGENT_SECRET before assigning a theme.',
            );
        }

        $ref = trim((string) ($options['ref'] ?? $theme->default_ref ?? 'main'));
        if ($ref === '') {
            $ref = 'main';
        }

        $installation = DB::transaction(function () use ($site, $theme, $ref, $actor, $ip): SiteThemeInstallation {
            $installation = SiteThemeInstallation::query()->firstOrNew([
                'site_id' => $site->id,
                'theme_id' => $theme->id,
            ]);

            $installation->ref = $ref;
            $installation->status = ThemeInstallationStatus::Pending;
            $installation->last_error = null;
            $installation->auto_update = $installation->exists
                ? (bool) $installation->auto_update
                : (bool) config('ops.themes.auto_update_default', false);
            $installation->save();

            $site->auditLogs()->create([
                'actor_user_id' => $actor?->id,
                'action' => 'theme.assign_started',
                'after' => $this->auditSnapshot($installation->fresh() ?? $installation, $theme),
                'ip' => $ip,
            ]);

            return $installation->fresh() ?? $installation;
        });

        ThemeInstallJob::dispatch(
            $installation->id,
            $activate,
            (bool) ($options['sync'] ?? $activate),
            $actor?->id,
            $ip,
        );

        return $installation->fresh() ?? $installation;
    }

    public function updateToLatest(
        SiteThemeInstallation $installation,
        ?User $actor,
        ?string $ip,
        bool $fromWebhook = false,
        int $delaySeconds = 0,
    ): void {
        $installation->loadMissing(['site', 'theme']);
        $site = $installation->site;
        $theme = $installation->theme;

        if ($site === null || $theme === null) {
            return;
        }

        if ($this->versions->shouldSkip($site, $theme)) {
            $site->auditLogs()->create([
                'actor_user_id' => $actor?->id,
                'action' => 'theme.update_skipped_version',
                'after' => [
                    'theme_id' => $theme->theme_id,
                    'minimum_deamon_version' => $theme->minimum_deamon_version,
                    'reported_deamon_version' => $site->reportedDeamonVersion(),
                    'from_webhook' => $fromWebhook,
                ],
                'ip' => $ip,
            ]);

            return;
        }

        // An unattended update on a CMS that cannot keep site-edited theme files
        // (before 1.2.32, or version unknown) would silently overwrite them. The
        // operator can still update by hand after reading the warning on the site.
        if ($fromWebhook && ! $site->keepsThemeFileCustomizationsOnUpdate()) {
            $site->auditLogs()->create([
                'actor_user_id' => null,
                'action' => 'theme.update_skipped_customizations',
                'after' => [
                    'theme_id' => $theme->theme_id,
                    'reported_deamon_version' => $site->reportedDeamonVersion(),
                    'from_webhook' => true,
                ],
                'ip' => $ip,
            ]);

            return;
        }

        $pending = ThemeUpdateJob::dispatch($installation->id, $actor?->id, $ip, $fromWebhook);
        if ($delaySeconds > 0) {
            $pending->delay(now()->addSeconds($delaySeconds));
        }
    }

    /**
     * Merge (default) adds what the package has and keeps rows the site owner
     * edited. Overwrite also replaces those edits and is never deferred, so the
     * operator sees it run against the state they confirmed.
     *
     * @return 'ran'|'deferred'
     */
    public function syncNow(
        SiteThemeInstallation $installation,
        ?User $actor,
        ?string $ip,
        string $mode = ControlPlaneAgentContract::SYNC_MODE_MERGE,
    ): string {
        if (! in_array($mode, [ControlPlaneAgentContract::SYNC_MODE_MERGE, ControlPlaneAgentContract::SYNC_MODE_OVERWRITE], true)) {
            throw new ThemeRolloutException('Theme sync mode must be merge or overwrite.');
        }

        $installation->loadMissing(['site', 'theme']);
        $site = $installation->site;
        $theme = $installation->theme;

        if ($site === null || $theme === null) {
            throw new ThemeRolloutException('Installation is missing site or theme.');
        }

        if (! $site->hasAgentSecret()) {
            throw new ThemeRolloutException('Site has no agent secret.');
        }

        if ($mode === ControlPlaneAgentContract::SYNC_MODE_MERGE && $this->deferSyncIfDeployOpen($installation, $site)) {
            return 'deferred';
        }

        $result = $this->syncWithDataRepair($site, $theme, $actor, $ip, $mode);

        $site->auditLogs()->create([
            'actor_user_id' => $actor?->id,
            'action' => $result->ok ? 'theme.sync_succeeded' : 'theme.sync_failed',
            'after' => [
                'theme_id' => $theme->theme_id,
                'mode' => $mode,
                'ok' => $result->ok,
            ],
            'ip' => $ip,
        ]);

        if (! $result->ok) {
            $installation->status = ThemeInstallationStatus::Error;
            $installation->last_error = $result->safeMessage;
            $installation->save();

            throw new ThemeRolloutException($result->safeMessage);
        }

        // A sync that succeeded — including one that only succeeded after the
        // data-package repair below — must clear the error a previous attempt
        // parked on the row, or the panel keeps showing a fixed failure.
        if ($installation->status === ThemeInstallationStatus::Error) {
            $installation->status = ThemeInstallationStatus::Active;
        }

        $installation->pending_sync_after_deploy = false;
        $installation->last_error = null;
        if ($result->taskId !== null) {
            $installation->last_sync_task_id = $result->taskId;
        }
        $installation->save();

        if (! $this->guardStorefront($installation, $site, $theme, 'sync', $actor, $ip)) {
            throw new ThemeRolloutException((string) $installation->last_error);
        }

        return 'ran';
    }

    /**
     * Asks the CMS to restore the rows the last theme sync task changed.
     */
    public function rollbackLastSync(SiteThemeInstallation $installation, ?User $actor, ?string $ip): void
    {
        $installation->loadMissing(['site', 'theme']);
        $site = $installation->site;
        $theme = $installation->theme;
        $taskId = (string) $installation->last_sync_task_id;

        if ($site === null || $theme === null) {
            throw new ThemeRolloutException('Installation is missing site or theme.');
        }

        if ($taskId === '') {
            throw new ThemeRolloutException(__('sites.theme_flash.sync_rollback_unavailable'));
        }

        $result = $this->agent->rollbackThemeSync($site, ['task_id' => $taskId]);

        $site->auditLogs()->create([
            'actor_user_id' => $actor?->id,
            'action' => $result->ok ? 'theme.sync_rollback_succeeded' : 'theme.sync_rollback_failed',
            'after' => [
                'theme_id' => $theme->theme_id,
                'task_id' => $taskId,
                'ok' => $result->ok,
                'error' => $result->ok ? null : $result->safeMessage,
            ],
            'ip' => $ip,
        ]);

        if (! $result->ok) {
            throw new ThemeRolloutException($result->safeMessage);
        }

        $installation->last_sync_task_id = null;
        $installation->save();
    }

    /**
     * Puts the theme files back on the commit that ran before the last update.
     * The two SHAs swap, so the operator can move forward again the same way.
     */
    public function rollbackThemeFiles(SiteThemeInstallation $installation, ?User $actor, ?string $ip): void
    {
        $installation->loadMissing(['site', 'theme']);
        $site = $installation->site;
        $theme = $installation->theme;
        $previousSha = (string) $installation->previous_pinned_sha;

        if ($site === null || $theme === null) {
            throw new ThemeRolloutException('Installation is missing site or theme.');
        }

        if ($previousSha === '') {
            throw new ThemeRolloutException(__('sites.theme_flash.files_rollback_unavailable'));
        }

        $this->assertMutableTheme($theme);

        $result = $this->agent->updateTheme($site, $this->themePayload($theme, $installation, sha: $previousSha));

        $site->auditLogs()->create([
            'actor_user_id' => $actor?->id,
            'action' => $result->ok ? 'theme.files_rollback_succeeded' : 'theme.files_rollback_failed',
            'after' => [
                'theme_id' => $theme->theme_id,
                'from_sha' => $installation->pinned_sha,
                'to_sha' => $previousSha,
                'ok' => $result->ok,
                'error' => $result->ok ? null : $result->safeMessage,
            ],
            'ip' => $ip,
        ]);

        if (! $result->ok) {
            throw new ThemeRolloutException($result->safeMessage);
        }

        $installation->previous_pinned_sha = $installation->pinned_sha;
        $installation->pinned_sha = $result->sha ?? $previousSha;
        $installation->status = ThemeInstallationStatus::Active;
        $installation->last_error = null;
        $installation->save();
    }

    public function activate(SiteThemeInstallation $installation, ?User $actor, ?string $ip, bool $confirmed): void
    {
        if (! $confirmed) {
            throw new ThemeRolloutException(
                'Activating a theme on a live site requires confirmation. This replaces the CMS active theme.',
            );
        }

        $this->runActivate($installation, $actor, $ip);
    }

    public function setAutoUpdate(SiteThemeInstallation $installation, bool $enabled, ?User $actor, ?string $ip): void
    {
        $before = (bool) $installation->auto_update;
        $installation->auto_update = $enabled;
        $installation->save();

        $installation->site?->auditLogs()->create([
            'actor_user_id' => $actor?->id,
            'action' => 'theme.auto_update_changed',
            'before' => ['auto_update' => $before],
            'after' => [
                'auto_update' => $enabled,
                'theme_id' => $installation->theme?->theme_id,
            ],
            'ip' => $ip,
        ]);
    }

    public function performInstall(SiteThemeInstallation $installation, bool $activate, bool $sync, ?User $actor, ?string $ip): void
    {
        $installation->loadMissing(['site', 'theme']);
        $site = $installation->site;
        $theme = $installation->theme;

        if ($site === null || $theme === null) {
            return;
        }

        if ($theme->theme_id === ControlPlaneAgentContract::SYSTEM_THEME_ID) {
            $this->markError(
                $installation,
                $site,
                $theme,
                $actor,
                $ip,
                'theme.install_failed',
                'The default system theme cannot be installed or updated via the agent.',
            );

            return;
        }

        $installation->status = ThemeInstallationStatus::Installing;
        $installation->last_error = null;
        $installation->save();

        $payload = $this->themePayload($theme, $installation);
        $result = $this->agent->installTheme($site, $payload);

        if (! $result->ok) {
            $this->markError($installation, $site, $theme, $actor, $ip, 'theme.install_failed', $result->safeMessage);

            return;
        }

        if ($result->sha !== null) {
            $installation->pinned_sha = $result->sha;
        }

        if ($activate) {
            $this->runActivate($installation, $actor, $ip, persistError: false);
        }

        if ($sync) {
            if (! $this->deferSyncIfDeployOpen($installation, $site)) {
                $syncResult = $this->syncWithDataRepair($site, $theme, $actor, $ip);
                if (! $syncResult->ok) {
                    $this->markError($installation, $site, $theme, $actor, $ip, 'theme.sync_failed', $syncResult->safeMessage);

                    return;
                }

                $installation->pending_sync_after_deploy = false;
                $installation->save();
            }
        }

        if (! $activate) {
            $installation->status = ThemeInstallationStatus::Active;
            $installation->last_error = null;
            $installation->save();
        }

        $site->auditLogs()->create([
            'actor_user_id' => $actor?->id,
            'action' => 'theme.install_succeeded',
            'after' => $this->auditSnapshot($installation->fresh() ?? $installation, $theme),
            'ip' => $ip,
        ]);

        $this->guardStorefront($installation, $site, $theme, 'install', $actor, $ip);
    }

    public function performUpdate(SiteThemeInstallation $installation, ?User $actor, ?string $ip, bool $fromWebhook = false): void
    {
        $installation->loadMissing(['site', 'theme']);
        $site = $installation->site;
        $theme = $installation->theme;

        if ($site === null || $theme === null) {
            return;
        }

        if ($theme->theme_id === ControlPlaneAgentContract::SYSTEM_THEME_ID) {
            $this->markError(
                $installation,
                $site,
                $theme,
                $actor,
                $ip,
                'theme.update_failed',
                'The default system theme cannot be installed or updated via the agent.',
            );

            return;
        }

        $installation->status = ThemeInstallationStatus::Updating;
        $installation->last_error = null;
        $installation->save();

        $payload = $this->themePayload($theme, $installation, preferLatest: true);
        $result = $this->agent->updateTheme($site, $payload);

        if (! $result->ok) {
            $this->markError($installation, $site, $theme, $actor, $ip, 'theme.update_failed', $result->safeMessage);

            return;
        }

        $installedSha = $result->sha ?? ($theme->latest_sha ?: null);

        if ($installedSha !== null && $installedSha !== $installation->pinned_sha) {
            $installation->previous_pinned_sha = $installation->pinned_sha;
            $installation->pinned_sha = $installedSha;
        }

        if ($fromWebhook) {
            $installation->updated_from_webhook_at = now();
        }

        // CMS 1.2.31+ keeps theme files the site edited and lists them; older CMS
        // omits the key, so keep whatever an earlier update reported.
        if ($result->customizations !== null) {
            $installation->customized_files = $result->customizations;
        }

        $installation->status = ThemeInstallationStatus::Active;
        $installation->last_error = null;
        $installation->save();

        $site->auditLogs()->create([
            'actor_user_id' => $actor?->id,
            'action' => $fromWebhook ? 'theme.webhook_updated' : 'theme.update_succeeded',
            'after' => [
                ...$this->auditSnapshot($installation->fresh() ?? $installation, $theme),
                'customizations' => $result->customizations,
            ],
            'ip' => $ip,
        ]);

        $this->guardStorefront($installation, $site, $theme, 'update', $actor, $ip);
    }

    /**
     * The agent reporting success does not mean the storefront renders: moonagro's
     * new theme installed cleanly and /timeline 500'd on a core partial the CMS
     * volume lacked. GET the pages; on a 5xx decide whose fault it is.
     *
     * - The CMS reports its core theme stale → not this theme. The health poll
     *   restarts the app (CoreThemeHealer); the theme change stays.
     * - Otherwise undo what just ran: an update goes back to the previous commit,
     *   a sync restores the rows it changed. A first install has nothing to go back
     *   to, so it only turns red with the failing pages.
     *
     * Only the active theme renders the storefront, so an inactive install is not checked.
     *
     * @param  'install'|'update'|'sync'  $change
     */
    private function guardStorefront(
        SiteThemeInstallation $installation,
        Site $site,
        Theme $theme,
        string $change,
        ?User $actor,
        ?string $ip,
    ): bool {
        $installation->refresh();
        if (! $installation->is_active || $installation->status === ThemeInstallationStatus::Error) {
            return true;
        }

        $smoke = $this->smoke->run($site, $theme);
        if (! $smoke->failed()) {
            return true;
        }

        $health = app(SiteHealthChecker::class)->check($site);
        $coreStale = ($health->summary['core_theme_in_sync'] ?? null) === false;
        $rolledBack = $coreStale ? null : $this->rollbackAfterSmoke($installation, $change, $actor, $ip);

        $message = match (true) {
            $coreStale => __('sites.theme_flash.smoke_core_stale', ['pages' => $smoke->summary()]),
            $rolledBack !== null => __('sites.theme_flash.smoke_rolled_back_'.$rolledBack, ['pages' => $smoke->summary()]),
            default => __('sites.theme_flash.smoke_failed', ['pages' => $smoke->summary()]),
        };

        $installation->refresh();
        $installation->status = ThemeInstallationStatus::Error;
        $installation->last_error = $message;
        $installation->save();

        $site->auditLogs()->create([
            'actor_user_id' => $actor?->id,
            'action' => 'theme.smoke_failed',
            'after' => [
                'theme_id' => $theme->theme_id,
                'change' => $change,
                'failures' => $smoke->failures,
                'core_theme_stale' => $coreStale,
                'rolled_back' => $rolledBack,
            ],
            'ip' => $ip,
        ]);

        return false;
    }

    /**
     * @return 'files'|'sync'|null what was undone
     */
    private function rollbackAfterSmoke(SiteThemeInstallation $installation, string $change, ?User $actor, ?string $ip): ?string
    {
        try {
            if ($change === 'update' && filled($installation->previous_pinned_sha)) {
                $this->rollbackThemeFiles($installation, $actor, $ip);

                return 'files';
            }

            if ($change === 'sync' && filled($installation->last_sync_task_id)) {
                $this->rollbackLastSync($installation, $actor, $ip);

                return 'sync';
            }
        } catch (ThemeRolloutException) {
            // The rollback's own audit row names why; the smoke failure still stands.
        }

        return null;
    }

    /**
     * While a Coolify deploy is still open, theme sync must not hit the site agent.
     * Mark the installation so ThemeSyncAfterDeployJob can fire sync after finish.
     */
    private function deferSyncIfDeployOpen(SiteThemeInstallation $installation, Site $site): bool
    {
        $open = Deployment::query()
            ->where('site_id', $site->id)
            ->whereNull('finished_at')
            ->exists();

        if (! $open) {
            return false;
        }

        $installation->pending_sync_after_deploy = true;
        $installation->save();

        return true;
    }

    /**
     * The CMS reports `data_package_missing` when the site has no theme-data root —
     * typically a theme installed by an agent older than CMS 1.2.14, which published
     * only the `theme/` subtree. Install the data package from the clone once, then
     * retry the sync so the operator does not need SSH.
     */
    private function syncWithDataRepair(
        Site $site,
        Theme $theme,
        ?User $actor,
        ?string $ip,
        string $mode = ControlPlaneAgentContract::SYNC_MODE_MERGE,
    ): ThemeAgentResult {
        $body = ControlPlaneAgentContract::syncBody($theme->theme_id, ControlPlaneAgentContract::SYNC_ACTION_ALL, $mode);
        $result = $this->agent->syncTheme($site, $body);

        if ($result->ok || $result->errorCode !== 'data_package_missing') {
            return $result;
        }

        $repair = $this->agent->installThemeData($site, ['theme_id' => $theme->theme_id]);

        $site->auditLogs()->create([
            'actor_user_id' => $actor?->id,
            'action' => $repair->ok ? 'theme.data_installed' : 'theme.data_install_failed',
            'after' => [
                'theme_id' => $theme->theme_id,
                'ok' => $repair->ok,
                'error' => $repair->ok ? null : $repair->safeMessage,
            ],
            'ip' => $ip,
        ]);

        // The repair is a best-effort side quest, so its own failure mode (a 404 on
        // a CMS older than 1.2.14, a pruned clone) is only audit detail. Surface the
        // original sync error, which names the missing data package for the operator.
        if (! $repair->ok) {
            return $result;
        }

        return $this->agent->syncTheme($site, $body);
    }

    /**
     * Install (re)publishes what the site already runs, so the pinned SHA wins.
     * Update moves the site forward, so the catalog's latest SHA wins; the pin
     * is only a fallback while the catalog has not seen a push yet.
     *
     * @return array<string, mixed>
     */
    private function themePayload(
        Theme $theme,
        SiteThemeInstallation $installation,
        bool $preferLatest = false,
        ?string $sha = null,
    ): array {
        $sha ??= $preferLatest
            ? ($theme->latest_sha ?: $installation->pinned_sha)
            : ($installation->pinned_sha ?: $theme->latest_sha);
        $cloneToken = null;

        try {
            $theme->loadMissing('gitConnection');
            $cloneToken = $this->github->mintCloneToken($theme->gitConnection);
        } catch (GitHubCredentialsException) {
            $cloneToken = null;
        }

        return ControlPlaneAgentContract::installBody(
            $theme->theme_id,
            $theme->repo_full_name,
            $installation->ref ?: $theme->default_ref ?: 'main',
            is_string($sha) && $sha !== '' ? $sha : null,
            is_string($cloneToken) && $cloneToken !== '' ? $cloneToken : null,
        );
    }

    private function assertMutableTheme(Theme $theme): void
    {
        if ($theme->theme_id === ControlPlaneAgentContract::SYSTEM_THEME_ID) {
            throw new ThemeRolloutException(
                'The default system theme cannot be installed or updated via the agent.',
            );
        }
    }

    private function runActivate(
        SiteThemeInstallation $installation,
        ?User $actor,
        ?string $ip,
        bool $persistError = true,
    ): void {
        $installation->loadMissing(['site', 'theme']);
        $site = $installation->site;
        $theme = $installation->theme;

        if ($site === null || $theme === null) {
            return;
        }

        $result = $this->agent->activateTheme($site, ['theme_id' => $theme->theme_id]);

        if (! $result->ok) {
            if ($persistError) {
                $this->markError($installation, $site, $theme, $actor, $ip, 'theme.activate_failed', $result->safeMessage);
            }

            if ($persistError) {
                throw new ThemeRolloutException($result->safeMessage);
            }

            return;
        }

        SiteThemeInstallation::query()
            ->where('site_id', $site->id)
            ->where('id', '!=', $installation->id)
            ->update(['is_active' => false]);

        $installation->is_active = true;
        $installation->status = ThemeInstallationStatus::Active;
        $installation->last_error = null;
        $installation->save();

        $site->auditLogs()->create([
            'actor_user_id' => $actor?->id,
            'action' => 'theme.activated',
            'after' => $this->auditSnapshot($installation->fresh() ?? $installation, $theme),
            'ip' => $ip,
        ]);
    }

    private function markError(
        SiteThemeInstallation $installation,
        Site $site,
        Theme $theme,
        ?User $actor,
        ?string $ip,
        string $action,
        string $safeMessage,
    ): void {
        $installation->status = ThemeInstallationStatus::Error;
        $installation->last_error = $safeMessage;
        $installation->save();

        $site->auditLogs()->create([
            'actor_user_id' => $actor?->id,
            'action' => $action,
            'after' => [
                'theme_id' => $theme->theme_id,
                'error' => $safeMessage,
            ],
            'ip' => $ip,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function auditSnapshot(SiteThemeInstallation $installation, Theme $theme): array
    {
        return [
            'theme_id' => $theme->theme_id,
            'repo_full_name' => $theme->repo_full_name,
            'ref' => $installation->ref,
            'pinned_sha' => $installation->pinned_sha,
            'is_active' => $installation->is_active,
            'auto_update' => $installation->auto_update,
            'status' => $installation->status instanceof ThemeInstallationStatus
                ? $installation->status->value
                : $installation->status,
        ];
    }
}
