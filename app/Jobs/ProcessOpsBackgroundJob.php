<?php

namespace App\Jobs;

use App\Models\OpsBackgroundJob;
use App\Services\Ops\OpsJobRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessOpsBackgroundJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public readonly string $jobId) {}

    public function handle(OpsJobRunner $runner): void
    {
        $job = OpsBackgroundJob::query()->find($this->jobId);
        if ($job === null || $job->status !== 'queued') {
            return;
        }

        $job->markRunning();

        try {
            $message = $runner->run($job);
            $fresh = $job->fresh() ?? $job;
            if ($fresh->status === 'failed') {
                return;
            }
            $fresh->markCompleted($message, $fresh->result);
        } catch (Throwable $exception) {
            $job->fresh()?->markFailed($exception->getMessage());
        }
    }
}
