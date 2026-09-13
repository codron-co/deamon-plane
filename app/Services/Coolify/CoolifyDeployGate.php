<?php

namespace App\Services\Coolify;

use App\Models\Deployment;
use App\Models\Site;
use Illuminate\Database\Eloquent\Builder;

/**
 * Caps concurrent Coolify builds per connection (or server) so shared VPS
 * memory is not starved by parallel compose builds.
 *
 * A bulk sweep is exempt from its own builds. The sweep already runs one site
 * at a time and hands each deploy to the Coolify queue, so counting the builds
 * it just queued against itself would refuse every site after the first and
 * report them as failures. The gate still refuses a deploy that would overlap
 * a build somebody else started — which is what it was written for.
 */
class CoolifyDeployGate
{
    private int $sweepDepth = 0;

    /**
     * Highest deployment id that existed when the current sweep opened. Rows
     * above it are the sweep's own queue, rows at or below it belong to
     * whoever held the host before the sweep started.
     */
    private ?int $sweepWatermark = null;

    /**
     * Run a serial bulk sweep that owns the host's deploy slot for its duration.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function duringSweep(callable $callback): mixed
    {
        if ($this->sweepDepth === 0) {
            $this->sweepWatermark = (int) Deployment::query()->max('id');
        }

        $this->sweepDepth++;

        try {
            return $callback();
        } finally {
            $this->sweepDepth--;

            if ($this->sweepDepth === 0) {
                $this->sweepWatermark = null;
            }
        }
    }

    public function assertCanStartDeploy(Site $site): void
    {
        $max = max(1, (int) config('ops.coolify.deploy.max_concurrent_per_server', 1));
        $inFlight = $this->blockingCount($site);

        if ($inFlight >= $max) {
            throw new CoolifyDeployBusyException(__('coolify.errors.deploy_busy', [
                'max' => $max,
                'count' => $inFlight,
            ]));
        }
    }

    public function unfinishedCount(Site $site): int
    {
        return $this->openDeployments($site)->count();
    }

    /**
     * Open builds this caller has to queue behind. Inside a sweep that is every
     * open build except the ones the sweep itself queued.
     */
    private function blockingCount(Site $site): int
    {
        $query = $this->openDeployments($site);

        if ($this->sweepWatermark !== null) {
            $query->where('id', '<=', $this->sweepWatermark);
        }

        return $query->count();
    }

    /**
     * @return Builder<Deployment>
     */
    private function openDeployments(Site $site): Builder
    {
        return Deployment::query()
            ->whereNull('finished_at')
            ->whereIn('site_id', $this->peerSites($site)->select('id'));
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
