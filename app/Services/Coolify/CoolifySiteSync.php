<?php

namespace App\Services\Coolify;

use App\Models\CoolifyConnection;
use App\Models\Site;

class CoolifySiteSync
{
    /**
     * Pull the Coolify application and its recent deployments onto one site.
     * Does not write secrets or change site status from historical deploy rows.
     *
     * @return array{filled: bool, deployments: int}
     */
    public function sync(Site $site): array
    {
        $uuid = trim((string) $site->coolify_app_uuid);
        if ($uuid === '') {
            throw new CoolifyApiException(__('sites.flash.sync_missing_app'), 400);
        }

        $site->loadMissing('coolifyConnection');
        $connection = $site->coolifyConnection instanceof CoolifyConnection
            ? $site->coolifyConnection
            : CoolifyConnection::default();

        if (! $connection instanceof CoolifyConnection) {
            throw new CoolifyApiException('Coolify connection is not configured.', 400);
        }

        $coolify = CoolifyApplicationService::forSite($site);
        $app = $coolify->getApp($uuid);
        $filled = (new CoolifySiteTargetSync)->fillSite($site, $app, $connection);
        $deployments = (new CoolifyDeploymentSync)->sync($site->fresh() ?? $site, $coolify);

        try {
            app(\App\Services\Sites\SiteAppHealthInspector::class)->refreshLocalCached($site->fresh() ?? $site);
        } catch (\Throwable) {
            // Keep sync successful even if health cache refresh fails.
        }

        return [
            'filled' => $filled,
            'deployments' => $deployments,
        ];
    }
}
