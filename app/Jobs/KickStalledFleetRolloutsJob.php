<?php

namespace App\Jobs;

use App\Models\FleetRollout;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Watchdog for the self-dispatching rollout chain: an open rollout that no tick
 * has touched for `ops.ci.stall_minutes` gets a new tick. Duplicate ticks are
 * dropped by the advance job's unique lock.
 */
class KickStalledFleetRolloutsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function handle(): void
    {
        $minutes = max(1, (int) config('ops.ci.stall_minutes', 5));

        FleetRollout::query()
            ->open()
            ->where('updated_at', '<', now()->subMinutes($minutes))
            ->pluck('id')
            ->each(fn (mixed $id) => AdvanceFleetRolloutJob::dispatch((int) $id));
    }
}
