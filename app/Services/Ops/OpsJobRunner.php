<?php

namespace App\Services\Ops;

use App\Enums\Channel;
use App\Models\CoolifyConnection;
use App\Models\OpsBackgroundJob;
use App\Models\Site;
use App\Models\User;
use App\Services\Coolify\CoolifyInventorySync;
use App\Services\Coolify\CoolifySiteSync;
use App\Services\GitHub\GitHubApiException;
use App\Services\GitHub\GitHubCredentialsException;
use App\Services\Sites\ChannelSwitcher;
use App\Services\Sites\ComposePackMigrator;
use App\Services\Sites\CoolifyDeploySettings;
use App\Services\Sites\SiteAppHealthFixer;
use App\Services\Sites\SiteAppHealthReport;
use App\Services\Sites\SiteLiveProbe;
use App\Services\Themes\ThemeCatalogSync;
use Illuminate\Support\Collection;
use RuntimeException;

class OpsJobRunner
{
    public function run(OpsBackgroundJob $job): string
    {
        return match ($job->type) {
            'sites.live_sync' => $this->liveSync($job),
            'sites.coolify_sync' => $this->coolifySiteSync($job),
            'coolify.inventory_sync' => $this->coolifyInventorySync($job),
            'themes.catalog_sync' => $this->themeCatalogSync($job),
            'sites.bulk_channel' => $this->bulkChannel($job),
            'sites.bulk_compose' => $this->bulkCompose($job),
            'sites.bulk_auto_deploy' => $this->bulkAutoDeploy($job),
            'sites.bulk_deploy' => $this->bulkDeploy($job),
            'sites.bulk_follow_head' => $this->bulkFollowHead($job),
            'sites.bulk_pin' => $this->bulkPin($job),
            'sites.bulk_app_health_fix' => $this->bulkAppHealthFix($job),
            default => throw new RuntimeException('Unknown ops job type.'),
        };
    }

    private function liveSync(OpsBackgroundJob $job): string
    {
        $sites = $this->sites($job);
        $probe = app(SiteLiveProbe::class);
        $total = max(1, $sites->count());
        $ok = 0;
        $failed = 0;
        $rows = [];

        foreach ($sites->values() as $index => $site) {
            $result = $probe->probeMany([$site]);
            $ok += $result['ok'];
            $failed += $result['failed'];
            $site->refresh();
            $rows[] = [
                'id' => $site->id,
                'live_label' => $site->liveHttpLabel(),
                'live_tone' => $site->liveHttpTone(),
                'favicon' => $site->last_live_favicon_url,
            ];
            $job->updateProgress((int) ((($index + 1) / $total) * 100), $site->name);
        }

        $job->result = ['sites' => $rows];
        $job->save();

        return __('sites.flash.live_synced', ['ok' => $ok, 'failed' => $failed]);
    }

    private function coolifySiteSync(OpsBackgroundJob $job): string
    {
        $sites = $this->sites($job)->filter(fn (Site $site): bool => filled($site->coolify_app_uuid));
        $sync = app(CoolifySiteSync::class);

        $result = $this->fanout(
            $job,
            $sites,
            fn (Site $site) => $sync->sync($site),
        );

        return $this->summaryFor(__('sites.flash.bulk_synced'), $result);
    }

    private function coolifyInventorySync(OpsBackgroundJob $job): string
    {
        $id = (int) ($job->payload['connection_id'] ?? 0);
        $connection = CoolifyConnection::query()->findOrFail($id);
        $job->updateProgress(20, $connection->name);
        $result = app(CoolifyInventorySync::class)->sync($connection);
        $job->updateProgress(100);

        return __('coolify.flash.sync', [
            'servers' => $result['servers'],
            'projects' => $result['projects'],
            'environments' => $result['environments'],
            'git' => $result['git_sources'],
            'sites' => $result['sites'] ?? 0,
        ]);
    }

    private function themeCatalogSync(OpsBackgroundJob $job): string
    {
        try {
            $result = app(ThemeCatalogSync::class)->sync();
        } catch (GitHubCredentialsException|GitHubApiException $exception) {
            throw new RuntimeException($exception->getMessage(), 0, $exception);
        }

        $job->updateProgress(100);

        return __('themes.flash.sync', [
            'created' => $result['created'],
            'updated' => $result['updated'],
            'skipped' => $result['skipped'],
        ]);
    }

    private function bulkChannel(OpsBackgroundJob $job): string
    {
        $target = Channel::from((string) ($job->payload['channel'] ?? ''));
        $actor = $this->actor($job);
        $sites = $this->sites($job);
        $switcher = app(ChannelSwitcher::class);
        $ip = $this->ip($job);

        $targets = $sites->values()->filter(function (Site $site) use ($target): bool {
            $current = $site->channel instanceof Channel ? $site->channel : Channel::tryFrom((string) $site->channel);

            return $current !== $target;
        });

        $result = $this->fanout($job, $targets, fn (Site $site) => $switcher->start(
            $site,
            $target,
            $actor,
            $ip,
            (bool) ($job->payload['confirmed'] ?? false),
            (bool) ($job->payload['force'] ?? false),
        ));

        return $this->summaryFor(
            __('sites.flash.bulk_channel', ['channel' => $target->value, 'skipped' => 0]),
            $result,
        );
    }

    private function bulkCompose(OpsBackgroundJob $job): string
    {
        $sites = $this->sites($job)->filter(fn (Site $site): bool => $site->hasDockerfileBuildPackWarning());
        $migrator = app(ComposePackMigrator::class);
        $actor = $this->actor($job);
        $ip = $this->ip($job);

        $result = $this->fanout($job, $sites, fn (Site $site) => $migrator->migrate($site, $actor, $ip));

        return $this->summaryFor(__('site_ops.pack.bulk'), $result);
    }

    private function bulkAutoDeploy(OpsBackgroundJob $job): string
    {
        $sites = $this->sites($job)->filter(fn (Site $site): bool => filled($site->coolify_app_uuid));
        $settings = app(CoolifyDeploySettings::class);
        $enabled = array_key_exists('enabled', $job->payload)
            ? (bool) $job->payload['enabled']
            : $settings->toggleEnabledFor($sites);
        $actor = $this->actor($job);
        $ip = $this->ip($job);

        $result = $this->fanout($job, $sites, fn (Site $site) => $settings->setAutoDeploy($site, $enabled, $actor, $ip));

        return $this->summaryFor(
            $enabled ? __('site_ops.auto_deploy.bulk_on') : __('site_ops.auto_deploy.bulk_off'),
            $result,
        );
    }

    private function bulkDeploy(OpsBackgroundJob $job): string
    {
        $sites = $this->sites($job)->filter(fn (Site $site): bool => filled($site->coolify_app_uuid));
        $settings = app(CoolifyDeploySettings::class);
        $actor = $this->actor($job);
        $ip = $this->ip($job);

        $result = $this->fanout($job, $sites, fn (Site $site) => $settings->redeploy($site, $actor, $ip));

        return $this->summaryFor(__('site_ops.redeploy.bulk'), $result);
    }

    private function bulkFollowHead(OpsBackgroundJob $job): string
    {
        $sites = $this->sites($job)->filter(fn (Site $site): bool => filled($site->coolify_app_uuid));
        $settings = app(CoolifyDeploySettings::class);
        $actor = $this->actor($job);
        $ip = $this->ip($job);

        $result = $this->fanout($job, $sites, fn (Site $site) => $settings->followHead($site, $actor, $ip));

        return $this->summaryFor(__('site_ops.pin.bulk_follow'), $result);
    }

    private function bulkPin(OpsBackgroundJob $job): string
    {
        $sites = $this->sites($job)->filter(fn (Site $site): bool => filled($site->coolify_app_uuid));
        $settings = app(CoolifyDeploySettings::class);
        $ref = (string) ($job->payload['ref'] ?? '');
        $actor = $this->actor($job);
        $ip = $this->ip($job);

        $result = $this->fanout($job, $sites, fn (Site $site) => $settings->pin($site, $ref, $actor, $ip));

        return $this->summaryFor(__('site_ops.pin.bulk'), $result);
    }

    private function bulkAppHealthFix(OpsBackgroundJob $job): string
    {
        $fix = (string) ($job->payload['fix'] ?? 'all');
        $sites = $this->sites($job)->loadMissing('latestDeployment');
        $fixer = app(SiteAppHealthFixer::class);
        $actor = $this->actor($job);
        $ip = $this->ip($job);

        $targets = $sites->values()->filter(function (Site $site) use ($fixer, $fix): bool {
            $needed = $fixer->neededFixes($site);

            return $fix === 'all' ? $needed !== [] : in_array($fix, $needed, true);
        });

        $rows = [];
        $result = $this->fanout($job, $targets, function (Site $site) use ($fixer, $fix, $actor, $ip, &$rows): void {
            try {
                $report = $fixer->fix($site, $fix, $actor, $ip);
            } catch (\Throwable $exception) {
                $rows[$site->id] = SiteAppHealthReport::forDisplay($site->fresh() ?? $site)->toView($site);

                throw $exception;
            }

            $rows[$site->id] = $report->toView($site->fresh() ?? $site);
        });

        $job->result = ['sites' => array_values($rows)];
        $job->save();

        return $fixer->summarize($result);
    }

    /**
     * Run one Coolify action per site through the paced fan-out, reporting live
     * progress into the ops jobs widget.
     *
     * @param  Collection<int, Site>  $sites
     * @param  callable(Site): void  $action
     * @return array{ok: int, failed: int, skipped: int, errors: list<string>, rate_limited: bool, throttled: bool, deferrals: int, deferred: int, sites: list<Site>}
     */
    private function fanout(OpsBackgroundJob $job, $sites, callable $action): array
    {
        return app(PacedFanout::class)->run(
            $sites->values(),
            $action,
            function (Site $site, int $completed, int $total) use ($job): void {
                $job->updateProgress((int) (($completed / max(1, $total)) * 100), $site->name);
            },
        );
    }

    private function ip(OpsBackgroundJob $job): ?string
    {
        return isset($job->payload['ip']) ? (string) $job->payload['ip'] : null;
    }

    /**
     * @param  array{ok?: int, failed?: int, skipped?: int}  $result
     */
    private function summaryFor(string $prefix, array $result): string
    {
        return BulkResultSummary::format($prefix, $result);
    }

    /**
     * @return Collection<int, Site>
     */
    private function sites(OpsBackgroundJob $job)
    {
        $ids = $job->payload['site_ids'] ?? [];

        return Site::query()->whereIn('id', $ids)->orderBy('name')->get();
    }

    private function actor(OpsBackgroundJob $job): User
    {
        $user = $job->actor;
        if (! $user instanceof User) {
            throw new RuntimeException('Ops job has no actor.');
        }

        return $user;
    }
}
