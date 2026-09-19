<?php

namespace App\Services\Sites;

use App\Enums\DeploymentStatus;
use App\Models\Deployment;
use App\Models\Site;
use App\Models\User;
use App\Services\Agent\SiteHealthChecker;
use App\Services\Coolify\CoolifyApiException;
use App\Services\Coolify\CoolifyAppEnvSync;
use App\Services\Coolify\CoolifyApplicationService;
use App\Services\Coolify\CoolifyDeploymentSync;
use App\Services\Ops\BulkResultSummary;
use App\Services\Ops\PacedFanout;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

class SiteAppHealthFixer
{
    private const COUNTS_CACHE_KEY = 'ops.sites.app_health_category_counts';

    /**
     * @var list<string>
     */
    public const FIX_ORDER = [
        'migrate_compose',
        'sync_env',
        'bind_domains',
        'inject_secret',
        'sync_deployments',
        'restart_app',
        'stop_then_redeploy',
        'rollback_last_good',
        'follow_head',
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
     * Fleet-wide fix counts, with the moment they were computed.
     *
     * The scan behind them walks every site, so the header menu reads a short-lived
     * cache and says how old the number is instead of rescanning per request.
     *
     * @return array{counts: array<string, int>, computed_at: CarbonImmutable}
     */
    public function cachedCategoryCounts(): array
    {
        $ttl = max(0, (int) config('ops.app_health.counts_ttl', 60));
        if ($ttl === 0) {
            return ['counts' => $this->categoryCounts(), 'computed_at' => CarbonImmutable::now()];
        }

        $cached = Cache::remember(self::COUNTS_CACHE_KEY, $ttl, fn (): array => [
            'counts' => $this->categoryCounts(),
            'computed_at' => CarbonImmutable::now()->toIso8601String(),
        ]);

        return [
            'counts' => is_array($cached['counts'] ?? null) ? $cached['counts'] : [],
            'computed_at' => CarbonImmutable::parse((string) ($cached['computed_at'] ?? CarbonImmutable::now())),
        ];
    }

    /**
     * Applying a fix changes what the fleet still needs, so the cached menu must not
     * keep offering work that is already done.
     */
    public function forgetCategoryCounts(): void
    {
        Cache::forget(self::COUNTS_CACHE_KEY);
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
                'restart_app' => $this->restartApp($site, $actor, $ip),
                'sync_deployments' => $this->syncDeployments($site),
                'stop_then_redeploy' => $this->stopThenRedeploy($site, $actor, $ip),
                'rollback_last_good' => $this->rollbackLastGood($site, $actor, $ip),
                'follow_head' => $this->deploys->followHead($site, $actor, $ip),
                default => throw new InvalidArgumentException(__('sites.app_health.unknown_fix')),
            };
        } catch (ComposePackException|SiteProvisionException|CoolifyApiException $exception) {
            throw new SiteAppHealthException($exception->getMessage(), (int) $exception->getCode(), $exception);
        } finally {
            // Even a failed attempt can have changed the site, so never serve the old counts.
            $this->forgetCategoryCounts();
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
     * @return array{ok: int, failed: int, skipped: int, waiting: int, errors: list<string>, rate_limited: bool, sites: list<array<string, mixed>>}
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
            'skipped' => $result['skipped'],
            'waiting' => $result['waiting'],
            'errors' => $result['errors'],
            'rate_limited' => $result['rate_limited'],
            'sites' => array_values($rows),
        ];
    }

    /**
     * @param  array{ok?: int, failed?: int, skipped?: int, waiting?: int}  $result
     * @param  bool  $deployTriggered  A fix set ending in a redeploy: Coolify is still building.
     */
    public function summarize(array $result, bool $deployTriggered = false): string
    {
        $skipped = BulkResultSummary::skipped($result);
        $waiting = BulkResultSummary::waiting($result);
        $ok = (int) ($result['ok'] ?? 0);
        $counts = [
            'ok' => $ok,
            'failed' => (int) ($result['failed'] ?? 0),
            'skipped' => $skipped,
        ];

        $done = $skipped > 0
            ? __('sites.app_health.bulk_done_skipped', $counts)
            : __('sites.app_health.bulk_done', $counts);

        if ($waiting > 0) {
            $done .= ', '.__('ops.bulk.waiting', ['waiting' => $waiting]);
        }

        $notes = array_filter([
            $deployTriggered && $ok > 0 ? BulkResultSummary::triggeredNote() : '',
            BulkResultSummary::throttleNote($result),
            BulkResultSummary::waitingNote($result),
        ], static fn (string $note): bool => $note !== '');

        return $notes === [] ? $done : $done.' '.implode(' ', $notes);
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

        $this->envSync->sync($site, CoolifyApplicationService::forSite($site));
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

    /**
     * Restart the compose containers in place (no rebuild). This is the recovery for
     * a container that crashed under `restart: no` and left the proxy on the
     * placeholder page.
     */
    private function restartApp(Site $site, ?User $actor, ?string $ip): void
    {
        $uuid = $this->requireApp($site);

        CoolifyApplicationService::forSite($site)->restartApplication($uuid);

        $site->auditLogs()->create([
            'actor_user_id' => $actor?->id,
            'action' => 'site.app_restarted',
            'after' => ['coolify_app_uuid' => $uuid],
            'ip' => $ip,
        ]);
    }

    /**
     * Pull the last deployments from Coolify: a poll timeout or a 429 leaves a row
     * marked failed while Coolify may have finished.
     */
    private function syncDeployments(Site $site): void
    {
        $this->requireApp($site);

        $coolify = CoolifyApplicationService::forSite($site);
        app(CoolifyDeploymentSync::class)->sync($site, $coolify);
        app(CoolifyDeploymentSync::class)->recoverSiteIfLatestFinished($site);
    }

    private function stopThenRedeploy(Site $site, ?User $actor, ?string $ip): void
    {
        $uuid = $this->requireApp($site);

        CoolifyApplicationService::forSite($site)->stopApplication($uuid);
        $this->deploys->redeploy($site, $actor, $ip);
    }

    /**
     * Pin the newest commit that finished on this site. Offered, never automatic:
     * moving a site off HEAD is an operator decision.
     */
    private function rollbackLastGood(Site $site, ?User $actor, ?string $ip): void
    {
        $this->requireApp($site);

        $lastGood = $site->deployments()
            ->where('status', DeploymentStatus::Finished)
            ->whereNotNull('commit_sha')
            ->orderByDesc('finished_at')
            ->orderByDesc('id')
            ->first();

        if (! $lastGood instanceof Deployment || blank($lastGood->commit_sha)) {
            throw new SiteAppHealthException(__('sites.app_health.no_last_good'));
        }

        $this->deploys->pin($site, (string) $lastGood->commit_sha, $actor, $ip);
    }

    private function requireApp(Site $site): string
    {
        $uuid = trim((string) $site->coolify_app_uuid);
        if ($uuid === '') {
            throw new SiteAppHealthException(__('site_ops.pack.missing_app'));
        }

        return $uuid;
    }
}
