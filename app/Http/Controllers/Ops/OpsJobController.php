<?php

namespace App\Http\Controllers\Ops;

use App\Enums\DeploymentStatus;
use App\Http\Controllers\Controller;
use App\Jobs\PollDeploymentJob;
use App\Models\Deployment;
use App\Models\OpsBackgroundJob;
use App\Services\Ops\OpsCoolifyDeployQueue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class OpsJobController extends Controller
{
    private const STALE_POLL_SECONDS = 20;

    public function index(Request $request, OpsCoolifyDeployQueue $queue): JsonResponse
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

        $queue->refreshOpen(
            $deploymentModels->filter(
                static fn (Deployment $deployment): bool => in_array(
                    $deployment->status,
                    [DeploymentStatus::Queued, DeploymentStatus::InProgress],
                    true,
                ),
            )->values(),
        );

        $deploymentModels = $deploymentModels->map(
            static fn (Deployment $deployment): Deployment => $deployment->fresh(['site']) ?? $deployment,
        );

        $this->kickStaleDeploymentPolls($deploymentModels);

        $local = $deploymentModels
            ->map(fn (Deployment $deployment): array => $deployment->toWidget())
            ->keyBy(static fn (array $row): string => (string) ($row['coolify_deployment_uuid'] ?? $row['id']));

        foreach ($queue->widgetRows() as $row) {
            $key = (string) ($row['coolify_deployment_uuid'] ?? $row['id']);
            if ($key === '' || $local->has($key)) {
                continue;
            }
            $local->put($key, $row);
        }

        $deployments = $local->values();

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

    public function destroy(Request $request, OpsBackgroundJob $job): JsonResponse
    {
        $this->authorize('ops.write');
        abort_unless($job->actor_user_id === $request->user()?->id, 404);
        abort_unless(in_array($job->status, ['completed', 'failed', 'cancelled'], true), 422);

        $job->delete();

        return response()->json(['ok' => true]);
    }

    public function cancelDeployment(Request $request, Deployment $deployment, OpsCoolifyDeployQueue $queue): JsonResponse
    {
        $this->authorize('ops.write');

        $updated = $queue->cancel($deployment);

        return response()->json([
            'ok' => true,
            'deployment' => $updated->toWidget(),
        ]);
    }

    public function forceStartDeployment(Request $request, Deployment $deployment, OpsCoolifyDeployQueue $queue): JsonResponse
    {
        $this->authorize('ops.write');

        $updated = $queue->forceStart($deployment);

        return response()->json([
            'ok' => true,
            'deployment' => $updated->toWidget(),
        ]);
    }

    public function cancelCoolifyDeployment(Request $request, string $uuid, OpsCoolifyDeployQueue $queue): JsonResponse
    {
        $this->authorize('ops.write');

        $updated = $queue->cancelRemote($uuid);

        return response()->json([
            'ok' => true,
            'deployment' => $updated?->toWidget(),
        ]);
    }

    public function forceStartCoolifyDeployment(Request $request, string $uuid, OpsCoolifyDeployQueue $queue): JsonResponse
    {
        $this->authorize('ops.write');

        $updated = $queue->forceStartRemote($uuid);

        return response()->json([
            'ok' => true,
            'deployment' => $updated->toWidget(),
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
