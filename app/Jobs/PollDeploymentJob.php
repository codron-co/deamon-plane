<?php

namespace App\Jobs;

use App\Enums\DeploymentTrigger;
use App\Models\CoolifyConnection;
use App\Models\Deployment;
use App\Models\Site;
use App\Services\Coolify\CoolifyApiException;
use App\Services\Coolify\CoolifyApplicationService;
use App\Services\Sites\ChannelSwitcher;
use App\Services\Sites\DeploymentFailureText;
use App\Services\Sites\SiteProvisioner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

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
    ) {}

    public function handle(SiteProvisioner $provisioner, CoolifyApplicationService $coolify, ChannelSwitcher $switcher): void
    {
        $deployment = Deployment::query()->with('site')->find($this->deploymentId);
        if ($deployment === null || $deployment->site === null) {
            return;
        }

        $owner = $deployment->trigger === DeploymentTrigger::ChannelSwitch
            ? $switcher
            : $provisioner;

        $uuid = $deployment->coolify_deployment_uuid;
        if (blank($uuid)) {
            $owner->markFailed(
                $deployment->site,
                'Coolify did not return a deployment uuid.',
                $deployment,
                $this->actorUserId,
                $this->ip,
            );

            return;
        }

        try {
            $remote = $this->coolifyFor($deployment->site, $coolify)->getDeployment($uuid);
        } catch (CoolifyApiException $exception) {
            $detail = DeploymentFailureText::fromException(
                $deployment->site,
                $exception,
                $owner->safeFailureMessage($deployment->site, $exception),
            );
            $owner->markFailed(
                $deployment->site,
                $detail['error_message'],
                $deployment,
                $this->actorUserId,
                $this->ip,
                $detail['log_excerpt'],
            );

            return;
        }

        $terminal = $owner->applyRemoteDeployment(
            $deployment,
            $remote,
            $this->actorUserId,
            $this->ip,
        );

        if ($terminal) {
            return;
        }

        $max = max(1, (int) config('ops.provision.poll_max_attempts', 40));
        if ($this->pollAttempt >= $max) {
            $owner->markFailed(
                $deployment->site,
                'Timed out waiting for Coolify deployment.',
                $deployment->fresh() ?? $deployment,
                $this->actorUserId,
                $this->ip,
            );

            return;
        }

        $delay = max(1, (int) config('ops.provision.poll_seconds', 15));

        self::dispatch($this->deploymentId, $this->actorUserId, $this->ip, $this->pollAttempt + 1)
            ->delay(now()->addSeconds($delay));
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
