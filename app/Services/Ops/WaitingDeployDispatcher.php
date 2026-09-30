<?php

namespace App\Services\Ops;

use App\Enums\DeploymentStatus;
use App\Enums\SiteStatus;
use App\Enums\WaitingDeployAction;
use App\Models\Deployment;
use App\Models\Site;
use App\Models\User;
use App\Services\Coolify\CoolifyDeployGate;
use App\Services\Sites\ChannelSwitcher;
use App\Services\Sites\SiteProvisioner;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Starts Plane `waiting` deployments, oldest first per Coolify host, while the
 * host is under `max_concurrent_per_server`.
 *
 * Idempotent and safe to run from anywhere: each host is drained under the
 * same lock `WaitingDeployQueue::request()` takes, so two ticks (or a tick and
 * an operator click) never start the same row or overfill a host. A host whose
 * lock is busy is skipped; the next tick picks it up.
 *
 * Head-of-line rule: when the oldest runnable row of a host cannot start (cap
 * full, transient Coolify error), nothing behind it on that host starts either.
 */
class WaitingDeployDispatcher
{
    public function __construct(
        private readonly WaitingDeployQueue $queue,
        private readonly CoolifyDeployGate $gate,
    ) {}

    /**
     * @return array{started: int, failed: int, cancelled: int, waiting: int}
     */
    public function tick(): array
    {
        $totals = ['started' => 0, 'failed' => 0, 'cancelled' => 0, 'waiting' => 0];

        foreach ($this->hosts() as $hostKey) {
            $result = $this->queue->withHostLock($hostKey, fn (): array => $this->drain($hostKey));
            if ($result === null) {
                continue;
            }

            foreach ($result as $key => $count) {
                $totals[$key] += $count;
            }
        }

        $totals['waiting'] = Deployment::query()->waiting()->count();

        if ($totals['started'] + $totals['failed'] + $totals['cancelled'] > 0) {
            Log::info('ops.deploy_queue.tick', $totals);
        }

        return $totals;
    }

    /**
     * Host keys that have waiting rows, in the order their oldest row queued.
     *
     * @return list<string>
     */
    private function hosts(): array
    {
        $hosts = [];
        foreach ($this->waitingRows() as $row) {
            $hosts[$this->hostKeyOf($row)] = true;
        }

        return array_keys($hosts);
    }

    /**
     * @return array{started: int, failed: int, cancelled: int}
     */
    private function drain(string $hostKey): array
    {
        $counts = ['started' => 0, 'failed' => 0, 'cancelled' => 0];

        $rows = array_values(array_filter(
            $this->waitingRows(),
            fn (Deployment $row): bool => $this->hostKeyOf($row) === $hostKey,
        ));

        foreach ($rows as $row) {
            $row->refresh();
            if ($row->status !== DeploymentStatus::Waiting) {
                continue;
            }

            $site = Site::withTrashed()->find($row->site_id);
            if (! $site instanceof Site || $site->trashed() || blank($site->coolify_app_uuid)) {
                $this->queue->close($row, DeploymentStatus::Cancelled, __('site_ops.queue.site_gone'));
                $this->queue->audit($row, null, null, 'site.deploy_queue_cancelled', ['reason' => 'site_gone']);
                $counts['cancelled']++;

                continue;
            }
            $row->setRelation('site', $site);

            if ($this->expired($row)) {
                $this->fail($row, $site, __('site_ops.queue.expired', ['minutes' => $this->maxMinutes()]), 'expired');
                $counts['failed']++;

                continue;
            }

            $stale = $this->staleReason($row, $site);
            if ($stale !== null) {
                $this->queue->close($row, DeploymentStatus::Cancelled, __('site_ops.queue.'.$stale));
                $this->queue->releaseSite($row, null, null);
                $this->queue->audit($row, null, null, 'site.deploy_queue_cancelled', ['reason' => $stale]);
                $counts['cancelled']++;

                continue;
            }

            // FIFO: the head of the host line waits for a slot, and so does
            // everyone behind it.
            if (! $this->gate->hasRoom($site)) {
                break;
            }

            $outcome = $this->startRow($row, $site);
            if ($outcome === 'blocked') {
                break;
            }

            $counts[$outcome]++;
        }

        return $counts;
    }

    /**
     * @return 'started'|'failed'|'blocked'
     */
    private function startRow(Deployment $row, Site $site): string
    {
        $row->forceFill(['queue_attempts' => $row->queue_attempts + 1])->save();

        $actor = $row->requested_by !== null ? User::query()->find($row->requested_by) : null;
        $payload = (array) $row->queue_payload;
        $ip = isset($payload['ip']) && is_string($payload['ip']) ? $payload['ip'] : null;
        $action = $row->queue_action;

        if (! $action instanceof WaitingDeployAction) {
            $this->fail($row, $site, __('site_ops.queue.start_failed', ['error' => 'unknown action']), 'unknown_action');

            return 'failed';
        }

        try {
            $this->queue->start($site, $action, $payload, $actor, $ip, $row);
        } catch (Throwable $exception) {
            $row->refresh();

            if (WaitingDeployQueue::isDeployBusy($exception)) {
                // A build started elsewhere between our count and the POST.
                return $this->keepWaiting($row, $site, $exception);
            }

            if (WaitingDeployQueue::isTransient($exception) && $row->queue_attempts < $this->maxAttempts()) {
                return $this->keepWaiting($row, $site, $exception);
            }

            $this->fail($row, $site, $this->failureText($row, $site, $exception), 'start_failed');

            return 'failed';
        }

        $row->refresh();
        $this->queue->audit($row, null, null, 'site.deploy_queue_started', [
            'attempt' => $row->queue_attempts,
            'status' => $row->status->value,
            'coolify_deployment_uuid' => $row->coolify_deployment_uuid,
        ]);

        return 'started';
    }

    /**
     * @return 'blocked'|'failed'
     */
    private function keepWaiting(Deployment $row, Site $site, Throwable $exception): string
    {
        if ($row->status !== DeploymentStatus::Waiting) {
            // The action already wrote an outcome on the row (a lifecycle
            // service failing it); nothing left to wait for.
            return 'failed';
        }

        Log::info('ops.deploy_queue.start_deferred', [
            'deployment_id' => $row->id,
            'site_id' => $site->id,
            'attempt' => $row->queue_attempts,
            'error' => $exception->getMessage(),
        ]);

        return 'blocked';
    }

    /**
     * The row will never build. App actions just close the row; a channel switch
     * or provision fails through its own service so the site lands in error with
     * the same audit and alert a direct failure would raise.
     */
    private function fail(Deployment $row, Site $site, string $reason, string $code): void
    {
        $actorId = $row->requested_by;
        $payload = (array) $row->queue_payload;
        $ip = isset($payload['ip']) && is_string($payload['ip']) ? $payload['ip'] : null;

        match ($row->queue_action) {
            WaitingDeployAction::ChannelSwitch => $site->status === SiteStatus::Deploying
                ? app(ChannelSwitcher::class)->markFailed($site, $reason, $row, $actorId, $ip)
                : $this->queue->close($row, DeploymentStatus::Failed, $reason),
            WaitingDeployAction::Provision => $site->status === SiteStatus::Provisioning
                ? app(SiteProvisioner::class)->markFailed($site, $reason, $row, $actorId, $ip)
                : $this->queue->close($row, DeploymentStatus::Failed, $reason),
            default => $row->status === DeploymentStatus::Waiting
                ? $this->queue->close($row, DeploymentStatus::Failed, $reason)
                : null,
        };

        $this->queue->audit($row, null, null, 'site.deploy_queue_failed', [
            'reason' => $code,
            'error' => $reason,
            'attempts' => $row->queue_attempts,
        ]);
    }

    private function failureText(Deployment $row, Site $site, Throwable $exception): string
    {
        $message = match ($row->queue_action) {
            WaitingDeployAction::ChannelSwitch => app(ChannelSwitcher::class)->safeFailureMessage($site, $exception),
            WaitingDeployAction::Provision => app(SiteProvisioner::class)->safeFailureMessage($site, $exception),
            default => trim($exception->getMessage()),
        };

        return __('site_ops.queue.start_failed', ['error' => $message !== '' ? $message : class_basename($exception)]);
    }

    private function expired(Deployment $row): bool
    {
        $queuedAt = $row->queued_at ?? $row->created_at;

        return $queuedAt !== null && $queuedAt->lt(now()->subMinutes($this->maxMinutes()));
    }

    /**
     * Why a waiting row no longer needs to run, or null when it does.
     *
     * - A channel switch whose site left `deploying` (someone else resolved it)
     *   or a provision whose site left `provisioning` has nothing to start.
     * - A redeploy / update-to-HEAD is satisfied by any build of the site that
     *   was requested after it and finished.
     * - Follow-HEAD additionally needs the app already unpinned with the
     *   auto-deploy state it would set; a pin needs that commit pinned.
     */
    private function staleReason(Deployment $row, Site $site): ?string
    {
        $action = $row->queue_action;

        if ($action === WaitingDeployAction::ChannelSwitch && ($site->status !== SiteStatus::Deploying || $site->desired_channel === null)) {
            return 'stale';
        }

        if ($action === WaitingDeployAction::Provision && $site->status !== SiteStatus::Provisioning) {
            return 'stale';
        }

        if (! $action instanceof WaitingDeployAction || ! $action->isAppAction()) {
            return null;
        }

        $queuedAt = $row->queued_at ?? $row->created_at;
        $later = $site->deployments()
            ->whereKeyNot($row->id)
            ->where('status', DeploymentStatus::Finished)
            ->where('created_at', '>', $queuedAt);

        $payload = (array) $row->queue_payload;

        $satisfied = match ($action) {
            WaitingDeployAction::Redeploy, WaitingDeployAction::UpdateHead => $later->exists()
                && ($action === WaitingDeployAction::Redeploy || $site->coolify_pinned_sha === null),
            WaitingDeployAction::FollowHead => $later->exists()
                && $site->coolify_pinned_sha === null
                && $site->coolify_auto_deploy === ! $site->usesCiGate(),
            WaitingDeployAction::Pin => $this->pinSatisfied($site, (string) ($payload['ref'] ?? ''), $later),
            default => false,
        };

        return $satisfied ? 'already_deployed' : null;
    }

    /**
     * @param  HasMany<Deployment, Site>  $later
     */
    private function pinSatisfied(Site $site, string $ref, $later): bool
    {
        $ref = trim($ref);
        $pinned = trim((string) $site->coolify_pinned_sha);
        if ($ref === '' || $pinned === '' || ! str_starts_with($pinned, $ref)) {
            return false;
        }

        return $later->where('commit_sha', 'like', $ref.'%')->exists();
    }

    /**
     * @return list<Deployment>
     */
    private function waitingRows(): array
    {
        return Deployment::query()
            ->waiting()
            // A soft-deleted site still owns its waiting rows: they are closed, not skipped.
            ->with(['site' => static fn ($query) => $query->withTrashed()])
            ->orderBy('queued_at')
            ->orderBy('id')
            ->get()
            ->all();
    }

    private function hostKeyOf(Deployment $row): string
    {
        $site = $row->site;
        if (! $site instanceof Site) {
            return 'site:'.$row->site_id;
        }

        return CoolifyDeployGate::hostKey($site);
    }

    private function maxMinutes(): int
    {
        return max(1, (int) config('ops.coolify.deploy.waiting_max_minutes', 120));
    }

    private function maxAttempts(): int
    {
        return max(1, (int) config('ops.coolify.deploy.waiting_max_attempts', 5));
    }
}
