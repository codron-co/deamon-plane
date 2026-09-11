<?php

namespace App\Services\Themes;

use App\Enums\ThemeInstallationStatus;
use App\Jobs\ThemeInstallJob;
use App\Jobs\ThemeUpdateJob;
use App\Models\Site;
use App\Models\SiteThemeInstallation;
use App\Models\Theme;
use App\Models\User;
use App\Services\Agent\ControlPlaneAgentContract;
use App\Services\Agent\SiteAgentClient;
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
    ) {}

    /**
     * @param  array{ref?: string, activate?: bool, sync?: bool, confirmed?: bool}  $options
     */
    public function assign(Site $site, Theme $theme, ?User $actor, ?string $ip, array $options = []): SiteThemeInstallation
    {
        $this->visibility->assertAssignable($theme, $site);
        $this->assertMutableTheme($theme);

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

        $pending = ThemeUpdateJob::dispatch($installation->id, $actor?->id, $ip, $fromWebhook);
        if ($delaySeconds > 0) {
            $pending->delay(now()->addSeconds($delaySeconds));
        }
    }

    public function syncNow(SiteThemeInstallation $installation, ?User $actor, ?string $ip): void
    {
        $installation->loadMissing(['site', 'theme']);
        $site = $installation->site;
        $theme = $installation->theme;

        if ($site === null || $theme === null) {
            throw new ThemeRolloutException('Installation is missing site or theme.');
        }

        if (! $site->hasAgentSecret()) {
            throw new ThemeRolloutException('Site has no agent secret.');
        }

        $result = $this->syncWithDataRepair($site, $theme, $actor, $ip);

        $site->auditLogs()->create([
            'actor_user_id' => $actor?->id,
            'action' => $result->ok ? 'theme.sync_succeeded' : 'theme.sync_failed',
            'after' => [
                'theme_id' => $theme->theme_id,
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
            $syncResult = $this->syncWithDataRepair($site, $theme, $actor, $ip);
            if (! $syncResult->ok) {
                $this->markError($installation, $site, $theme, $actor, $ip, 'theme.sync_failed', $syncResult->safeMessage);

                return;
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

        $payload = $this->themePayload($theme, $installation);
        $result = $this->agent->updateTheme($site, $payload);

        if (! $result->ok) {
            $this->markError($installation, $site, $theme, $actor, $ip, 'theme.update_failed', $result->safeMessage);

            return;
        }

        if ($result->sha !== null) {
            $installation->pinned_sha = $result->sha;
        } elseif ($theme->latest_sha) {
            $installation->pinned_sha = $theme->latest_sha;
        }

        if ($fromWebhook) {
            $installation->updated_from_webhook_at = now();
        }

        $installation->status = ThemeInstallationStatus::Active;
        $installation->last_error = null;
        $installation->save();

        $site->auditLogs()->create([
            'actor_user_id' => $actor?->id,
            'action' => $fromWebhook ? 'theme.webhook_updated' : 'theme.update_succeeded',
            'after' => $this->auditSnapshot($installation->fresh() ?? $installation, $theme),
            'ip' => $ip,
        ]);
    }

    /**
     * The CMS reports `data_package_missing` when the site has no theme-data root —
     * typically a theme installed by an agent older than CMS 1.2.14, which published
     * only the `theme/` subtree. Install the data package from the clone once, then
     * retry the sync so the operator does not need SSH.
     */
    private function syncWithDataRepair(Site $site, Theme $theme, ?User $actor, ?string $ip): ThemeAgentResult
    {
        $body = ControlPlaneAgentContract::syncBody($theme->theme_id);
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

        if (! $repair->ok) {
            return $repair;
        }

        return $this->agent->syncTheme($site, $body);
    }

    /**
     * @return array<string, mixed>
     */
    private function themePayload(Theme $theme, SiteThemeInstallation $installation): array
    {
        $sha = $installation->pinned_sha ?: $theme->latest_sha;
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
