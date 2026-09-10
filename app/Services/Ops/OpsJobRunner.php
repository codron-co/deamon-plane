<?php

namespace App\Services\Ops;

use App\Enums\Channel;
use App\Models\CoolifyConnection;
use App\Models\OpsBackgroundJob;
use App\Models\Site;
use App\Models\User;
use App\Services\Coolify\CoolifyApiException;
use App\Services\Coolify\CoolifyInventorySync;
use App\Services\Coolify\CoolifySiteSync;
use App\Services\GitHub\GitHubApiException;
use App\Services\GitHub\GitHubCredentialsException;
use App\Services\Sites\ChannelSwitcher;
use App\Services\Sites\ChannelSwitchException;
use App\Services\Sites\ComposePackMigrator;
use App\Services\Sites\CoolifyDeploySettings;
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
        $total = max(1, $sites->count());
        $ok = 0;
        $failed = 0;
        $deployments = 0;
        $errors = [];

        foreach ($sites->values() as $index => $site) {
            try {
                $result = $sync->sync($site);
                $ok++;
                $deployments += (int) ($result['deployments'] ?? 0);
            } catch (CoolifyApiException $exception) {
                $failed++;
                $errors[] = $site->name.': '.$exception->getMessage();
            }
            $job->updateProgress((int) ((($index + 1) / $total) * 100), $site->name);
        }

        return trim(__('sites.flash.bulk_synced').' '.$ok.' ok'.($failed > 0 ? ', '.$failed.' failed' : ''));
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
        $total = max(1, $sites->count());
        $ok = 0;
        $failed = 0;

        foreach ($sites->values() as $index => $site) {
            $current = $site->channel instanceof Channel ? $site->channel : Channel::tryFrom((string) $site->channel);
            if ($current === $target) {
                $job->updateProgress((int) ((($index + 1) / $total) * 100), $site->name);

                continue;
            }

            try {
                $switcher->start(
                    $site,
                    $target,
                    $actor,
                    isset($job->payload['ip']) ? (string) $job->payload['ip'] : null,
                    (bool) ($job->payload['confirmed'] ?? false),
                    (bool) ($job->payload['force'] ?? false),
                );
                $ok++;
            } catch (ChannelSwitchException $exception) {
                $failed++;
            }
            $job->updateProgress((int) ((($index + 1) / $total) * 100), $site->name);
        }

        return __('sites.flash.bulk_channel', ['channel' => $target->value, 'skipped' => 0]).' '.$ok.' ok'.($failed > 0 ? ', '.$failed.' failed' : '');
    }

    private function bulkCompose(OpsBackgroundJob $job): string
    {
        $sites = $this->sites($job)->filter(fn (Site $site): bool => $site->hasDockerfileBuildPackWarning());
        $result = app(ComposePackMigrator::class)->migrateMany(
            $sites,
            $this->actor($job),
            isset($job->payload['ip']) ? (string) $job->payload['ip'] : null,
        );
        $job->updateProgress(100);

        return __('site_ops.pack.bulk').' '.$result['ok'].' ok';
    }

    private function bulkAutoDeploy(OpsBackgroundJob $job): string
    {
        $sites = $this->sites($job)->filter(fn (Site $site): bool => filled($site->coolify_app_uuid));
        $settings = app(CoolifyDeploySettings::class);
        $enabled = array_key_exists('enabled', $job->payload)
            ? (bool) $job->payload['enabled']
            : $settings->toggleEnabledFor($sites);
        $result = $settings->setAutoDeployMany(
            $sites,
            $enabled,
            $this->actor($job),
            isset($job->payload['ip']) ? (string) $job->payload['ip'] : null,
        );
        $job->updateProgress(100);

        return ($enabled ? __('site_ops.auto_deploy.bulk_on') : __('site_ops.auto_deploy.bulk_off')).' '.$result['ok'].' ok';
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
