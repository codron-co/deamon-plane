<?php

namespace App\Services\Sites;

use App\Enums\DeploymentStatus;
use App\Models\Site;
use App\Services\Agent\SiteHealthEvaluator;
use App\Services\Coolify\CoolifyApiException;
use App\Services\Coolify\CoolifyApplicationService;
use App\Services\Coolify\Dto\CoolifyApplication;
use App\Services\Coolify\Dto\CoolifyEnvironmentVariable;
use App\Services\Coolify\Dto\CreateComposeAppRequest;

class SiteAppHealthInspector
{
    /**
     * @var list<string>
     */
    private const COMPOSE_REQUIRED = ['DB_PASSWORD', 'MYSQL_ROOT_PASSWORD', 'APP_KEY'];

    /**
     * @var array<string, string>
     */
    private const COMPOSE_STATICS = [
        'DB_HOST' => 'mysql',
        'DB_CONNECTION' => 'mysql',
    ];

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

        $latest = $site->relationLoaded('latestDeployment')
            ? $site->latestDeployment
            : $site->latestDeployment()->first();

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

        foreach (self::COMPOSE_REQUIRED as $key) {
            if ($this->isBlank($envs[$key] ?? null) || $this->isPlaceholder($envs[$key] ?? null)) {
                $issues[] = new SiteAppHealthIssue('missing_env', 'sync_env', $key);
            }
        }

        foreach (self::COMPOSE_STATICS as $key => $expected) {
            $current = trim((string) ($envs[$key] ?? ''));
            if ($current !== '' && strcasecmp($current, $expected) !== 0) {
                $issues[] = new SiteAppHealthIssue('wrong_env', 'sync_env', $key);
            }
            if ($current === '') {
                $issues[] = new SiteAppHealthIssue('wrong_env', 'sync_env', $key);
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
