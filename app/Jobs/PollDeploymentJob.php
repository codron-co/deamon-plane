<?php

namespace App\Jobs;

use App\Enums\DeploymentStatus;
use App\Enums\DeploymentTrigger;
use App\Models\CoolifyConnection;
use App\Models\Deployment;
use App\Models\Site;
use App\Services\Coolify\CoolifyApiException;
use App\Services\Coolify\CoolifyApplicationService;
use App\Services\Coolify\CoolifyCredentials;
use App\Services\Coolify\CoolifyDeploymentSync;
use App\Services\Coolify\CoolifyRateGuard;
use App\Services\Sites\ChannelSwitcher;
use App\Services\Sites\DeploymentFailureText;
use App\Services\Sites\SiteProvisioner;
use App\Support\RetryAfter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class PollDeploymentJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(
        public readonly int $deploymentId,
        public readonly ?int $actorUserId = null,
        public readonly ?string $ip = null,
        public readonly int $pollAttempt = 1,
        public readonly int $transientAttempt = 0,
    ) {}

    public function handle(
        SiteProvisioner $provisioner,
        CoolifyApplicationService $coolify,
        ChannelSwitcher $switcher,
        CoolifyDeploymentSync $sync,
    ): void {
        $deployment = Deployment::query()->with('site')->find($this->deploymentId);
        if ($deployment === null || $deployment->site === null) {
            return;
        }

        // The Coolify notification webhook usually resolves the row first; stop
        // polling instead of spending a request confirming what we already know.
        if ($this->isTerminal($deployment)) {
            return;
        }

        $statusOnly = $this->isStatusOnly($deployment);
        $owner = $deployment->trigger === DeploymentTrigger::ChannelSwitch
            ? $switcher
            : $provisioner;

        $uuid = $deployment->coolify_deployment_uuid;
        if (blank($uuid)) {
            $this->failOpen(
                $deployment,
                $owner,
                $sync,
                $statusOnly,
                'Coolify did not return a deployment uuid.',
            );

            return;
        }

        try {
            $remote = $this->coolifyFor($deployment->site, $coolify)->getDeployment($uuid);
        } catch (CoolifyApiException $exception) {
            // A throttled or unreachable status read says nothing about the build.
            // Recording it as a failure is how healthy deploys used to end up as
            // "Too Many Attempts." rows, so reschedule instead.
            if ($exception->isTransient() && $this->transientAttempt < $this->maxTransientAttempts()) {
                $this->rescheduleAfterTransient($deployment->site, $exception);

                return;
            }

            $message = $exception->isTransient()
                ? (string) __('coolify.errors.rate_limited_deploy')
                : ($statusOnly
                    ? trim($exception->getMessage()) ?: $exception::class
                    : $owner->safeFailureMessage($deployment->site, $exception));

            $detail = DeploymentFailureText::fromException($deployment->site, $exception, $message);
            $this->failOpen(
                $deployment,
                $owner,
                $sync,
                $statusOnly,
                $exception->isTransient() ? $message : $detail['error_message'],
                $detail['log_excerpt'],
            );

            return;
        }

        if ($statusOnly) {
            $terminal = $sync->applyExisting($deployment, $remote);
        } else {
            $terminal = $owner->applyRemoteDeployment(
                $deployment,
                $remote,
                $this->actorUserId,
                $this->ip,
            );
        }

        if ($terminal) {
            return;
        }

        $max = max(1, (int) config('ops.provision.poll_max_attempts', 40));
        if ($this->pollAttempt >= $max) {
            $this->failOpen(
                $deployment->fresh() ?? $deployment,
                $owner,
                $sync,
                $statusOnly,
                'Timed out waiting for Coolify deployment.',
            );

            return;
        }

        self::dispatch($this->deploymentId, $this->actorUserId, $this->ip, $this->pollAttempt + 1)
            ->delay(now()->addSeconds($this->nextPollSeconds()));
    }

    /**
     * Back off and re-queue the same poll attempt after a throttle / gateway error.
     */
    private function rescheduleAfterTransient(Site $site, CoolifyApiException $exception): void
    {
        $host = $this->hostFor($site);
        $cooldown = app(CoolifyRateGuard::class)->cooldownRemaining($host);
        $backoff = RetryAfter::backoff(
            $this->transientAttempt + 1,
            max(1, (int) config('ops.coolify.retry.base_delay_ms', 500)),
            max(1, (int) config('ops.coolify.retry.max_delay_ms', 8000)),
        );

        $delay = max(1, (int) ceil(max($cooldown, $backoff)));

        Log::info('Coolify deployment poll deferred', [
            'deployment_id' => $this->deploymentId,
            'status' => $exception->status,
            'transient_attempt' => $this->transientAttempt + 1,
            'delay_seconds' => $delay,
        ]);

        self::dispatch(
            $this->deploymentId,
            $this->actorUserId,
            $this->ip,
            $this->pollAttempt,
            $this->transientAttempt + 1,
        )->delay(now()->addSeconds($delay));
    }

    /**
     * Grow the interval and jitter it, so sibling polls dispatched in the same
     * second stop arriving at Coolify as one burst every cycle.
     */
    private function nextPollSeconds(): int
    {
        $base = max(1, (int) config('ops.provision.poll_seconds', 15));
        $max = max($base, (int) config('ops.provision.poll_max_seconds', 60));
        $grown = min($max, (int) ($base * (1 + ($this->pollAttempt - 1) * 0.25)));
        $jitter = max(1, (int) round($grown * 0.4));

        return max(1, random_int($grown - $jitter, $grown + $jitter));
    }

    private function maxTransientAttempts(): int
    {
        return max(1, (int) config('ops.provision.poll_transient_max_attempts', 8));
    }

    private function hostFor(Site $site): string
    {
        $site->loadMissing('coolifyConnection');
        $connection = $site->coolifyConnection;

        if ($connection instanceof CoolifyConnection && $connection->hasToken()) {
            return $connection->credentials()->apiRoot();
        }

        return CoolifyCredentials::resolve()->apiRoot();
    }

    private function isTerminal(Deployment $deployment): bool
    {
        return in_array($deployment->status, [
            DeploymentStatus::Finished,
            DeploymentStatus::Failed,
            DeploymentStatus::Cancelled,
        ], true);
    }

    private function isStatusOnly(Deployment $deployment): bool
    {
        return in_array($deployment->trigger, [
            DeploymentTrigger::Manual,
            DeploymentTrigger::ThemeRollout,
        ], true);
    }

    private function failOpen(
        Deployment $deployment,
        SiteProvisioner|ChannelSwitcher $owner,
        CoolifyDeploymentSync $sync,
        bool $statusOnly,
        string $message,
        ?string $logExcerpt = null,
    ): void {
        if ($statusOnly) {
            $sync->failWithoutSiteChange($deployment, $message, $logExcerpt);

            return;
        }

        $owner->markFailed(
            $deployment->site,
            $message,
            $deployment,
            $this->actorUserId,
            $this->ip,
            $logExcerpt,
        );
    }

    private function coolifyFor(Site $site, CoolifyApplicationService $fallback): CoolifyApplicationService
    {
        $site->loadMissing('coolifyConnection');
        $connection = $site->coolifyConnection;
        if ($connection instanceof CoolifyConnection && $connection->hasToken()) {
            return CoolifyApplicationService::forConnection($connection);
        }

        return $fallback;
    }
}
