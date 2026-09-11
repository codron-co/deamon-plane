<?php

namespace Tests\Feature\Ops;

use App\Jobs\ProcessOpsBackgroundJob;
use App\Models\OpsBackgroundJob;
use App\Services\Ops\OpsJobRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Two bulk sweeps must not stack their Coolify request rate.
 */
class OpsBulkSerializationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_coolify_bulk_job_waits_while_another_sweep_holds_the_lock(): void
    {
        Queue::fake();
        $job = $this->job('sites.bulk_pin');

        $lock = Cache::lock('ops:coolify-bulk', 60);
        $this->assertTrue($lock->get());

        try {
            (new ProcessOpsBackgroundJob($job->id))->handle(app(OpsJobRunner::class));
        } finally {
            $lock->release();
        }

        $this->assertSame('queued', $job->refresh()->status);
        Queue::assertPushed(ProcessOpsBackgroundJob::class);
    }

    public function test_a_non_coolify_job_ignores_the_lock(): void
    {
        Queue::fake();
        $job = $this->job('sites.live_sync');

        $lock = Cache::lock('ops:coolify-bulk', 60);
        $this->assertTrue($lock->get());

        try {
            (new ProcessOpsBackgroundJob($job->id))->handle(app(OpsJobRunner::class));
        } finally {
            $lock->release();
        }

        $this->assertNotSame('queued', $job->refresh()->status);
        Queue::assertNotPushed(ProcessOpsBackgroundJob::class);
    }

    public function test_the_lock_is_released_after_a_sweep_finishes(): void
    {
        Queue::fake();
        $job = $this->job('sites.coolify_sync');

        (new ProcessOpsBackgroundJob($job->id))->handle(app(OpsJobRunner::class));

        $lock = Cache::lock('ops:coolify-bulk', 60);
        $this->assertTrue($lock->get(), 'The bulk lock should be free once the sweep ends.');
        $lock->release();
    }

    private function job(string $type): OpsBackgroundJob
    {
        return OpsBackgroundJob::query()->create([
            'type' => $type,
            'title' => $type,
            'status' => 'queued',
            'progress' => 0,
            'payload' => ['site_ids' => []],
        ]);
    }
}
