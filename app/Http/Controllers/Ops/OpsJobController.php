<?php

namespace App\Http\Controllers\Ops;

use App\Http\Controllers\Controller;
use App\Models\OpsBackgroundJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OpsJobController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('ops.write');

        $jobs = OpsBackgroundJob::query()
            ->where('actor_user_id', $request->user()?->id)
            ->whereIn('status', ['queued', 'running'])
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->map(fn (OpsBackgroundJob $job): array => $job->toWidget())
            ->values();

        return response()->json([
            'jobs' => $jobs,
        ]);
    }

    public function show(Request $request, OpsBackgroundJob $job): JsonResponse
    {
        $this->authorize('ops.write');
        abort_unless($job->actor_user_id === $request->user()?->id, 404);

        return response()->json([
            'job' => $job->toWidget(),
        ]);
    }
}
