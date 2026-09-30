<?php

namespace App\Jobs;

use App\Enums\OpsLane;
use App\Jobs\Concerns\OnOpsLane;
use App\Services\Ops\WaitingDeployDispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * One pass of the Plane deploy line: start `waiting` deployments whose Coolify
 * host has a free build slot. Dispatched when a build ends (Deployment saved
 * hook) and every minute from the scheduler as the safety net. Running it twice
 * is harmless — each host is drained under a lock.
 */
class StartWaitingDeploysJob implements ShouldQueue
{
    use OnOpsLane;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 170;

    public function __construct()
    {
        $this->onLane(OpsLane::Critical);
    }

    public function handle(WaitingDeployDispatcher $dispatcher): void
    {
        $dispatcher->tick();
    }
}
