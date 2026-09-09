<?php

namespace App\Services\Sites;

use App\Enums\Channel;
use App\Enums\DeploymentStatus;
use App\Enums\DeploymentTrigger;
use App\Enums\SiteStatus;
use App\Jobs\PollDeploymentJob;
use App\Jobs\ProvisionSiteJob;
use App\Models\CoolifySetting;
use App\Models\Deployment;
use App\Models\Site;
use App\Models\User;
use App\Services\Coolify\CoolifyApiException;
use App\Services\Coolify\CoolifyApplicationService;
use App\Services\Coolify\CoolifyCredentials;
use App\Services\Coolify\Dto\CoolifyDeployment;
use App\Services\Coolify\Dto\CreateComposeAppRequest;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class SiteProvisioner
{
    public function __construct(
        private readonly CoolifyApplicationService $coolify,
    ) {}

    public function canStart(Site $site): bool
    {
        return in_array($site->status, [SiteStatus::Draft, SiteStatus::Error], true);
    }

    public function start(Site $site, ?User $actor = null, ?string $ip = null): void
    {
        if (! $this->canStart($site)) {
            throw new SiteProvisionException('Only draft or failed sites can be provisioned.');
        }

        $this->assertCoolifyReady($site);

        $siteId = (string) $site->id;

        DB::transaction(function () use ($siteId, $actor, $ip): void {
            /** @var Site $locked */
            $locked = Site::query()->lockForUpdate()->findOrFail($siteId);

            if (! $this->canStart($locked)) {
                throw new SiteProvisionException('Only draft or failed sites can be provisioned.');
            }

            $this->ensureSecrets($locked);
            $locked->transitionTo(SiteStatus::Provisioning);
            $locked->save();

            $locked->auditLogs()->create([
                'actor_user_id' => $actor?->id,
                'action' => 'site.provision_started',
                'after' => $this->auditSnapshot($locked),
                'ip' => $ip,
            ]);
        });

        ProvisionSiteJob::dispatch($siteId, $actor?->id, $ip);
    }

    public function provisionOnCoolify(Site $site, ?int $actorUserId = null, ?string $ip = null): Deployment
    {
        $appUuid = $site->coolify_app_uuid;

        if (blank($appUuid)) {
            $created = $this->coolify->createComposeApp($this->createRequest($site));
            $appUuid = $created->uuid;
            $site->coolify_app_uuid = $appUuid;
            $site->save();
        }

        $this->coolify->updateEnvs($appUuid, [
            'APP_KEY' => (string) $site->app_key_encrypted,
            'DEAMON_SITE_NAME' => $site->name,
        ]);

        $this->coolify->setDomains($appUuid, $site->primary_domain);

        $deployed = $this->coolify->deploy($appUuid);
        $deploymentUuid = $deployed->firstDeploymentUuid();

        $channel = $site->channel instanceof Channel ? $site->channel : Channel::from((string) $site->channel);

        $deployment = $site->deployments()->create([
            'channel' => $channel,
            'trigger' => DeploymentTrigger::Create,
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
        $effective = $mapped === DeploymentStatus::Queued
            ? DeploymentStatus::InProgress
            : $mapped;
        $site = $deployment->site;

        if (
            $deployment->finished_at !== null
            && in_array($deployment->status, [DeploymentStatus::Finished, DeploymentStatus::Failed, DeploymentStatus::Cancelled], true)
            && (
                $deployment->status === $effective
                || in_array($effective, [DeploymentStatus::Queued, DeploymentStatus::InProgress], true)
            )
        ) {
            return true;
        }

        $deployment->status = $effective;

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
            $this->markFailed($site, 'Coolify deployment '.$status.'.', $deployment, $actorUserId, $ip);

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
        $deployment->status = DeploymentStatus::Finished;
        if (filled($remote->commit)) {
            $deployment->commit_sha = $remote->commit;
        }
        $deployment->finished_at = now();
        $deployment->error_message = null;
        $deployment->save();

        if ($site->canTransitionTo(SiteStatus::Active)) {
            $site->transitionTo(SiteStatus::Active);
        }

        if (blank($site->agent_base_url) && filled($site->primary_domain)) {
            $host = $site->primary_domain;
            $site->agent_base_url = preg_match('#^https?://#i', $host) === 1
                ? $host
                : 'https://'.$host;
        }

        $site->save();

        $site->auditLogs()->create([
            'actor_user_id' => $actorUserId,
            'action' => 'site.provision_succeeded',
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
    ): void {
        $safe = $this->redactSecrets($site, $message);

        if ($deployment !== null) {
            $deployment->status = $deployment->status === DeploymentStatus::Cancelled
                ? DeploymentStatus::Cancelled
                : DeploymentStatus::Failed;
            $deployment->error_message = $safe;
            $deployment->finished_at = now();
            $deployment->save();
        }

        if ($site->status !== SiteStatus::Error && $site->canTransitionTo(SiteStatus::Error)) {
            $site->transitionTo(SiteStatus::Error);
            $site->save();
        }

        $site->auditLogs()->create([
            'actor_user_id' => $actorUserId,
            'action' => 'site.provision_failed',
            'after' => array_merge($this->auditSnapshot($site), [
                'error' => $safe,
            ]),
            'ip' => $ip,
        ]);

        Log::warning('Site provision failed', [
            'site_id' => $site->id,
            'site_slug' => $site->slug,
        ]);
    }

    public function safeFailureMessage(Site $site, Throwable $exception): string
    {
        $message = $exception instanceof CoolifyApiException
            ? $exception->getMessage()
            : 'Provisioning failed.';

        return $this->redactSecrets($site, $message);
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

    private function ensureSecrets(Site $site): void
    {
        if (blank($site->app_key_encrypted)) {
            $site->app_key_encrypted = 'base64:'.base64_encode(Encrypter::generateKey((string) config('app.cipher')));
        }

        if (blank($site->agent_secret_encrypted)) {
            $site->agent_secret_encrypted = Str::password(64, symbols: false);
        }
    }

    private function assertCoolifyReady(Site $site): void
    {
        $settings = CoolifySetting::current();
        $credentials = CoolifyCredentials::resolve($settings);

        if ($credentials->baseUrl === '' || ! $credentials->hasToken()) {
            throw new SiteProvisionException('Coolify is not configured. Add the API URL and token in Settings.');
        }

        [$project, $server] = $this->resolveTargets($site, $settings);

        if ($project === '' || $server === '') {
            throw new SiteProvisionException('Coolify project and server UUIDs are required before provision.');
        }
    }

    private function createRequest(Site $site): CreateComposeAppRequest
    {
        $settings = CoolifySetting::current();
        [$project, $server] = $this->resolveTargets($site, $settings);

        $channel = $site->channel instanceof Channel
            ? $site->channel->value
            : (string) $site->channel;

        $repository = filled($site->git_repository)
            ? (string) $site->git_repository
            : (string) config('ops.deamon.repository');

        return new CreateComposeAppRequest(
            projectUuid: $project,
            serverUuid: $server,
            gitRepository: $repository,
            gitBranch: $channel,
            environmentName: (string) config('ops.provision.environment_name', 'production'),
            githubAppUuid: filled($settings->github_app_uuid) ? (string) $settings->github_app_uuid : null,
            privateKeyUuid: filled($settings->private_key_uuid) ? (string) $settings->private_key_uuid : null,
            name: 'deamon-'.$site->slug,
            instantDeploy: false,
            dockerComposeLocation: (string) config('ops.deamon.compose_file', CreateComposeAppRequest::DEFAULT_COMPOSE_LOCATION),
        );
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function resolveTargets(Site $site, CoolifySetting $settings): array
    {
        $project = trim((string) ($settings->default_project_uuid ?: config('ops.coolify.default_project_uuid')));
        $server = trim((string) ($site->coolify_server_uuid ?: $settings->default_server_uuid ?: config('ops.coolify.default_server_uuid')));

        return [$project, $server];
    }

    /**
     * @return array<string, mixed>
     */
    private function auditSnapshot(Site $site): array
    {
        return [
            'slug' => $site->slug,
            'name' => $site->name,
            'primary_domain' => $site->primary_domain,
            'channel' => $site->channel instanceof Channel ? $site->channel->value : $site->channel,
            'status' => $site->status instanceof SiteStatus ? $site->status->value : $site->status,
            'coolify_app_uuid' => $site->coolify_app_uuid,
            'coolify_server_uuid' => $site->coolify_server_uuid,
        ];
    }

    private function redactSecrets(Site $site, string $message): string
    {
        $redacted = CoolifyApiException::redact($message);

        foreach ([$site->app_key_encrypted, $site->agent_secret_encrypted] as $secret) {
            if (is_string($secret) && $secret !== '' && str_contains($redacted, $secret)) {
                $redacted = str_replace($secret, '[redacted]', $redacted);
            }
        }

        return $redacted;
    }
}
