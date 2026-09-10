<?php

namespace App\Services\Ops;

use App\Jobs\ProcessOpsBackgroundJob;
use App\Models\OpsBackgroundJob;
use App\Models\User;

class OpsJobManager
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function queue(string $type, string $title, ?User $actor, array $payload = []): OpsBackgroundJob
    {
        $job = OpsBackgroundJob::query()->create([
            'type' => $type,
            'title' => $title,
            'status' => 'queued',
            'progress' => 0,
            'payload' => $payload,
            'actor_user_id' => $actor?->id,
        ]);

        ProcessOpsBackgroundJob::dispatch($job->id)->afterResponse();

        return $job->fresh() ?? $job;
    }
}
