<?php

namespace App\Http\Controllers\Ops;

use App\Enums\DeploymentStatus;
use App\Http\Controllers\Controller;
use App\Jobs\PollDeploymentJob;
use App\Models\Deployment;
use App\Models\OpsBackgroundJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class OpsJobController extends Controller
{
    private const STALE_POLL_SECONDS = 20;

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

        $deploymentModels = Deployment::query()
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
            ->get();

        $this->kickStaleDeploymentPolls($deploymentModels);

        $deployments = $deploymentModels
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

    /**
     * @param  Collection<int, Deployment>  $deployments
     */
    private function kickStaleDeploymentPolls(Collection $deployments): void
    {
        $cutoff = now()->subSeconds(self::STALE_POLL_SECONDS);

        foreach ($deployments as $deployment) {
            if (! in_array($deployment->status, [DeploymentStatus::Queued, DeploymentStatus::InProgress], true)) {
                continue;
            }

            if (blank($deployment->coolify_deployment_uuid)) {
                continue;
            }

            $started = $deployment->started_at ?? $deployment->created_at;
            if ($started !== null && $started->gt($cutoff)) {
                continue;
            }

            $key = 'ops.deploy.poll.'.$deployment->id;
            if (! Cache::add($key, 1, now()->addSeconds(self::STALE_POLL_SECONDS))) {
                continue;
            }

            PollDeploymentJob::dispatch($deployment->id, $deployment->requested_by, null);
        }
    }
}
