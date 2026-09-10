<?php

namespace App\Services\Coolify;

use App\Enums\Channel;
use App\Enums\CoolifyGitSourceKind;
use App\Enums\SiteStatus;
use App\Models\CoolifyConnection;
use App\Models\Site;
use App\Services\Coolify\Dto\CoolifyApplication;
use Throwable;

class CoolifySiteTargetSync
{
    /**
     * Fill project / environment / git / server / allowlisted channel from GET application.
     * Does not change status or secrets. 404 (app gone) skips the site.
     */
    public function fill(CoolifyConnection $connection, CoolifyApplicationService $coolify): int
    {
        $updated = 0;

        foreach ($this->sitesFor($connection) as $site) {
            try {
                $app = $coolify->getApp((string) $site->coolify_app_uuid);
            } catch (CoolifyApiException $exception) {
                continue;
            } catch (Throwable) {
                continue;
            }

            $changed = $this->fillSite($site, $app, $connection);
            try {
                if ((new CoolifyDeploymentSync)->sync($site, $coolify) > 0) {
                    $changed = true;
                }
            } catch (CoolifyApiException) {
                // Inventory still fills app targets when the deployments list is missing.
            }

            if ($changed) {
                $updated++;
            }
        }

        return $updated;
    }

    /**
     * @return iterable<int, Site>
     */
    private function sitesFor(CoolifyConnection $connection): iterable
    {
        return Site::query()
            ->whereNotNull('coolify_app_uuid')
            ->where('coolify_app_uuid', '!=', '')
            ->where(function ($query) use ($connection): void {
                $query->where('coolify_connection_id', $connection->id);
                if ($connection->is_default) {
                    $query->orWhereNull('coolify_connection_id');
                }
            })
            ->orderBy('id')
            ->get();
    }

    public function fillSite(Site $site, CoolifyApplication $app, CoolifyConnection $connection): bool
    {
        $busy = in_array($site->status, [SiteStatus::Provisioning, SiteStatus::Deploying], true);

        if ($site->coolify_connection_id !== $connection->id) {
            $site->coolify_connection_id = $connection->id;
        }

        $server = $app->serverUuid();
        if ($server !== null) {
            $site->coolify_server_uuid = $server;
        }

        $project = $app->projectUuid();
        if ($project !== null) {
            $site->coolify_project_uuid = $project;
        }

        $environment = $app->environmentUuid();
        if ($environment !== null) {
            $site->coolify_environment_uuid = $environment;
        }

        $gitUuid = $app->gitSourceUuid();
        $gitKind = $app->gitSourceKind();
        if ($gitUuid !== null && $gitKind instanceof CoolifyGitSourceKind) {
            $site->coolify_git_source_uuid = $gitUuid;
            $site->coolify_git_source_kind = $gitKind;
        }

        if (filled($app->gitRepository)) {
            $site->git_repository = $app->gitRepository;
        }

        if (! $busy) {
            $this->applyChannel($site, $app->gitBranch);
        }

        if (! $site->isDirty()) {
            return false;
        }

        $site->save();

        return true;
    }

    private function applyChannel(Site $site, ?string $branch): void
    {
        $normalized = strtolower(trim((string) $branch));
        $channel = Channel::tryFrom($normalized);

        if ($channel instanceof Channel && $channel->isAllowed()) {
            $site->channel = $channel;
            $site->channel_needs_review = false;

            return;
        }

        if ($normalized !== '') {
            $site->channel_needs_review = true;
        }
    }
}
