<?php

namespace App\Services\Ops;

use App\Enums\Channel;
use App\Enums\DeploymentStatus;
use App\Enums\SiteStatus;
use App\Enums\WaitingDeployAction;
use App\Jobs\StartWaitingDeploysJob;
use App\Models\Deployment;
use App\Models\Site;
use App\Models\User;
use App\Services\Coolify\CoolifyApiException;
use App\Services\Coolify\CoolifyDeployBusyException;
use App\Services\Coolify\CoolifyDeployGate;
use App\Services\Sites\ChannelSwitcher;
use App\Services\Sites\CoolifyDeploySettings;
use App\Services\Sites\SiteProvisioner;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

/**
 * The Plane-side deploy line. A single-site deploy that finds its Coolify host
 * at `ops.coolify.deploy.max_concurrent_per_server` is recorded as a `waiting`
 * deployment instead of being refused; `WaitingDeployDispatcher` starts those
 * rows FIFO per host once a slot frees.
 *
 * Every deploy decision for one host (start now, or wait) happens under the
 * same host lock the dispatcher takes, so an operator click and a dispatcher
 * tick can never both see "one slot free" and start two builds into it.
 *
 * Bulk sweeps (`PacedFanout`) never come through here: they keep their own
 * pacing and `sıra bekliyor` bucket, so nothing is queued twice.
 */
class WaitingDeployQueue
{
    public const LOCK_SECONDS = 180;

    /**
     * Locks this process already holds, by key. A deploy started under the host
     * lock can reach `request()` again in-process (a sync poll finishing a
     * provision asks for the domain-bind redeploy); that must not wait on itself.
     *
     * @var array<string, int>
     */
    private static array $held = [];

    public function __construct(
        private readonly CoolifyDeployGate $gate,
    ) {}

    /**
     * Start the deploy now when the host has room and nobody is waiting ahead
     * of it; otherwise put it in the Plane line. Errors other than the build
     * cap propagate exactly as the direct call would raise them.
     *
     * @param  array<string, mixed>  $payload
     */
    public function request(
        Site $site,
        WaitingDeployAction $action,
        array $payload = [],
        ?User $actor = null,
        ?string $ip = null,
    ): DeployRequestOutcome {
        $payload = $this->normalizePayload($action, $payload);
        $release = $this->acquire(self::lockKey($site), $this->requestLockWait());
        $outcome = null;

        try {
            if ($release !== null && ! $this->hasWaitingOnHost($site) && $this->gate->hasRoom($site)) {
                try {
                    $outcome = DeployRequestOutcome::started($this->start($site, $action, $payload, $actor, $ip));
                } catch (Throwable $exception) {
                    // A build slipped in between the count and the POST (a sweep
                    // in another worker): wait in line instead of refusing.
                    if (! self::isDeployBusy($exception)) {
                        throw $exception;
                    }
                }
            }

            $outcome ??= $this->enqueue($site, $action, $payload, $actor, $ip);
        } finally {
            if ($release !== null) {
                $release();
            }
        }

        // Queued with a free slot (lock contention, or rows ahead of it that
        // the dispatcher has not reached yet): nudge the line now instead of
        // waiting for the minute tick.
        if ($outcome->queued && $this->gate->hasRoom($site)) {
            StartWaitingDeploysJob::dispatch();
        }

        return $outcome;
    }

    /**
     * Run the action itself. `$into` is the waiting row being started; without
     * it the action creates its own deployment row, which is returned.
     *
     * @param  array<string, mixed>  $payload
     */
    public function start(
        Site $site,
        WaitingDeployAction $action,
        array $payload,
        ?User $actor,
        ?string $ip,
        ?Deployment $into = null,
    ): Deployment {
        $before = (int) $site->deployments()->max('id');
        $settings = app(CoolifyDeploySettings::class);

        match ($action) {
            WaitingDeployAction::Redeploy => $settings->redeploy($site, $actor, $ip, (bool) ($payload['force'] ?? true), $into),
            WaitingDeployAction::UpdateHead => $settings->updateToHead($site, $actor, $ip, $into),
            WaitingDeployAction::FollowHead => $settings->followHead($site, $actor, $ip, $into),
            WaitingDeployAction::Pin => $settings->pin($site, (string) ($payload['ref'] ?? ''), $actor, $ip, $into),
            WaitingDeployAction::ChannelSwitch => app(ChannelSwitcher::class)->switchOnCoolify($site, $actor?->id, $ip, $into),
            WaitingDeployAction::Provision => app(SiteProvisioner::class)->startProvisionDeploy($site, $actor?->id, $ip, $into),
        };

        if ($into instanceof Deployment) {
            return $into->refresh();
        }

        $deployment = $site->deployments()->where('id', '>', $before)->latest('id')->first();
        if (! $deployment instanceof Deployment) {
            throw new RuntimeException('Deploy started but no deployment row was recorded.');
        }

        return $deployment;
    }

    /**
     * Operator cancel of a waiting row. Nothing reached Coolify, so this is
     * local; a waiting channel switch / provision also releases the site.
     */
    public function cancel(Deployment $deployment, ?User $actor = null, ?string $ip = null): Deployment
    {
        $site = $deployment->site;
        $release = $site instanceof Site ? $this->acquire(self::lockKey($site), $this->requestLockWait()) : null;

        try {
            $deployment->refresh();
            if ($deployment->status !== DeploymentStatus::Waiting) {
                abort(422, __('site_ops.queue.cancel_unavailable'));
            }

            $this->close($deployment, DeploymentStatus::Cancelled, __('site_ops.queue.cancelled'));
            $this->releaseSite($deployment, $actor, $ip);
            $this->audit($deployment, $actor, $ip, 'site.deploy_queue_cancelled', [
                'reason' => 'operator',
            ]);
        } finally {
            if ($release !== null) {
                $release();
            }
        }

        return $deployment->refresh();
    }

    /**
     * FIFO place of a waiting row on its host (1 = next to start).
     */
    public function positionOf(Deployment $deployment): int
    {
        $site = $deployment->site;
        if (! $site instanceof Site || $deployment->status !== DeploymentStatus::Waiting) {
            return 0;
        }

        $queuedAt = $deployment->queued_at ?? $deployment->created_at;

        return Deployment::query()
            ->waiting()
            ->whereIn('site_id', $this->gate->peersOf($site)->select('id'))
            ->where(function ($query) use ($queuedAt, $deployment): void {
                $query->where('queued_at', '<', $queuedAt)
                    ->orWhere(function ($same) use ($queuedAt, $deployment): void {
                        $same->where('queued_at', $queuedAt)->where('id', '<=', $deployment->id);
                    });
            })
            ->count();
    }

    /**
     * Waiting rows of one site with their host position, for the Deployments tab.
     *
     * @return list<array{deployment: Deployment, position: int}>
     */
    public function forSite(Site $site): array
    {
        $rows = [];
        foreach ($site->deployments()->waiting()->with('requestedBy')->orderBy('queued_at')->orderBy('id')->get() as $row) {
            $row->setRelation('site', $site);
            $rows[] = ['deployment' => $row, 'position' => $this->positionOf($row)];
        }

        return $rows;
    }

    public function hasWaitingOnHost(Site $site): bool
    {
        return Deployment::query()
            ->waiting()
            ->whereIn('site_id', $this->gate->peersOf($site)->select('id'))
            ->exists();
    }

    /**
     * Close a waiting row that will never start.
     */
    public function close(Deployment $deployment, DeploymentStatus $status, string $reason): void
    {
        $deployment->forceFill([
            'status' => $status,
            'finished_at' => now(),
            'error_message' => $reason,
        ])->save();
    }

    /**
     * A waiting channel switch holds the site in `deploying`, a waiting
     * provision in `provisioning`. When the row goes away without a build, the
     * site must not stay there: nothing changed on Coolify for a switch, so it
     * goes back to active; a provision never built, so it lands in error where
     * Provision can be run again.
     */
    public function releaseSite(Deployment $deployment, ?User $actor, ?string $ip): void
    {
        $site = $deployment->site?->fresh();
        if (! $site instanceof Site) {
            return;
        }

        if ($deployment->queue_action === WaitingDeployAction::ChannelSwitch && $site->status === SiteStatus::Deploying) {
            $site->desired_channel = null;
            $site->transitionTo(SiteStatus::Active);
            $site->save();
            $this->audit($deployment, $actor, $ip, 'site.channel_switch_cancelled', []);
        }

        if ($deployment->queue_action === WaitingDeployAction::Provision && $site->canTransitionTo(SiteStatus::Error) && $site->status === SiteStatus::Provisioning) {
            $site->transitionTo(SiteStatus::Error);
            $site->save();
        }
    }

    /**
     * Run `$callback` holding the site's host lock, or return null without
     * running it when another process holds the lock.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn|null
     */
    public function withHostLock(string $hostKey, callable $callback): mixed
    {
        $release = $this->acquire(self::lockKeyForHost($hostKey), 0);
        if ($release === null) {
            return null;
        }

        try {
            return $callback();
        } finally {
            $release();
        }
    }

    /**
     * @param  array<string, mixed>  $after
     */
    public function audit(Deployment $deployment, ?User $actor, ?string $ip, string $action, array $after): void
    {
        $site = $deployment->site;
        if (! $site instanceof Site) {
            return;
        }

        $site->auditLogs()->create([
            'actor_user_id' => $actor?->id,
            'action' => $action,
            'before' => null,
            'after' => array_merge([
                'deployment_id' => $deployment->id,
                'queue_action' => $deployment->queue_action?->value,
            ], $after),
            'ip' => $ip,
        ]);
    }

    public static function lockKey(Site $site): string
    {
        return self::lockKeyForHost(CoolifyDeployGate::hostKey($site));
    }

    public static function lockKeyForHost(string $hostKey): string
    {
        return 'ops:deploy-queue:'.$hostKey;
    }

    public static function isDeployBusy(Throwable $exception): bool
    {
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof CoolifyDeployBusyException) {
                return true;
            }
        }

        return false;
    }

    /**
     * A 429 / 5xx / dropped connection says nothing about the deploy itself: the
     * row stays waiting and is tried again on the next tick.
     */
    public static function isTransient(Throwable $exception): bool
    {
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof CoolifyApiException) {
                return $current->isTransient();
            }
            if ($current instanceof ConnectionException) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function enqueue(
        Site $site,
        WaitingDeployAction $action,
        array $payload,
        ?User $actor,
        ?string $ip,
    ): DeployRequestOutcome {
        $running = $this->gate->unfinishedCount($site);

        $existing = $this->duplicateOf($site, $action, $payload);
        if ($existing instanceof Deployment) {
            return DeployRequestOutcome::queued($existing, $this->positionOf($existing), $running, duplicate: true);
        }

        $channel = $action === WaitingDeployAction::ChannelSwitch && $site->desired_channel instanceof Channel
            ? $site->desired_channel
            : $site->channel;

        $row = $site->deployments()->create([
            'channel' => $channel,
            'trigger' => $action->trigger(),
            'status' => DeploymentStatus::Waiting,
            'requested_by' => $actor?->id,
            'queue_action' => $action,
            'queue_payload' => array_merge($payload, ['ip' => $ip]),
            'queue_attempts' => 0,
            'queued_at' => now(),
        ]);
        $row->setRelation('site', $site);

        $position = $this->positionOf($row);
        $this->audit($row, $actor, $ip, 'site.deploy_queued', array_merge($payload, [
            'position' => $position,
            'running' => $running,
            'max' => $this->gate->maxConcurrent(),
        ]));

        return DeployRequestOutcome::queued($row, $position, $running);
    }

    /**
     * A second click on the same action returns the row already waiting. A
     * Tekrar deploy also rides on any waiting app action of the site: that row
     * builds the site anyway. Different targets (pin X, then HEAD) each keep
     * their own row, so the last request is also the last to run.
     *
     * @param  array<string, mixed>  $payload
     */
    private function duplicateOf(Site $site, WaitingDeployAction $action, array $payload): ?Deployment
    {
        $waiting = $site->deployments()->waiting()->orderBy('queued_at')->orderBy('id')->get();

        foreach ($waiting as $row) {
            if ($row->queue_action === $action && $this->samePayload($action, (array) $row->queue_payload, $payload)) {
                return $row;
            }

            if ($action === WaitingDeployAction::Redeploy && $row->queue_action?->isAppAction()) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $stored
     * @param  array<string, mixed>  $incoming
     */
    private function samePayload(WaitingDeployAction $action, array $stored, array $incoming): bool
    {
        return match ($action) {
            WaitingDeployAction::Pin => (string) ($stored['ref'] ?? '') === (string) ($incoming['ref'] ?? ''),
            WaitingDeployAction::Redeploy => (bool) ($stored['force'] ?? true) === (bool) ($incoming['force'] ?? true),
            default => true,
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizePayload(WaitingDeployAction $action, array $payload): array
    {
        return match ($action) {
            WaitingDeployAction::Pin => ['ref' => trim((string) ($payload['ref'] ?? ''))],
            WaitingDeployAction::Redeploy => ['force' => (bool) ($payload['force'] ?? true)],
            default => [],
        };
    }

    /**
     * Take `$key`, waiting up to `$waitSeconds`. Returns the release callback,
     * or null when the lock stayed busy. Re-entrant inside one process.
     *
     * @return (callable(): void)|null
     */
    private function acquire(string $key, int $waitSeconds): ?callable
    {
        if (isset(self::$held[$key])) {
            self::$held[$key]++;

            return static function () use ($key): void {
                self::$held[$key]--;
                if (self::$held[$key] <= 0) {
                    unset(self::$held[$key]);
                }
            };
        }

        /** @var Lock $lock */
        $lock = Cache::lock($key, self::LOCK_SECONDS);

        try {
            $acquired = $waitSeconds > 0 ? (bool) $lock->block($waitSeconds) : (bool) $lock->get();
        } catch (LockTimeoutException) {
            $acquired = false;
        }

        if (! $acquired) {
            return null;
        }

        self::$held[$key] = 1;

        return static function () use ($key, $lock): void {
            self::$held[$key]--;
            if (self::$held[$key] <= 0) {
                unset(self::$held[$key]);
                $lock->release();
            }
        };
    }

    private function requestLockWait(): int
    {
        return max(0, (int) config('ops.coolify.deploy.request_lock_wait_seconds', 5));
    }
}
