<?php

namespace App\Services\Coolify;

use App\Models\Deployment;
use App\Models\Site;
use Illuminate\Database\Eloquent\Builder;

/**
 * Caps concurrent Coolify builds per connection (or server) so shared VPS
 * memory is not starved by parallel compose builds.
 */
class CoolifyDeployGate
{
    public function assertCanStartDeploy(Site $site): void
    {
        $max = max(1, (int) config('ops.coolify.deploy.max_concurrent_per_server', 1));
        $inFlight = $this->unfinishedCount($site);

        if ($inFlight >= $max) {
            throw new CoolifyDeployBusyException(__('coolify.errors.deploy_busy', [
                'max' => $max,
                'count' => $inFlight,
            ]));
        }
    }

    public function unfinishedCount(Site $site): int
    {
        return Deployment::query()
            ->whereNull('finished_at')
            ->whereIn('site_id', $this->peerSites($site)->select('id'))
            ->count();
    }

    /**
     * Sites that share the same Coolify host: connection first, else server uuid.
     *
     * @return Builder<Site>
     */
    private function peerSites(Site $site): Builder
    {
        if ($site->coolify_connection_id !== null) {
            return Site::query()->where('coolify_connection_id', $site->coolify_connection_id);
        }

        $serverUuid = trim((string) $site->coolify_server_uuid);
        if ($serverUuid !== '') {
            return Site::query()->where('coolify_server_uuid', $serverUuid);
        }

        return Site::query()->whereKey($site->id);
    }
}
