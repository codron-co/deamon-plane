<?php

namespace App\Jobs;

use App\Enums\OpsLane;
use App\Jobs\Concerns\OnOpsLane;
use App\Models\OpsBackgroundJob;
use App\Services\Ops\OpsJobRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

class ProcessOpsBackgroundJob implements ShouldQueue
{
    use OnOpsLane;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public readonly string $jobId)
    {
        $this->onLane(OpsLane::Long);
    }

    /**
     * Job types that fan out over Coolify. Only one runs at a time so two bulk
     * sweeps cannot stack their request rate and trip Coolify's throttle.
     *
     * @var list<string>
     */
    private const COOLIFY_TYPES = [
        'sites.coolify_sync',
        'coolify.inventory_sync',
        'sites.bulk_channel',
        'sites.bulk_compose',
        'sites.bulk_auto_deploy',
        'sites.bulk_deploy_gate',
        'sites.bulk_deploy',
        'sites.bulk_follow_head',
        'sites.bulk_update_head',
        'sites.bulk_pin',
        'sites.bulk_app_health_fix',
        'sites.bulk_inject_agent_secret',
        'sites.bulk_purge',
    ];

    public function handle(OpsJobRunner $runner): void
    {
        $job = OpsBackgroundJob::query()->find($this->jobId);
        if ($job === null || $job->status !== 'queued') {
            return;
        }

        if (! in_array($job->type, self::COOLIFY_TYPES, true)) {
            $this->execute($job, $runner);

            return;
        }

        $lock = Cache::lock('ops:coolify-bulk', max(60, (int) config('ops.coolify.bulk.lock_seconds', 900)));

        if (! $lock->get()) {
            // Another sweep owns Coolify right now. Stay queued and come back.
            self::dispatch($this->jobId)
                ->delay(now()->addSeconds(max(1, (int) config('ops.coolify.bulk.requeue_seconds', 20))));

            return;
        }

        try {
            $this->execute($job, $runner);
        } finally {
            $lock->release();
        }
    }

    private function execute(OpsBackgroundJob $job, OpsJobRunner $runner): void
    {
        $job->markRunning();

        try {
            $message = $runner->run($job);
            $fresh = $job->fresh() ?? $job;
            if ($fresh->status === 'failed') {
                return;
            }
            $fresh->markCompleted($message, $fresh->result);
        } catch (Throwable $exception) {
            report($exception);
            $job->fresh()?->markFailed($exception->getMessage());
        }
    }

    /**
     * Timeout or worker restart: neither the catch nor the finally above ran.
     * Close the row and free the Coolify sweep lock this job was holding, so
     * the next sweep does not wait out the lock's 15 minutes.
     */
    public function failed(?Throwable $exception): void
    {
        $job = OpsBackgroundJob::query()->find($this->jobId);
        if ($job === null || ! in_array($job->status, ['queued', 'running'], true)) {
            return;
        }

        $wasRunning = $job->status === 'running';
        $job->markFailed(__('ops.jobs.stopped'));

        if ($wasRunning && in_array($job->type, self::COOLIFY_TYPES, true)) {
            Cache::lock('ops:coolify-bulk')->forceRelease();
        }
    }
}
