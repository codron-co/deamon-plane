<?php

namespace App\Jobs;

use App\Enums\OpsLane;
use App\Jobs\Concerns\OnOpsLane;
use App\Models\FleetRollout;
use App\Services\Rollouts\FleetRolloutService;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * One tick of a CI-gated fleet rollout. While a stage waits (canary builds,
 * canary health, a busy Coolify host, the next fan-out batch) the job queues
 * itself again with a delay. Unique until it starts processing, so the
 * self-dispatch at the end of a tick is not swallowed by its own lock, and two
 * ticks of one rollout never overlap. KickStalledFleetRolloutsJob restarts a
 * chain that was lost (worker restart, Plane deploy).
 */
class AdvanceFleetRolloutJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use OnOpsLane;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public int $uniqueFor = 900;

    public function __construct(
        public readonly int $rolloutId,
    ) {
        $this->onLane(OpsLane::Long);
    }

    public function uniqueId(): string
    {
        return (string) $this->rolloutId;
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('fleet-rollout-'.$this->rolloutId))->dontRelease()->expireAfter(900),
        ];
    }

    public function handle(FleetRolloutService $service): void
    {
        $rollout = FleetRollout::query()->find($this->rolloutId);
        if ($rollout === null) {
            return;
        }

        $delay = $service->advance($rollout);
        if ($delay !== null) {
            self::dispatch($this->rolloutId)->delay(now()->addSeconds(max(1, $delay)));
        }
    }
}
