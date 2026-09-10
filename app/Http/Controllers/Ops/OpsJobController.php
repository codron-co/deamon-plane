<?php

namespace App\Http\Controllers\Ops;

use App\Enums\DeploymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Deployment;
use App\Models\OpsBackgroundJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OpsJobController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('ops.write');

        $since = now()->subHours(2);

        $jobs = OpsBackgroundJob::query()
            ->where('actor_user_id', $request->user()?->id)
            ->where(function ($query) use ($since): void {
                $query->whereIn('status', ['queued', 'running'])
                    ->orWhere(function ($recent) use ($since): void {
                        $recent->whereIn('status', ['completed', 'failed'])
                            ->where('updated_at', '>=', $since);
                    });
            })
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->map(fn (OpsBackgroundJob $job): array => $job->toWidget())
            ->values();

        $deployments = Deployment::query()
            ->with('site')
            ->where(function ($query) use ($since): void {
                $query->whereIn('status', [
                    DeploymentStatus::Queued,
                    DeploymentStatus::InProgress,
                ])->orWhere(function ($recent) use ($since): void {
                    $recent->whereIn('status', [
                        DeploymentStatus::Failed,
                        DeploymentStatus::Finished,
                        DeploymentStatus::Cancelled,
                    ])->where('updated_at', '>=', $since);
                });
            })
            ->latest('id')
            ->limit(30)
            ->get()
            ->map(fn (Deployment $deployment): array => $deployment->toWidget())
            ->values();

        return response()->json([
            'jobs' => $jobs,
            'deployments' => $deployments,
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
