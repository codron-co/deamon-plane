<?php

namespace App\Http\Controllers\Ops\Concerns;

use App\Services\Ops\OpsJobManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

trait QueuesOpsJob
{
    /**
     * @param  array<string, mixed>  $payload
     */
    protected function queueOpsJob(Request $request, string $type, string $title, array $payload = []): JsonResponse
    {
        $job = app(OpsJobManager::class)->queue($type, $title, $request->user(), $payload);

        return response()->json([
            'ok' => true,
            'job' => $job->toWidget(),
        ]);
    }
}
