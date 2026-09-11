<?php

namespace App\Jobs;

use App\Enums\DeploymentTrigger;
use App\Models\CoolifyConnection;
use App\Models\Deployment;
use App\Models\Site;
use App\Services\Coolify\CoolifyApiException;
use App\Services\Coolify\CoolifyApplicationService;
use App\Services\Coolify\CoolifyDeploymentSync;
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
            $detail = DeploymentFailureText::fromException(
                $deployment->site,
                $exception,
                $statusOnly
                    ? trim($exception->getMessage()) ?: $exception::class
                    : $owner->safeFailureMessage($deployment->site, $exception),
            );
            $this->failOpen(
                $deployment,
                $owner,
                $sync,
                $statusOnly,
                $detail['error_message'],
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

        $delay = max(1, (int) config('ops.provision.poll_seconds', 15));

        self::dispatch($this->deploymentId, $this->actorUserId, $this->ip, $this->pollAttempt + 1)
            ->delay(now()->addSeconds($delay));
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
