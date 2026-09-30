<?php

namespace App\Services\Coolify;

use App\Enums\DeploymentStatus;
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
        $max = $this->maxConcurrent();
        $inFlight = $this->blockingCount($site);

        if ($inFlight >= $max) {
            throw new CoolifyDeployBusyException(__('coolify.errors.deploy_busy', [
                'max' => $max,
                'count' => $inFlight,
            ]));
        }

        $loadPerCpu = $this->overloadedLoadPerCpu($site);
        if ($loadPerCpu !== null) {
            throw new CoolifyDeployBusyException(__('coolify.errors.deploy_host_overloaded', [
                'load' => number_format($loadPerCpu, 1),
                'max' => number_format($this->maxLoadPerCpu(), 1),
            ]));
        }
    }

    public function unfinishedCount(Site $site): int
    {
        return $this->openDeployments($site)->count();
    }

    public function maxConcurrent(): int
    {
        return max(1, (int) config('ops.coolify.deploy.max_concurrent_per_server', 2));
    }

    /**
     * Whether a new build may start on the site's host right now. Counts every
     * open build on the host, sweep or not: the Plane waiting line must never
     * start a row just because a sweep in this process looks past its own builds.
     */
    public function hasRoom(Site $site): bool
    {
        return $this->unfinishedCount($site) < $this->maxConcurrent()
            && $this->overloadedLoadPerCpu($site) === null;
    }

    /**
     * The host's 5 minute load per CPU from the freshest agent health poll of any
     * site on it (every CMS container sees the host's /proc/loadavg). Null when no
     * site on the host reported one recently (CMS before 1.2.63, agent down).
     */
    public function hostLoadPerCpu(Site $site): ?float
    {
        $maxAge = max(1, (int) config('ops.coolify.deploy.load_reading_max_age_minutes', 20));

        $peers = $this->peerSites($site)
            ->whereNotNull('last_health_at')
            ->where('last_health_at', '>=', now()->subMinutes($maxAge))
            ->orderByDesc('last_health_at')
            ->limit(10)
            ->get(['id', 'last_health_payload']);

        foreach ($peers as $peer) {
            $payload = is_array($peer->last_health_payload) ? $peer->last_health_payload : [];
            $load = $payload['host_load'] ?? null;
            $cpus = $payload['host_cpus'] ?? null;

            if (is_array($load) && isset($load[1]) && is_numeric($load[1]) && is_int($cpus) && $cpus > 0) {
                return (float) $load[1] / $cpus;
            }
        }

        return null;
    }

    public function maxLoadPerCpu(): float
    {
        return max(0.0, (float) config('ops.coolify.deploy.max_load_per_cpu', 6));
    }

    /**
     * Load per CPU when it is at or above the cap, else null. Unknown load never
     * blocks: an old CMS or a silent agent must not freeze every deploy.
     */
    private function overloadedLoadPerCpu(Site $site): ?float
    {
        $max = $this->maxLoadPerCpu();
        if ($max <= 0.0) {
            return null;
        }

        $loadPerCpu = $this->hostLoadPerCpu($site);

        return $loadPerCpu !== null && $loadPerCpu >= $max ? $loadPerCpu : null;
    }

    /**
     * The host a site's builds count against: connection first, else server
     * uuid, else the site alone. `OpsCoolifyDeployQueue::queueStanding()` and the
     * Plane waiting line group by the same key.
     */
    public static function hostKey(Site $site): string
    {
        return self::hostKeyFor($site->coolify_connection_id, $site->coolify_server_uuid, (string) $site->getKey());
    }

    public static function hostKeyFor(mixed $connectionId, mixed $serverUuid, string $siteId): string
    {
        if ($connectionId !== null && $connectionId !== '') {
            return 'conn:'.$connectionId;
        }

        $serverUuid = trim((string) $serverUuid);
        if ($serverUuid !== '') {
            return 'server:'.$serverUuid;
        }

        return 'site:'.$siteId;
    }

    /**
     * Sites that share the site's Coolify host (the site itself included).
     *
     * @return Builder<Site>
     */
    public function peersOf(Site $site): Builder
    {
        return $this->peerSites($site);
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
        // A failed or cancelled row is closed even when its finished_at was never
        // written; only queued / in-progress rows hold the host.
        return Deployment::query()
            ->whereNull('finished_at')
            ->whereIn('status', [DeploymentStatus::Queued->value, DeploymentStatus::InProgress->value])
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
