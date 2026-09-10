<?php

namespace App\Services\Sites;

use App\Enums\Channel;
use App\Enums\DeploymentStatus;
use App\Enums\DeploymentTrigger;
use App\Enums\OpsRole;
use App\Enums\SiteStatus;
use App\Jobs\PollDeploymentJob;
use App\Jobs\SwitchSiteChannelJob;
use App\Models\CoolifySetting;
use App\Models\Deployment;
use App\Models\Site;
use App\Models\User;
use App\Services\Coolify\CoolifyApiException;
use App\Services\Coolify\CoolifyApplicationService;
use App\Services\Coolify\CoolifyCredentials;
use App\Services\Coolify\Dto\CoolifyDeployment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ChannelSwitcher
{
    public function canStart(Site $site): bool
    {
        return $site->canSwitchChannel();
    }

    public function requiresConfirm(Channel $from, Channel $to): bool
    {
        $map = config('ops.channel_switch.downgrade_requires_confirm', []);

        return in_array($to->value, $map[$from->value] ?? [], true);
    }

    public function requiresVersionGate(Channel $from, Channel $to): bool
    {
        $map = config('ops.channel_switch.upgrade_requires_version_gate', []);

        return in_array($to->value, $map[$from->value] ?? [], true);
    }

    public function start(
        Site $site,
        Channel $target,
        User $actor,
        ?string $ip = null,
        bool $confirmed = false,
        bool $force = false,
    ): void {
        Channel::assertAllowed($target);

        $from = $site->channel instanceof Channel
            ? $site->channel
            : Channel::from((string) $site->channel);

        if ($from === $target) {
            throw new ChannelSwitchException('Site is already on this channel.');
        }

        if ($this->requiresConfirm($from, $target) && ! $confirmed) {
            throw new ChannelSwitchException('Switching off main requires confirmation. Volumes stay; the Coolify app is not deleted.');
        }

        if ($force && $this->requiresVersionGate($from, $target) && ! $this->isSuperAdmin($actor)) {
            throw new ChannelSwitchException('Only Super Admin can force a switch to main.');
        }

        if (! $this->versionGateAllows($site, $from, $target, $force && $this->isSuperAdmin($actor))) {
            throw new ChannelSwitchException('Version gate blocked the switch to main. Reported Deamon version is below the minimum. Super Admin can force.');
        }

        if (in_array($site->status, [SiteStatus::Deploying, SiteStatus::Provisioning], true)) {
            throw new ChannelSwitchException('A deploy is already in progress. Wait for it to finish before switching channel.');
        }

        if (! $this->canStart($site)) {
            throw new ChannelSwitchException('Only active or failed provisioned sites can switch channel.');
        }

        if (blank($site->coolify_app_uuid)) {
            throw new ChannelSwitchException('Site has no Coolify application. Provision first.');
        }

        $this->assertCoolifyReady();

        $siteId = (string) $site->id;
        $forced = $force && $this->isSuperAdmin($actor) && $this->requiresVersionGate($from, $target);

        DB::transaction(function () use ($siteId, $target, $actor, $ip, $confirmed, $forced): void {
            /** @var Site $locked */
            $locked = Site::query()->lockForUpdate()->findOrFail($siteId);

            if (in_array($locked->status, [SiteStatus::Deploying, SiteStatus::Provisioning], true)) {
                throw new ChannelSwitchException('A deploy is already in progress. Wait for it to finish before switching channel.');
            }

            if (! $this->canStart($locked)) {
                throw new ChannelSwitchException('Only active or failed provisioned sites can switch channel.');
            }

            $before = $this->auditSnapshot($locked);

            $locked->desired_channel = $target;
            $locked->transitionTo(SiteStatus::Deploying);
            $locked->save();

            $locked->auditLogs()->create([
                'actor_user_id' => $actor->id,
                'action' => 'site.channel_switch_started',
                'before' => $before,
                'after' => array_merge($this->auditSnapshot($locked), [
                    'confirmed' => $confirmed,
                    'forced' => $forced,
                ]),
                'ip' => $ip,
            ]);
        });

        SwitchSiteChannelJob::dispatch($siteId, $actor->id, $ip);
    }

    public function switchOnCoolify(Site $site, ?int $actorUserId = null, ?string $ip = null): Deployment
    {
        $appUuid = (string) $site->coolify_app_uuid;
        $target = $site->desired_channel instanceof Channel
            ? $site->desired_channel
            : Channel::from((string) $site->desired_channel);

        $coolify = CoolifyApplicationService::forSite($site);
        $patch = [
            'git_branch' => $target->value,
            'git_commit_sha' => '',
        ];
        $environmentUuid = ChannelEnvironmentMap::environmentUuid($site, $target);
        if ($environmentUuid !== null) {
            $patch['environment_uuid'] = $environmentUuid;
        }

        $coolify->patchApplication($appUuid, $patch);
        $coolify->updateEnvs($appUuid, [
            'APP_ENV' => ChannelEnvironmentMap::appEnv($target),
            'DEAMON_CHANNEL' => $target->value,
        ]);

        if ($environmentUuid !== null && $site->coolify_environment_uuid !== $environmentUuid) {
            $site->coolify_environment_uuid = $environmentUuid;
            $site->save();
        }

        $deployed = $coolify->deploy($appUuid);
        $deploymentUuid = $deployed->firstDeploymentUuid();

        $deployment = $site->deployments()->create([
            'channel' => $target,
            'trigger' => DeploymentTrigger::ChannelSwitch,
            'coolify_deployment_uuid' => $deploymentUuid,
            'status' => $deploymentUuid === null ? DeploymentStatus::Failed : DeploymentStatus::InProgress,
            'started_at' => now(),
            'requested_by' => $actorUserId,
        ]);

        if ($deploymentUuid === null) {
            $this->markFailed($site, 'Coolify did not return a deployment uuid.', $deployment, $actorUserId, $ip);

            return $deployment;
        }

        PollDeploymentJob::dispatch($deployment->id, $actorUserId, $ip);

        return $deployment;
    }

    public function applyRemoteDeployment(
        Deployment $deployment,
        CoolifyDeployment $remote,
        ?int $actorUserId = null,
        ?string $ip = null,
    ): bool {
        $mapped = $this->mapRemoteStatus($remote->status);
        $site = $deployment->site;

        $deployment->status = $mapped === DeploymentStatus::Queued
            ? DeploymentStatus::InProgress
            : $mapped;

        if (filled($remote->commit)) {
            $deployment->commit_sha = $remote->commit;
        }

        $deployment->save();

        if ($mapped === DeploymentStatus::Finished) {
            $this->markSucceeded($site, $deployment, $remote, $actorUserId, $ip);

            return true;
        }

        if (in_array($mapped, [DeploymentStatus::Failed, DeploymentStatus::Cancelled], true)) {
            $status = $remote->status ?? $mapped->value;
            $detail = DeploymentFailureText::fromRemote($site, $remote, 'Coolify deployment '.$status.'.');
            $this->markFailed($site, $detail['error_message'], $deployment, $actorUserId, $ip, $detail['log_excerpt']);

            return true;
        }

        return false;
    }

    public function markSucceeded(
        Site $site,
        Deployment $deployment,
        CoolifyDeployment $remote,
        ?int $actorUserId = null,
        ?string $ip = null,
    ): void {
        $target = $site->desired_channel instanceof Channel
            ? $site->desired_channel
            : ($deployment->channel instanceof Channel ? $deployment->channel : null);

        $beforeChannel = $site->channel instanceof Channel ? $site->channel->value : $site->channel;

        $deployment->status = DeploymentStatus::Finished;
        if (filled($remote->commit)) {
            $deployment->commit_sha = $remote->commit;
        }
        $deployment->finished_at = now();
        $deployment->error_message = null;
        $deployment->save();

        if ($target instanceof Channel) {
            $site->channel = $target;
        }

        $site->desired_channel = null;

        if ($site->canTransitionTo(SiteStatus::Active)) {
            $site->transitionTo(SiteStatus::Active);
        }

        $site->save();

        $site->auditLogs()->create([
            'actor_user_id' => $actorUserId,
            'action' => 'site.channel_switched',
            'before' => [
                'channel' => $beforeChannel,
                'desired_channel' => $target instanceof Channel ? $target->value : $target,
                'status' => SiteStatus::Deploying->value,
            ],
            'after' => $this->auditSnapshot($site),
            'ip' => $ip,
        ]);
    }

    public function markFailed(
        Site $site,
        string $message,
        ?Deployment $deployment = null,
        ?int $actorUserId = null,
        ?string $ip = null,
        ?string $logExcerpt = null,
    ): void {
        $safe = $this->redactSecrets($site, $message);

        if ($deployment === null) {
            $channel = $site->desired_channel instanceof Channel
                ? $site->desired_channel
                : ($site->channel instanceof Channel ? $site->channel : Channel::from((string) $site->channel));
            $deployment = $site->deployments()->create([
                'channel' => $channel,
                'trigger' => DeploymentTrigger::ChannelSwitch,
                'status' => DeploymentStatus::Failed,
                'started_at' => now(),
                'requested_by' => $actorUserId,
            ]);
        }

        $deployment->status = $deployment->status === DeploymentStatus::Cancelled
            ? DeploymentStatus::Cancelled
            : DeploymentStatus::Failed;
        $deployment->error_message = $safe;
        if ($logExcerpt !== null) {
            $deployment->log_excerpt = $this->redactSecrets($site, $logExcerpt);
        }
        $deployment->finished_at = now();
        $deployment->save();

        if ($site->status !== SiteStatus::Error && $site->canTransitionTo(SiteStatus::Error)) {
            $site->transitionTo(SiteStatus::Error);
        }

        $site->save();

        $site->auditLogs()->create([
            'actor_user_id' => $actorUserId,
            'action' => 'site.channel_switch_failed',
            'after' => array_merge($this->auditSnapshot($site), [
                'error' => $safe,
            ]),
            'ip' => $ip,
        ]);

        Log::warning('Site channel switch failed', [
            'site_id' => $site->id,
            'site_slug' => $site->slug,
        ]);
    }

    public function safeFailureMessage(Site $site, Throwable $exception): string
    {
        $fallback = $exception instanceof CoolifyApiException
            ? $exception->getMessage()
            : 'Channel switch failed.';

        return DeploymentFailureText::fromException($site, $exception, $fallback)['error_message'];
    }

    public function mapRemoteStatus(?string $status): DeploymentStatus
    {
        $normalized = strtolower(str_replace(['-', ' '], '_', trim((string) $status)));

        return match ($normalized) {
            'finished', 'success', 'successful', 'done' => DeploymentStatus::Finished,
            'failed', 'error', 'exited' => DeploymentStatus::Failed,
            'cancelled', 'canceled', 'cancelled_by_user', 'canceled_by_user' => DeploymentStatus::Cancelled,
            'queued', 'pending' => DeploymentStatus::Queued,
            default => DeploymentStatus::InProgress,
        };
    }

    private function versionGateAllows(Site $site, Channel $from, Channel $target, bool $forced): bool
    {
        if (! $this->requiresVersionGate($from, $target)) {
            return true;
        }

        if ($forced) {
            return true;
        }

        $reported = $site->reportedDeamonVersion();
        if ($reported === null) {
            // Missing last health / deamon_version does not block beta/alpha → main.
            return true;
        }

        $minimum = trim((string) config('ops.channel_switch.main_minimum_version', ''));
        if ($minimum === '') {
            return true;
        }

        return version_compare($reported, $minimum, '>=');
    }

    private function assertCoolifyReady(): void
    {
        $credentials = CoolifyCredentials::resolve(CoolifySetting::current());

        if ($credentials->baseUrl === '' || ! $credentials->hasToken()) {
            throw new ChannelSwitchException('Coolify bağlı değil. Coolify menüsünden URL ve API token ekleyin.');
        }
    }

    private function isSuperAdmin(User $actor): bool
    {
        return $actor->hasRole(OpsRole::SuperAdmin->value);
    }

    /**
     * @return array<string, mixed>
     */
    private function auditSnapshot(Site $site): array
    {
        return [
            'slug' => $site->slug,
            'channel' => $site->channel instanceof Channel ? $site->channel->value : $site->channel,
            'desired_channel' => $site->desired_channel instanceof Channel
                ? $site->desired_channel->value
                : $site->desired_channel,
            'status' => $site->status instanceof SiteStatus ? $site->status->value : $site->status,
            'coolify_app_uuid' => $site->coolify_app_uuid,
        ];
    }

    private function redactSecrets(Site $site, string $message): string
    {
        return DeploymentFailureText::redact($site, $message);
    }
}
