<?php

namespace App\Services\Sites;

use App\Enums\CoolifyEnvKind;
use App\Enums\DeploymentStatus;
use App\Enums\SiteStatus;
use App\Models\CoolifyEnvDefault;
use App\Models\Deployment;
use App\Models\Site;
use App\Services\Agent\SiteHealthEvaluator;
use App\Services\Coolify\CoolifyApiException;
use App\Services\Coolify\CoolifyAppEnvSync;
use App\Services\Coolify\CoolifyApplicationService;
use App\Services\Coolify\Dto\CoolifyApplication;
use App\Services\Coolify\Dto\CoolifyEnvironmentVariable;
use App\Services\Coolify\Dto\CreateComposeAppRequest;

class SiteAppHealthInspector
{
    /**
     * Keys that must be filled even when the channel catalog has not been synced yet.
     *
     * @var list<string>
     */
    private const COMPOSE_REQUIRED_FALLBACK = ['DB_PASSWORD', 'MYSQL_ROOT_PASSWORD', 'APP_KEY'];

    public function __construct(
        private readonly SiteHealthEvaluator $agentHealth,
    ) {}

    public function inspect(Site $site, bool $live = true): SiteAppHealthReport
    {
        $issues = $this->localIssues($site);
        $buildPack = null;
        $composeLocation = null;

        if ($live && filled($site->coolify_app_uuid)) {
            try {
                $coolify = CoolifyApplicationService::forSite($site);
                $app = $coolify->getApp((string) $site->coolify_app_uuid);
                $buildPack = $app->buildPack;
                $composeLocation = $app->dockerComposeLocation;
                $issues = $this->mergeIssues($issues, $this->livePackIssues($app));

                if ($app->isComposePack()) {
                    $envs = $this->envMap($coolify->listEnvs((string) $site->coolify_app_uuid));
                    $issues = $this->mergeIssues($issues, $this->liveEnvIssues($site, $envs));
                }

                if ($site->canBindCoolifyDomains()) {
                    $issues = $this->mergeIssues($issues, $this->liveDomainIssues($site, $app));
                }
            } catch (CoolifyApiException) {
                $issues = $this->mergeIssues($issues, [
                    new SiteAppHealthIssue('coolify_unreachable'),
                ]);
            }
        }

        $unique = $this->mergeIssues([], $issues);
        $report = new SiteAppHealthReport(
            checked: true,
            ok: $unique === [],
            buildPack: $buildPack,
            composeLocation: $composeLocation,
            issues: $unique,
            checkedAt: now(),
        );

        $site->last_app_health_at = $report->checkedAt;
        $site->last_app_health_payload = $report->toArray();
        SiteFilterVerdict::applyApp($site);
        $site->save();

        return $report;
    }

    public function localReport(Site $site): SiteAppHealthReport
    {
        $issues = $this->localIssues($site);

        return new SiteAppHealthReport(
            checked: false,
            ok: $issues === [],
            buildPack: null,
            composeLocation: null,
            issues: $issues,
            checkedAt: null,
        );
    }

    /**
     * @return list<SiteAppHealthIssue>
     */
    public function localIssues(Site $site): array
    {
        $status = $site->status instanceof SiteStatus
            ? $site->status
            : SiteStatus::tryFrom((string) $site->status);

        // Not running yet — do not invent "failed deploy" / "agent down" noise.
        if (in_array($status, [SiteStatus::Draft, SiteStatus::Provisioning], true)) {
            return [];
        }

        // Failed provision: only "no Coolify app". Redeploy / agent check are the wrong CTA.
        if ($status === SiteStatus::Error) {
            $issues = [];
            if (blank($site->coolify_app_uuid)) {
                $issues[] = new SiteAppHealthIssue('missing_app');
            }
            if ($site->hasDockerfileBuildPackWarning()) {
                $issues[] = new SiteAppHealthIssue('dockerfile_pack', 'migrate_compose');
            }

            return $issues;
        }

        $issues = [];

        if (blank($site->coolify_app_uuid)) {
            $issues[] = new SiteAppHealthIssue('missing_app');
        }

        if ($site->hasDockerfileBuildPackWarning()) {
            $issues[] = new SiteAppHealthIssue('dockerfile_pack', 'migrate_compose');
        }

        if (! $site->hasAgentSecret()) {
            $issues[] = new SiteAppHealthIssue(
                'missing_agent_secret',
                filled($site->coolify_app_uuid) ? 'inject_secret' : null,
            );
        }

        $latest = $this->latestDeployment($site);

        if ($latest?->status === DeploymentStatus::Failed) {
            $issues[] = new SiteAppHealthIssue(
                'deploy_failed',
                filled($site->coolify_app_uuid) ? 'redeploy' : null,
            );
        }

        if ($site->hasAgentSecret() && $this->agentHealth->isAgentFailing($site)) {
            $issues[] = new SiteAppHealthIssue('agent_unhealthy', 'check_health');
        }

        return $issues;
    }

    /**
     * Persist display merge without Coolify HTTP (after deploy sync / poll).
     */
    public function refreshLocalCached(Site $site): SiteAppHealthReport
    {
        $stored = SiteAppHealthReport::fromStored($site);
        $merged = $stored->checked
            ? SiteAppHealthReport::mergeLocalInto($stored, $site)
            : $this->localReport($site);

        $site->last_app_health_at = now();
        $site->last_app_health_payload = $merged->toArray();
        SiteFilterVerdict::applyApp($site);
        $site->save();

        return $merged;
    }

    /**
     * Latest deployment by Coolify/started clock, then id.
     */
    public function latestDeployment(Site $site): ?Deployment
    {
        if ($site->relationLoaded('deployments')) {
            return $site->deployments
                ->sortByDesc(fn ($d) => [
                    $d->started_at?->getTimestamp() ?? 0,
                    $d->id,
                ])
                ->first();
        }

        return $site->deployments()
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @return list<SiteAppHealthIssue>
     */
    private function livePackIssues(CoolifyApplication $app): array
    {
        $issues = [];

        if ($app->isDockerfilePack()) {
            $issues[] = new SiteAppHealthIssue('dockerfile_pack', 'migrate_compose');
        }

        if ($app->isComposePack()) {
            $expected = CreateComposeAppRequest::DEFAULT_COMPOSE_LOCATION;
            $actual = trim((string) $app->dockerComposeLocation);
            if ($actual !== '' && $actual !== $expected && $actual !== ltrim($expected, '/')) {
                $issues[] = new SiteAppHealthIssue('wrong_compose_file', 'migrate_compose');
            }
        }

        return $issues;
    }

    /**
     * @param  array<string, string>  $envs
     * @return list<SiteAppHealthIssue>
     */
    private function liveEnvIssues(Site $site, array $envs): array
    {
        $issues = [];

        [$required, $statics, $siteRows] = $this->catalogExpectations($site);

        foreach ($required as $key) {
            if ($this->isBlank($envs[$key] ?? null) || $this->isPlaceholder($envs[$key] ?? null)) {
                $issues[] = new SiteAppHealthIssue('missing_env', 'sync_env', $key);
            }
        }

        foreach ($statics as $key => $expected) {
            $current = trim((string) ($envs[$key] ?? ''));
            if ($current !== '' && strcasecmp($current, $expected) !== 0) {
                $issues[] = new SiteAppHealthIssue('wrong_env', 'sync_env', $key);
            }
            if ($current === '') {
                $issues[] = new SiteAppHealthIssue('wrong_env', 'sync_env', $key);
            }
        }

        // A filled site-kind value can still be stale (e.g. CONTROL_PLANE_HOST_ALLOWLIST written
        // for another Plane host), which the CMS enforces. Secrets are never compared here:
        // syncing a "fixed" APP_KEY would make encrypted data unreadable.
        $envSync = app(CoolifyAppEnvSync::class);
        foreach ($siteRows as $row) {
            $current = trim((string) ($envs[$row->key] ?? ''));
            $expected = $envSync->expectedSiteValue($site, $row);
            if ($current !== '' && $expected !== null && $expected !== '' && $current !== $expected) {
                $issues[] = new SiteAppHealthIssue('wrong_env', 'sync_env', (string) $row->key);
            }
        }

        if ($site->hasAgentSecret()) {
            $secret = trim((string) ($envs['CONTROL_PLANE_AGENT_SECRET'] ?? ''));
            if ($secret === '' || $this->isPlaceholder($secret)) {
                $issues[] = new SiteAppHealthIssue('missing_env', 'inject_secret', 'CONTROL_PLANE_AGENT_SECRET');
            }
        }

        return $issues;
    }

    /**
     * Required keys + static expectations from the channel catalog (CMS `.env.production.example`).
     * Without a synced catalog the compose secrets stay required.
     *
     * @return array{0: list<string>, 1: array<string, string>, 2: list<CoolifyEnvDefault>}
     */
    private function catalogExpectations(Site $site): array
    {
        $required = [];
        $statics = [];
        $siteRows = [];

        try {
            $channel = app(CoolifyAppEnvSync::class)->channelFor($site);
            $rows = CoolifyEnvDefault::query()->forChannel($channel)->get();
        } catch (\Throwable) {
            $rows = collect();
        }

        foreach ($rows as $row) {
            if (! $row instanceof CoolifyEnvDefault) {
                continue;
            }

            if ($row->kind === CoolifyEnvKind::Generated || $row->kind === CoolifyEnvKind::Required) {
                $required[] = (string) $row->key;
            } elseif ($row->kind === CoolifyEnvKind::Site && $row->key !== 'CONTROL_PLANE_AGENT_SECRET') {
                $required[] = (string) $row->key;
                if (! $row->is_secret) {
                    $siteRows[] = $row;
                }
            } elseif ($row->kind === CoolifyEnvKind::Static && filled($row->value)) {
                $statics[(string) $row->key] = (string) $row->value;
            }
        }

        if ($required === []) {
            $required = self::COMPOSE_REQUIRED_FALLBACK;
        }

        return [array_values(array_unique($required)), $statics, $siteRows];
    }

    /**
     * @return list<SiteAppHealthIssue>
     */
    private function liveDomainIssues(Site $site, CoolifyApplication $app): array
    {
        $reconciler = app(SiteDomainReconciler::class);
        $missing = $reconciler->missingOnCoolify($site, $reconciler->customerHosts($app));
        if ($missing === []) {
            return [];
        }

        return [
            new SiteAppHealthIssue('domain_unbound', 'bind_domains', $missing[0]),
        ];
    }

    /**
     * @param  iterable<int, mixed>  $envs
     * @return array<string, string>
     */
    private function envMap(iterable $envs): array
    {
        $map = [];

        foreach ($envs as $env) {
            if (! $env instanceof CoolifyEnvironmentVariable || $env->key === '' || $env->isPreview) {
                continue;
            }

            $map[$env->key] = (string) ($env->value() ?? '');
        }

        return $map;
    }

    /**
     * @param  list<SiteAppHealthIssue>  $current
     * @param  list<SiteAppHealthIssue>  $incoming
     * @return list<SiteAppHealthIssue>
     */
    private function mergeIssues(array $current, array $incoming): array
    {
        $seen = [];
        $out = [];

        foreach (array_merge($current, $incoming) as $issue) {
            $fingerprint = $issue->code.'|'.($issue->key ?? '');
            if (isset($seen[$fingerprint])) {
                continue;
            }

            $seen[$fingerprint] = true;
            $out[] = $issue;
        }

        return $out;
    }

    private function isBlank(?string $value): bool
    {
        return trim((string) $value) === '';
    }

    private function isPlaceholder(?string $value): bool
    {
        $trim = strtolower(trim((string) $value));
        if ($trim === '') {
            return true;
        }

        foreach (['change-me', 'changeme', '__her_'] as $needle) {
            if (str_starts_with($trim, $needle)) {
                return true;
            }
        }

        return false;
    }
}
