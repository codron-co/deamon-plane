<?php

namespace App\Services\Sites;

use App\Enums\Channel;
use App\Enums\CoolifyGitSourceKind;
use App\Enums\DeploymentStatus;
use App\Enums\DeploymentTrigger;
use App\Enums\SiteStatus;
use App\Enums\WaitingDeployAction;
use App\Jobs\EnsureComailDomainJob;
use App\Jobs\PollDeploymentJob;
use App\Jobs\ProvisionSiteJob;
use App\Models\CloudflareSetting;
use App\Models\CoolifyConnection;
use App\Models\CoolifySetting;
use App\Models\Deployment;
use App\Models\Site;
use App\Models\User;
use App\Services\Cloudflare\CloudflareAccounts;
use App\Services\Cloudflare\CloudflareApiException;
use App\Services\Cloudflare\CloudflareZoneService;
use App\Services\Coolify\CoolifyApiException;
use App\Services\Coolify\CoolifyApplicationService;
use App\Services\Coolify\CoolifyCredentials;
use App\Services\Coolify\CoolifyProvisionPreflight;
use App\Services\Coolify\DockerImageLabels;
use App\Services\Coolify\Dto\CoolifyApplication;
use App\Services\Coolify\Dto\CoolifyDeployment;
use App\Services\Coolify\Dto\CreateComposeAppRequest;
use App\Services\Coolify\Dto\CreateDockerImageAppRequest;
use App\Services\Mail\DeployFailedNotifier;
use App\Services\Mail\SiteMailConfigurer;
use App\Services\Mail\SiteMailOrderBinder;
use App\Services\Ops\WaitingDeployQueue;
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
        private readonly SiteLanding $landing,
        private readonly NewSitePlacement $placement,
    ) {}

    public function canStart(Site $site): bool
    {
        return in_array($site->status, [SiteStatus::Draft, SiteStatus::Error], true);
    }

    public function start(Site $site, ?User $actor = null, ?string $ip = null): void
    {
        if (! $this->canStart($site)) {
            throw new SiteProvisionException(__('sites.provision_errors.not_provisionable'));
        }

        $this->assertCloudflareReady($this->cloudflareSettingsFor($site));
        $this->assertCoolifyReady($site);
        $this->placement->targets($site);

        $connection = $this->connectionFor($site);
        if ($connection instanceof CoolifyConnection && blank($site->coolify_app_uuid)) {
            $this->preflight->assert($site, $connection);
        }

        $siteId = (string) $site->id;

        DB::transaction(function () use ($siteId, $actor, $ip): void {
            /** @var Site $locked */
            $locked = Site::query()->lockForUpdate()->findOrFail($siteId);

            if (! $this->canStart($locked)) {
                throw new SiteProvisionException(__('sites.provision_errors.not_provisionable'));
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

        $site->refresh();
        $this->syncMail($site);

        ProvisionSiteJob::dispatch($siteId, $actor?->id, $ip);
    }

    public function provision(Site $site, ?int $actorUserId = null, ?string $ip = null): Deployment
    {
        // Checked again here (start() did too): a default server removed meanwhile must
        // fail the job before Cloudflare or Coolify is touched.
        $this->placement->targets($site);

        $this->provisionOnCloudflare($site, $actorUserId, $ip);

        return $this->provisionOnCoolify($site, $actorUserId, $ip);
    }

    public function provisionOnCloudflare(Site $site, ?int $actorUserId = null, ?string $ip = null): void
    {
        $settings = $this->cloudflareSettingsFor($site);
        $this->assertCloudflareReady($settings);
        if (! $settings instanceof CloudflareSetting) {
            throw new SiteProvisionException(__('cloudflare.errors.not_configured'));
        }

        $result = $this->cloudflare->ensureZoneAndDns($site, $settings);
        $site->refresh();
        $this->landing->afterCloudflare($site, $settings, $result['zone']);

        try {
            $this->landing->ensureTemporaryPreview($site, $settings);
        } catch (Throwable $exception) {
            $site->auditLogs()->create([
                'actor_user_id' => $actorUserId,
                'action' => 'site.preview_unavailable',
                'after' => [
                    'slug' => $site->slug,
                    'primary_domain' => $site->primary_domain,
                    'error' => $this->redactSecrets($site, $exception->getMessage()),
                ],
                'ip' => $ip,
            ]);
        }

        $site->refresh();

        $site->auditLogs()->create([
            'actor_user_id' => $actorUserId,
            'action' => 'site.cloudflare_ready',
            'after' => [
                'slug' => $site->slug,
                'primary_domain' => $site->primary_domain,
                'cloudflare_zone_id' => $site->cloudflare_zone_id,
                'cloudflare_nameservers' => $site->cloudflare_nameservers,
                'cloudflare_zone_status' => $site->cloudflare_zone_status,
                'temporary_domain' => $site->temporary_domain,
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

        // Build once, run many: the site runs `deamon-cms:<sha>` and never builds, so
        // its first deploy needs a commit the channel builder already finished. Asked
        // before the app exists: no built image fails the provision with nothing created.
        $images = app(BuiltImageCommits::class);
        $wantsImage = blank($appUuid) && self::provisionsDockerImage();
        $builtSha = $images->appliesTo($site)
            ? $images->latest(BuiltImageCommits::channelOf($site), $coolify, registry: $wantsImage)
            : null;

        if (blank($appUuid)) {
            if ($connection instanceof CoolifyConnection) {
                $this->preflight->assert($site, $connection);
            }

            // A Docker Image app runs a built image: a channel without a builder gets compose.
            $created = $wantsImage && $builtSha !== null
                ? $coolify->createDockerImageApp($this->createImageRequest($site, $connection, $builtSha))
                : $coolify->createComposeApp($this->createRequest($site, $connection));
            $appUuid = $created->uuid;
            $site->coolify_app_uuid = $appUuid;
            $site->save();
        }

        // Read once: the pin and the Docker Image setup both depend on the build pack.
        $app = $builtSha !== null ? $coolify->getApp((string) $appUuid) : null;

        // Compose: Coolify refuses docker_compose_domains until a deploy has loaded
        // docker_compose_raw from git. Bind domains only after deploy finishes
        // (see markSucceeded). Do not setDomains here. A Docker Image app has no
        // compose file: its env constants, volumes, domains and labels go in now
        // (again on a re-provision, every step is idempotent).
        if ($app?->isDockerImagePack()) {
            $this->prepareDockerImageApp($site, $coolify, $app);
        }

        try {
            $this->agentSecrets->inject($site);
        } catch (SiteProvisionException) {
            // Provision continues; operator can use Generate & inject on the site.
        }

        // The CMS compose has no `mysql` / `redis` of its own any more: the first build
        // must already find the shared DB_* / REDIS_* env. Failing here fails the provision
        // (no deploy); a re-provision repeats the idempotent steps.
        $placed = $this->placement->place($site, $coolify);
        $site->auditLogs()->create([
            'actor_user_id' => $actorUserId,
            'action' => 'site.shared_services_placed',
            'after' => ['slug' => $site->slug] + $placed,
            'ip' => $ip,
        ]);

        // Pin before the first deploy: auto-deploy off (a push must not start a commit
        // with no image), SOURCE_COMMIT filled (the compose image tag), the built commit.
        // A Docker Image app is pinned by its image tag. The same PATCH again on a re-provision.
        if ($builtSha !== null) {
            $images->pinApp($coolify, (string) $appUuid, $builtSha, $app);
            CoolifyDeploySettings::rememberPin($site, $builtSha);
            $site->auditLogs()->create([
                'actor_user_id' => $actorUserId,
                'action' => 'site.built_image_pinned',
                'after' => [
                    'slug' => $site->slug,
                    'channel' => BuiltImageCommits::channelOf($site)->value,
                    'git_commit_sha' => $builtSha,
                ],
                'ip' => $ip,
            ]);
        }

        // The first build waits in the Plane line when the host's build cap is
        // full; the site stays `provisioning` until the dispatcher starts it.
        return app(WaitingDeployQueue::class)->request(
            $site,
            WaitingDeployAction::Provision,
            [],
            $actorUserId !== null ? User::query()->find($actorUserId) : null,
            $ip,
        )->deployment;
    }

    /**
     * The provision build itself. Called straight from `provisionOnCoolify` when
     * the host has room, or by `WaitingDeployDispatcher` with the waiting row.
     */
    public function startProvisionDeploy(Site $site, ?int $actorUserId = null, ?string $ip = null, ?Deployment $into = null): Deployment
    {
        $connection = $this->connectionFor($site);
        $coolify = $connection instanceof CoolifyConnection
            ? CoolifyApplicationService::forConnection($connection)
            : $this->coolify;
        $appUuid = (string) $site->coolify_app_uuid;

        $deployed = $coolify->deploy($appUuid);
        $deploymentUuid = $deployed->firstDeploymentUuid();

        $channel = $site->channel instanceof Channel ? $site->channel : Channel::from((string) $site->channel);

        $values = [
            'channel' => $channel,
            'trigger' => DeploymentTrigger::Create,
            'coolify_deployment_uuid' => $deploymentUuid,
            'status' => $deploymentUuid === null ? DeploymentStatus::Failed : DeploymentStatus::InProgress,
            'started_at' => now(),
            'requested_by' => $into->requested_by ?? $actorUserId,
        ];

        if ($into instanceof Deployment) {
            $into->forceFill($values)->save();
            $deployment = $into;
        } else {
            $deployment = $site->deployments()->create($values);
        }

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

        // Compose domains need docker_compose_raw from the finished deploy.
        // Coolify writes Traefik labels at deploy time, so hosts bound only now
        // are not served until the app builds again: a first provision needs
        // one follow-up deploy or the site answers with the Traefik default cert.
        $domainsWereMissing = $deployment->trigger === DeploymentTrigger::Create
            && $site->canBindCoolifyDomains()
            && $this->domainsMissingOnCoolify($site);

        try {
            $this->landing->syncCoolifyDomains($site);

            if ($domainsWereMissing) {
                $this->deployAfterDomainBind($site, $actorUserId, $ip);
            }
        } catch (Throwable $exception) {
            Log::warning('site.domain_bind_after_deploy_failed', [
                'site_id' => $site->id,
                'site_slug' => $site->slug,
                'error' => $this->redactSecrets($site, $exception->getMessage()),
            ]);
            $site->auditLogs()->create([
                'actor_user_id' => $actorUserId,
                'action' => 'site.domain_bind_failed',
                'after' => [
                    'slug' => $site->slug,
                    'primary_domain' => $site->primary_domain,
                    'error' => $this->redactSecrets($site, $exception->getMessage()),
                ],
                'ip' => $ip,
            ]);
        }

        // CoMail: enroll the primary domain (mailcow domain + DKIM + DNS + CMS push).
        // Idempotent and queued; a mail failure never fails the provision.
        try {
            EnsureComailDomainJob::dispatchForPrimary($site);
        } catch (Throwable $exception) {
            Log::warning('comail.enroll_dispatch_failed', [
                'site_id' => $site->id,
                'error' => $this->redactSecrets($site, $exception->getMessage()),
            ]);
        }

        $site->auditLogs()->create([
            'actor_user_id' => $actorUserId,
            'action' => 'site.provision_succeeded',
            'after' => $this->auditSnapshot($site),
            'ip' => $ip,
        ]);
    }

    /**
     * Whether Plane's hosts are absent from the live Coolify app. An unreadable
     * app answers false: the follow-up deploy is skipped rather than guessed.
     */
    private function domainsMissingOnCoolify(Site $site): bool
    {
        try {
            $app = CoolifyApplicationService::forSite($site)->getApp((string) $site->coolify_app_uuid);
        } catch (Throwable) {
            return false;
        }

        $reconciler = app(SiteDomainReconciler::class);

        return $reconciler->missingOnCoolify($site, $reconciler->customerHosts($app)) !== [];
    }

    /**
     * Best effort: the provision already succeeded. A busy host queues the
     * follow-up deploy in the Plane line; any other refusal is audited and left
     * to the operator's Redeploy.
     */
    private function deployAfterDomainBind(Site $site, ?int $actorUserId, ?string $ip): void
    {
        try {
            // A full build cap queues the follow-up build in the Plane deploy
            // line instead of dropping it (it used to be audited and forgotten).
            $deployment = app(WaitingDeployQueue::class)->request(
                $site->fresh() ?? $site,
                WaitingDeployAction::Redeploy,
                ['force' => false],
                null,
                $ip,
            )->deployment;
        } catch (Throwable $exception) {
            $site->auditLogs()->create([
                'actor_user_id' => $actorUserId,
                'action' => 'site.redeploy_after_domain_bind_failed',
                'after' => [
                    'slug' => $site->slug,
                    'error' => $this->redactSecrets($site, $exception->getMessage()),
                ],
                'ip' => $ip,
            ]);

            return;
        }

        $site->auditLogs()->create([
            'actor_user_id' => $actorUserId,
            'action' => 'site.redeploy_after_domain_bind',
            'after' => [
                'slug' => $site->slug,
                'deployment_id' => $deployment->id,
            ],
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

        app(DeployFailedNotifier::class)->notify($site, $deployment);

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
            || $exception instanceof SiteProvisionException
            || $exception instanceof BuiltImageException)) {
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

    private function syncMail(Site $site): void
    {
        if (blank($site->mail_server_id)) {
            return;
        }

        try {
            app(SiteMailOrderBinder::class)->bind($site);
            app(SiteMailConfigurer::class)->sync($site);
        } catch (Throwable) {
            Log::warning('site.mail_bind_failed', [
                'site_id' => $site->id,
                'site_slug' => $site->slug,
            ]);
        }
    }

    private function cloudflareSettingsFor(Site $site): ?CloudflareSetting
    {
        if (filled($site->cloudflare_setting_id)) {
            $site->loadMissing('cloudflareAccount');
            $account = $site->cloudflareAccount;
            if (! $account instanceof CloudflareSetting || ! $account->is_enabled || ! $account->hasCredentials()) {
                throw new SiteProvisionException(__('cloudflare.errors.account_unavailable'));
            }

            return $account;
        }

        return CloudflareAccounts::default();
    }

    private function assertCloudflareReady(?CloudflareSetting $settings = null): void
    {
        if ($settings === null || ! $settings->hasCredentials()) {
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

        $environmentName = ChannelEnvironmentMap::canonicalizeEnvironmentName(
            $connection?->default_environment_name
                ?: (string) config('ops.provision.environment_name', 'main'),
        );

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
            dockerComposeDomains: null,
            dockerComposeLocation: CreateComposeAppRequest::DEFAULT_COMPOSE_LOCATION,
            destinationUuid: CoolifyConnection::newAppDestinationUuid($connection),
        );
    }

    /**
     * New sites get a Docker Image app (ops.deamon.provision_pack), compose only on request.
     */
    public static function provisionsDockerImage(): bool
    {
        return strtolower(trim((string) config('ops.deamon.provision_pack', 'dockerimage'))) === 'dockerimage';
    }

    /**
     * The env rows a Docker Image site app needs on top of the catalog: what the CMS
     * compose file sets as constants (a Docker Image app has no compose file).
     *
     * @return array<string, string>
     */
    public static function dockerImageEnv(): array
    {
        return array_map('strval', (array) config('ops.deamon.docker_image.env', []));
    }

    /**
     * Same app as tools/ops/deamon-image/to-dockerimage.sh creates, on the built commit.
     */
    private function createImageRequest(Site $site, ?CoolifyConnection $connection, string $builtSha): CreateDockerImageAppRequest
    {
        [$project, $server] = $this->resolveTargets($site, $connection);

        $environmentUuid = trim((string) $site->coolify_environment_uuid);
        if ($environmentUuid === '' && $connection instanceof CoolifyConnection) {
            $environmentUuid = trim((string) $connection->default_environment_uuid);
        }

        $environmentName = ChannelEnvironmentMap::canonicalizeEnvironmentName(
            $connection?->default_environment_name
                ?: (string) config('ops.provision.environment_name', 'main'),
        );
        $image = (array) config('ops.deamon.docker_image', []);
        $health = (array) ($image['health_check'] ?? []);

        return new CreateDockerImageAppRequest(
            projectUuid: $project,
            serverUuid: $server,
            imageName: (string) ($image['name'] ?? '127.0.0.1:5000/deamon-cms'),
            imageTag: $builtSha,
            domains: DockerImageLabels::hosts($site->coolifyDomainBinding()),
            environmentName: $environmentUuid === '' ? $environmentName : null,
            environmentUuid: $environmentUuid !== '' ? $environmentUuid : null,
            destinationUuid: CoolifyConnection::newImageAppDestinationUuid(),
            name: 'deamon-'.$site->slug,
            description: 'Deamon site (docker image, zero-downtime)',
            port: (int) ($image['port'] ?? 8080),
            limitsCpus: (string) ($image['limits_cpus'] ?? '1.5'),
            limitsMemory: (string) ($image['limits_memory'] ?? '768m'),
            runOptions: (string) ($image['run_options'] ?? '--pids-limit 256'),
            healthCheckPath: (string) ($health['path'] ?? '/up'),
            healthCheckInterval: (int) ($health['interval'] ?? 5),
            healthCheckTimeout: (int) ($health['timeout'] ?? 5),
            healthCheckRetries: (int) ($health['retries'] ?? 36),
            healthCheckStartPeriod: (int) ($health['start_period'] ?? 5),
        );
    }

    /**
     * What a Docker Image site app needs besides its image: the compose constants as
     * env, the three persistent volumes, and the hosts with their labels (Coolify does
     * not write labels for these apps, DockerImageLabels) plus APP_URL / DEAMON_SITE_HOST.
     * Volumes already mounted (a re-provision) are not added twice.
     */
    private function prepareDockerImageApp(Site $site, CoolifyApplicationService $coolify, CoolifyApplication $app): void
    {
        $uuid = (string) $site->coolify_app_uuid;

        $coolify->updateEnvs($uuid, self::dockerImageEnv());

        $mounted = [];
        foreach ($coolify->listStorages($uuid)->persistentStorages as $storage) {
            if (is_array($storage)) {
                $mounted[(string) ($storage['mount_path'] ?? '')] = true;
            }
        }
        foreach ((array) config('ops.deamon.docker_image.storages', []) as $name => $mountPath) {
            if (! isset($mounted[(string) $mountPath])) {
                $coolify->addPersistentStorage($uuid, (string) $name, (string) $mountPath);
            }
        }

        $binding = $site->coolifyDomainBinding();
        if ($binding !== '') {
            $coolify->setDomains($uuid, $binding, true, $app);
        }
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
