<?php

namespace App\Services\Sites;

use App\Enums\CoolifyEnvPack;
use App\Models\Site;
use App\Models\User;
use App\Services\Agent\SiteHealthChecker;
use App\Services\Coolify\CoolifyApiException;
use App\Services\Coolify\CoolifyAppEnvSync;
use App\Services\Coolify\CoolifyApplicationService;
use App\Services\Ops\PacedFanout;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class SiteAppHealthFixer
{
    /**
     * @var list<string>
     */
    public const FIX_ORDER = [
        'migrate_compose',
        'sync_env',
        'bind_domains',
        'inject_secret',
        'redeploy',
        'check_health',
    ];

    public function __construct(
        private readonly SiteAppHealthInspector $inspector,
        private readonly CoolifyAppEnvSync $envSync,
        private readonly ComposePackMigrator $packMigrator,
        private readonly SiteAgentSecretInjector $secrets,
        private readonly CoolifyDeploySettings $deploys,
        private readonly SiteHealthChecker $health,
    ) {}

    /**
     * Unique fix keys the site currently needs, in safe apply order.
     *
     * @return list<string>
     */
    public function neededFixes(Site $site): array
    {
        return self::orderedUniqueFixes(SiteAppHealthReport::forDisplay($site));
    }

    /**
     * @return list<string>
     */
    public static function orderedUniqueFixes(SiteAppHealthReport $report): array
    {
        $wanted = [];
        foreach ($report->issues as $issue) {
            if ($issue instanceof SiteAppHealthIssue && filled($issue->fix)) {
                $wanted[(string) $issue->fix] = true;
            }
        }

        return array_values(array_filter(
            self::FIX_ORDER,
            static fn (string $fix): bool => isset($wanted[$fix]),
        ));
    }

    /**
     * @return array<string, int>
     */
    public function categoryCounts(): array
    {
        $counts = array_fill_keys(self::FIX_ORDER, 0);

        Site::query()
            ->with('latestDeployment')
            ->orderBy('id')
            ->chunkById(100, function ($sites) use (&$counts): void {
                foreach ($sites as $site) {
                    if (! $site instanceof Site) {
                        continue;
                    }
                    foreach ($this->neededFixes($site) as $fix) {
                        $counts[$fix]++;
                    }
                }
            });

        return array_filter($counts, static fn (int $count): bool => $count > 0);
    }

    public function fix(Site $site, string $fix, ?User $actor = null, ?string $ip = null): SiteAppHealthReport
    {
        if ($fix === 'all') {
            return $this->fixAll($site, $actor, $ip);
        }

        try {
            match ($fix) {
                'sync_env' => $this->syncEnv($site),
                'migrate_compose' => $this->packMigrator->migrate($site, $actor, $ip),
                'inject_secret' => $this->secrets->inject($site, $actor, $ip),
                'redeploy' => $this->deploys->redeploy($site, $actor, $ip),
                'check_health' => $this->health->check($site),
                'bind_domains' => $this->bindDomains($site),
                default => throw new InvalidArgumentException(__('sites.app_health.unknown_fix')),
            };
        } catch (ComposePackException|SiteProvisionException|CoolifyApiException $exception) {
            throw new SiteAppHealthException($exception->getMessage(), (int) $exception->getCode(), $exception);
        }

        return $this->reinspect($site);
    }

    public function fixAll(Site $site, ?User $actor = null, ?string $ip = null): SiteAppHealthReport
    {
        $fixes = $this->neededFixes($site);
        if ($fixes === []) {
            return SiteAppHealthReport::forDisplay($site);
        }

        $report = null;
        foreach ($fixes as $fix) {
            $report = $this->fix($site, $fix, $actor, $ip);
        }

        return $report ?? SiteAppHealthReport::forDisplay($site);
    }

    /**
     * @param  Collection<int, Site>|iterable<int, Site>  $sites
     * @return array{ok: int, failed: int, errors: list<string>, sites: list<array<string, mixed>>}
     */
    public function fixMany(iterable $sites, string $fix, ?User $actor = null, ?string $ip = null): array
    {
        $targets = [];
        foreach ($sites as $site) {
            if (! $site instanceof Site) {
                continue;
            }

            $needed = $this->neededFixes($site);
            if ($fix !== 'all' && ! in_array($fix, $needed, true)) {
                continue;
            }
            if ($fix === 'all' && $needed === []) {
                continue;
            }

            $targets[] = $site;
        }

        $rows = [];
        $result = app(PacedFanout::class)->run(
            $targets,
            function (Site $site) use ($fix, $actor, $ip, &$rows): void {
                try {
                    $report = $this->fix($site, $fix, $actor, $ip);
                } catch (SiteAppHealthException|InvalidArgumentException $exception) {
                    $rows[$site->id] = SiteAppHealthReport::forDisplay($site->fresh() ?? $site)->toView($site);

                    throw $exception;
                }

                $rows[$site->id] = $report->toView($site->fresh() ?? $site);
            },
        );

        return [
            'ok' => $result['ok'],
            'failed' => $result['failed'],
            'errors' => $result['errors'],
            'rate_limited' => $result['rate_limited'],
            'sites' => array_values($rows),
        ];
    }

    private function reinspect(Site $site): SiteAppHealthReport
    {
        try {
            return $this->inspector->inspect($site, live: filled($site->coolify_app_uuid));
        } catch (CoolifyApiException) {
            return $this->inspector->inspect($site, live: false);
        }
    }

    private function syncEnv(Site $site): void
    {
        if (blank($site->coolify_app_uuid)) {
            throw new SiteAppHealthException(__('site_ops.pack.missing_app'));
        }

        $this->envSync->sync($site, CoolifyApplicationService::forSite($site), CoolifyEnvPack::DockerCompose);
    }

    private function bindDomains(Site $site): void
    {
        if (blank($site->coolify_app_uuid)) {
            throw new SiteAppHealthException(__('site_ops.pack.missing_app'));
        }

        try {
            app(SiteLanding::class)->syncCoolifyDomains($site);
        } catch (SiteProvisionException $exception) {
            throw new SiteAppHealthException($exception->getMessage(), (int) $exception->getCode(), $exception);
        }
    }
}
