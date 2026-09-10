<?php

namespace App\Services\Sites;

use App\Enums\Channel;
use App\Enums\CoolifyGitSourceKind;
use App\Enums\DeploymentStatus;
use App\Enums\DeploymentTrigger;
use App\Enums\SiteStatus;
use App\Jobs\PollDeploymentJob;
use App\Jobs\ProvisionSiteJob;
use App\Models\CloudflareSetting;
use App\Models\CoolifyConnection;
use App\Models\CoolifySetting;
use App\Models\Deployment;
use App\Models\Site;
use App\Models\User;
use App\Services\Cloudflare\CloudflareApiException;
use App\Services\Cloudflare\CloudflareZoneService;
use App\Services\Coolify\CoolifyApiException;
use App\Services\Coolify\CoolifyApplicationService;
use App\Services\Coolify\CoolifyCredentials;
use App\Services\Coolify\CoolifyProvisionPreflight;
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
        private readonly CoolifyProvisionPreflight $preflight,
        private readonly SiteAgentSecretInjector $agentSecrets,
        private readonly CloudflareZoneService $cloudflare,
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

        $this->assertCloudflareReady();
        $this->assertCoolifyReady($site);

        $connection = $this->connectionFor($site);
        if ($connection instanceof CoolifyConnection && blank($site->coolify_app_uuid)) {
            $this->preflight->assert($site, $connection);
        }

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

    public function provision(Site $site, ?int $actorUserId = null, ?string $ip = null): Deployment
    {
        $this->provisionOnCloudflare($site, $actorUserId, $ip);

        return $this->provisionOnCoolify($site, $actorUserId, $ip);
    }

    public function provisionOnCloudflare(Site $site, ?int $actorUserId = null, ?string $ip = null): void
    {
        $settings = CloudflareSetting::current();
        $this->assertCloudflareReady($settings);

        $this->cloudflare->ensureZoneAndDns($site, $settings);
        $site->refresh();

        $site->auditLogs()->create([
            'actor_user_id' => $actorUserId,
            'action' => 'site.cloudflare_ready',
            'after' => [
                'slug' => $site->slug,
                'primary_domain' => $site->primary_domain,
                'cloudflare_zone_id' => $site->cloudflare_zone_id,
                'cloudflare_nameservers' => $site->cloudflare_nameservers,
            ],
            'ip' => $ip,
        ]);
    }

    public function provisionOnCoolify(Site $site, ?int $actorUserId = null, ?string $ip = null): Deployment
    {
        $connection = $this->connectionFor($site);
        $coolify = $connection instanceof CoolifyConnection
            ? CoolifyApplicationService::forConnection($connection)
            : $this->coolify;

        $appUuid = $site->coolify_app_uuid;

        if (blank($appUuid)) {
            if ($connection instanceof CoolifyConnection) {
                $this->preflight->assert($site, $connection);
            }

            $created = $coolify->createComposeApp($this->createRequest($site, $connection));
            $appUuid = $created->uuid;
            $site->coolify_app_uuid = $appUuid;
            $site->save();
        }

        $coolify->updateEnvs($appUuid, [
            'APP_KEY' => (string) $site->app_key_encrypted,
            'DEAMON_SITE_NAME' => $site->name,
        ]);

        $coolify->setDomains($appUuid, $site->primary_domain);

        try {
            $this->agentSecrets->inject($site);
        } catch (SiteProvisionException) {
            // Provision continues; operator can use Generate & inject on the site.
        }

        $deployed = $coolify->deploy($appUuid);
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
        ?string $logExcerpt = null,
    ): void {
        $safe = $this->redactSecrets($site, $message);

        if ($deployment === null) {
            $channel = $site->channel instanceof Channel ? $site->channel : Channel::from((string) $site->channel);
            $deployment = $site->deployments()->create([
                'channel' => $channel,
                'trigger' => DeploymentTrigger::Create,
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
            'error' => $safe,
        ]);
    }

    public function safeFailureMessage(Site $site, Throwable $exception): string
    {
        $message = trim($exception->getMessage());
        if ($message === '') {
            $message = $exception::class;
        }

        if (! ($exception instanceof CoolifyApiException
            || $exception instanceof CloudflareApiException
            || $exception instanceof SiteProvisionException)) {
            $message = class_basename($exception).': '.$message;
        }

        return DeploymentFailureText::fromException($site, $exception, $message)['error_message'];
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

    private function assertCloudflareReady(?CloudflareSetting $settings = null): void
    {
        $settings ??= CloudflareSetting::current();

        if (! $settings->hasCredentials()) {
            throw new SiteProvisionException(__('cloudflare.errors.not_configured'));
        }
    }

    private function assertCoolifyReady(Site $site): void
    {
        $connection = $this->connectionFor($site);
        $credentials = $connection instanceof CoolifyConnection
            ? $connection->credentials()
            : CoolifyCredentials::resolve(CoolifySetting::current());

        if ($credentials->baseUrl === '' || ! $credentials->hasToken()) {
            throw new SiteProvisionException('Coolify bağlı değil. Coolify menüsünden URL ve API token ekleyin.');
        }

        [$project, $server] = $this->resolveTargets($site, $connection);

        if ($project === '' || $server === '') {
            throw new SiteProvisionException('Provision için aktif proje ve sunucu seçin (Coolify menüsü). UUID’yi elle yazmayın.');
        }
    }

    private function createRequest(Site $site, ?CoolifyConnection $connection): CreateComposeAppRequest
    {
        [$project, $server] = $this->resolveTargets($site, $connection);

        $channel = $site->channel instanceof Channel
            ? $site->channel->value
            : (string) $site->channel;

        $repository = filled($site->git_repository)
            ? (string) $site->git_repository
            : (string) config('ops.deamon.repository');

        [$githubApp, $privateKey] = $this->resolveGitSource($site, $connection);
        $environmentUuid = trim((string) $site->coolify_environment_uuid);
        if ($environmentUuid === '' && $connection instanceof CoolifyConnection) {
            $environmentUuid = trim((string) $connection->default_environment_uuid);
        }

        $environmentName = $connection?->default_environment_name
            ?: (string) config('ops.provision.environment_name', 'production');

        return new CreateComposeAppRequest(
            projectUuid: $project,
            serverUuid: $server,
            gitRepository: $repository,
            gitBranch: $channel,
            environmentName: $environmentUuid === '' ? $environmentName : null,
            environmentUuid: $environmentUuid !== '' ? $environmentUuid : null,
            githubAppUuid: $githubApp,
            privateKeyUuid: $privateKey,
            name: 'deamon-'.$site->slug,
            instantDeploy: false,
            dockerComposeDomains: filled($site->primary_domain) ? (string) $site->primary_domain : null,
            dockerComposeLocation: CreateComposeAppRequest::DEFAULT_COMPOSE_LOCATION,
        );
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function resolveTargets(Site $site, ?CoolifyConnection $connection): array
    {
        $settings = CoolifySetting::current();
        $project = trim((string) (
            $site->coolify_project_uuid
            ?: $connection?->default_project_uuid
            ?: $settings->default_project_uuid
            ?: config('ops.coolify.default_project_uuid')
        ));
        $server = trim((string) (
            $site->coolify_server_uuid
            ?: $connection?->default_server_uuid
            ?: $settings->default_server_uuid
            ?: config('ops.coolify.default_server_uuid')
        ));

        return [$project, $server];
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function resolveGitSource(Site $site, ?CoolifyConnection $connection): array
    {
        $kind = $site->coolify_git_source_kind instanceof CoolifyGitSourceKind
            ? $site->coolify_git_source_kind
            : CoolifyGitSourceKind::tryFrom((string) $site->coolify_git_source_kind);
        $uuid = trim((string) $site->coolify_git_source_uuid);

        if ($uuid === '' && $connection instanceof CoolifyConnection) {
            $kind = $connection->default_git_source_kind instanceof CoolifyGitSourceKind
                ? $connection->default_git_source_kind
                : CoolifyGitSourceKind::tryFrom((string) $connection->default_git_source_kind);
            $uuid = trim((string) $connection->default_git_source_uuid);
        }

        if ($uuid === '') {
            $settings = CoolifySetting::current();

            return [
                filled($settings->github_app_uuid) ? (string) $settings->github_app_uuid : null,
                filled($settings->private_key_uuid) ? (string) $settings->private_key_uuid : null,
            ];
        }

        return [
            $kind === CoolifyGitSourceKind::GithubApp ? $uuid : null,
            $kind === CoolifyGitSourceKind::DeployKey ? $uuid : null,
        ];
    }

    private function connectionFor(Site $site): ?CoolifyConnection
    {
        if ($site->coolify_connection_id) {
            $site->loadMissing('coolifyConnection');

            return $site->coolifyConnection;
        }

        return CoolifyConnection::default();
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
        return DeploymentFailureText::redact($site, $message);
    }
}
