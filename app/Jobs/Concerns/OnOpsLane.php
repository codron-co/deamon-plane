<?php

namespace App\Jobs\Concerns;

use App\Enums\OpsLane;

/**
 * Puts a job on its lane. The long lane uses its own queue connection in
 * production so its retry_after outlasts the job (config/queue.php lanes).
 */
trait OnOpsLane
{
    protected function onLane(OpsLane $lane): void
    {
        $this->onQueue($lane->value);

        $connection = config('queue.lanes.'.$lane->value.'.connection');
        if (is_string($connection) && $connection !== '') {
            $this->onConnection($connection);
        }
    }
}
