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
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class OpsJobController extends Controller
{
    private const STALE_POLL_SECONDS = 20;

    public function index(Request $request, OpsCoolifyDeployQueue $queue): JsonResponse
    {
        $this->authorize('ops.write');

        // Only `failed=1` is the failed-only lane. `true` / `yes` / junk widen,
        // matching the Sites filters: a typo must not empty the widget.
        $failedOnly = $request->query('failed') === '1';
        $since = now()->subHours(2);

        $jobs = $this->widgetJobs($request, $since, $failedOnly);
        $deploymentModels = $this->widgetDeployments($since, $failedOnly);

        if (! $failedOnly) {
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
        }

        // Read the queue once for the whole payload: every row's position and the
        // header summary must agree, and a per-row query would scale with the queue.
        $standing = $queue->queueStanding();

        $local = $deploymentModels
            ->map(fn (Deployment $deployment): array => $queue->applyQueueStanding($deployment->toWidget(), $standing))
            ->keyBy(static fn (array $row): string => (string) ($row['coolify_deployment_uuid'] ?? $row['id']));

        // Failed-only is triage of rows Plane already has. Merging the live
        // Coolify queue would hide failures behind running builds and spend
        // a GET /deployments per connection — the pressure P0-4 exists to cut.
        if (! $failedOnly) {
            foreach ($queue->widgetRows() as $row) {
                $key = (string) ($row['coolify_deployment_uuid'] ?? $row['id']);
                if ($key === '' || $local->has($key)) {
                    continue;
                }
                $local->put($key, $queue->applyQueueStanding($row, $standing));
            }
        }

        $deployments = $local->values();

        return response()->json([
            'jobs' => $jobs,
            'deployments' => $deployments,
            'queue' => [
                'running' => $standing['running'],
                'queued' => $standing['queued'],
                'label' => $queue->queueSummaryLabel($standing),
            ],
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

        return response()->json(
            $this->deploymentResponse($queue, $queue->cancel($deployment)),
        );
    }

    public function forceStartDeployment(Request $request, Deployment $deployment, OpsCoolifyDeployQueue $queue): JsonResponse
    {
        $this->authorize('ops.write');

        return response()->json(
            $this->deploymentResponse($queue, $queue->forceStart($deployment, $request->user()), __('ops.jobs.force_started')),
        );
    }

    public function cancelCoolifyDeployment(Request $request, string $uuid, OpsCoolifyDeployQueue $queue): JsonResponse
    {
        $this->authorize('ops.write');

        return response()->json(
            $this->deploymentResponse($queue, $queue->cancelRemote($uuid)),
        );
    }

    public function forceStartCoolifyDeployment(Request $request, string $uuid, OpsCoolifyDeployQueue $queue): JsonResponse
    {
        $this->authorize('ops.write');

        return response()->json(
            $this->deploymentResponse($queue, $queue->forceStartRemote($uuid, $request->user()), __('ops.jobs.force_started')),
        );
    }

    /**
     * Cancel and force start both reorder the line, so their response carries the
     * recomputed standing instead of leaving the row stale until the next poll.
     *
     * @return array<string, mixed>
     */
    private function deploymentResponse(OpsCoolifyDeployQueue $queue, ?Deployment $deployment, ?string $message = null): array
    {
        $standing = $queue->queueStanding();

        $payload = ['ok' => true];
        if ($message !== null) {
            $payload['message'] = $message;
        }

        $payload['deployment'] = $deployment === null
            ? null
            : $queue->applyQueueStanding($deployment->toWidget(), $standing);

        $payload['queue'] = [
            'running' => $standing['running'],
            'queued' => $standing['queued'],
            'label' => $queue->queueSummaryLabel($standing),
        ];

        return $payload;
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function widgetJobs(Request $request, Carbon $since, bool $failedOnly): Collection
    {
        $query = OpsBackgroundJob::query()
            ->where('actor_user_id', $request->user()?->id);

        if ($failedOnly) {
            $query->where('status', 'failed')->where('updated_at', '>=', $since);
        } else {
            $query->where(function ($inner) use ($since): void {
                $inner->whereIn('status', ['queued', 'running'])
                    ->orWhere(function ($recent) use ($since): void {
                        $recent->whereIn('status', ['completed', 'failed'])
                            ->where('updated_at', '>=', $since);
                    });
            });
        }

        return $query
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->map(fn (OpsBackgroundJob $job): array => $job->toWidget())
            ->values();
    }

    /**
     * @return Collection<int, Deployment>
     */
    private function widgetDeployments(Carbon $since, bool $failedOnly): Collection
    {
        $query = Deployment::query()->with('site');

        if ($failedOnly) {
            $query->where('status', DeploymentStatus::Failed)->where('updated_at', '>=', $since);
        } else {
            $query->where(function ($inner) use ($since): void {
                $inner->whereIn('status', [
                    DeploymentStatus::Queued,
                    DeploymentStatus::InProgress,
                ])->orWhere(function ($recent) use ($since): void {
                    $recent->whereIn('status', [
                        DeploymentStatus::Failed,
                        DeploymentStatus::Finished,
                        DeploymentStatus::Cancelled,
                    ])->where('updated_at', '>=', $since);
                });
            });
        }

        return $query->latest('id')->limit(30)->get();
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
