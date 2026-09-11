<?php

namespace App\Services\Coolify;

use App\Models\CoolifyConnection;
use App\Models\Site;
use App\Services\Sites\SiteAppHealthInspector;
use App\Services\Sites\SiteDomainReconciler;

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
            throw new CoolifyApiException(__('coolify.errors.not_configured'), 400);
        }

        $coolify = CoolifyApplicationService::forSite($site);
        $app = $coolify->getApp($uuid);
        $filled = (new CoolifySiteTargetSync)->fillSite($site, $app, $connection);

        $autoRebind = (bool) config('ops.coolify.auto_rebind_domains', true);
        $domains = ['imported' => 0, 'conflicts' => 0, 'rebound' => false, 'missing' => []];
        try {
            $domains = app(SiteDomainReconciler::class)->reconcile(
                $site->fresh() ?? $site,
                $app,
                $autoRebind,
            );
        } catch (\Throwable) {
            // Domain reconcile must not abort deployment sync.
        }

        $deploymentSync = new CoolifyDeploymentSync;
        $deployments = $deploymentSync->sync($site->fresh() ?? $site, $coolify);

        try {
            // Rows already Finished skip writeRemoteState dirty path — still clear stale Error.
            $deploymentSync->recoverSiteIfLatestFinished($site->fresh() ?? $site);
        } catch (\Throwable) {
            // Status recovery must not abort sync.
        }

        try {
            app(SiteAppHealthInspector::class)->refreshLocalCached($site->fresh() ?? $site);
        } catch (\Throwable) {
            // Keep sync successful even if health cache refresh fails.
        }

        return [
            'filled' => $filled,
            'deployments' => $deployments,
            'domains' => $domains,
        ];
    }
}
