<?php

namespace App\Services\Coolify;

use App\Enums\Channel;
use App\Models\CoolifyConnection;
use App\Models\Site;
use App\Services\Coolify\Dto\CoolifyApplication;
use App\Services\Coolify\Dto\CoolifyDeployment;
use App\Services\Coolify\Dto\CoolifyDeployResult;
use App\Services\Coolify\Dto\CoolifyEnvironmentVariable;
use App\Services\Coolify\Dto\CoolifyGitSource;
use App\Services\Coolify\Dto\CoolifyProject;
use App\Services\Coolify\Dto\CoolifyProjectEnvironment;
use App\Services\Coolify\Dto\CoolifyServer;
use App\Services\Coolify\Dto\CoolifyStorages;
use App\Services\Coolify\Dto\CreateComposeAppRequest;
use App\Services\Coolify\Dto\CreateDockerImageAppRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Thin wrappers over CoolifyClient for list / create / env / domain / branch / deploy.
 * Channel switch PATCHes git_branch + APP_ENV / DEAMON_CHANNEL, then deploys.
 * `deploy()` syncs Coolify env against Settings catalogs for Plane sites first.
 * Never DELETE the application except explicit hard-delete (`deleteApplication` with delete_volumes).
 */
class CoolifyApplicationService
{
    public function __construct(
        private readonly CoolifyClient $client,
    ) {}

    public static function forConnection(CoolifyConnection $connection): self
    {
        return new self(new CoolifyClient($connection->credentials()));
    }

    public static function forSite(Site $site): self
    {
        $site->loadMissing('coolifyConnection');
        if ($site->coolifyConnection instanceof CoolifyConnection) {
            return self::forConnection($site->coolifyConnection);
        }

        return new self(new CoolifyClient(CoolifyCredentials::resolve()));
    }

    public function client(): CoolifyClient
    {
        return $this->client;
    }

    /**
     * @return Collection<int, CoolifyApplication>
     */
    public function listApps(?string $tag = null): Collection
    {
        return $this->client->listApps($tag);
    }

    public function getApp(string $uuid): CoolifyApplication
    {
        return $this->client->getApp($uuid);
    }

    /**
     * @return Collection<int, CoolifyServer>
     */
    public function listServers(): Collection
    {
        return $this->client->listServers();
    }

    /**
     * @return Collection<int, CoolifyProject>
     */
    public function listProjects(): Collection
    {
        return $this->client->listProjects();
    }

    /**
     * @return Collection<int, CoolifyProjectEnvironment>
     */
    public function listEnvironments(string $projectUuid): Collection
    {
        return $this->client->listEnvironments($projectUuid);
    }

    /**
     * @return Collection<int, CoolifyGitSource>|null
     */
    public function listGithubApps(): ?Collection
    {
        return $this->client->listGithubApps();
    }

    /**
     * @return Collection<int, CoolifyGitSource>
     */
    public function listPrivateKeys(): Collection
    {
        return $this->client->listPrivateKeys();
    }

    public function createComposeApp(CreateComposeAppRequest $request): CoolifyApplication
    {
        $this->assertChannel($request->gitBranch);

        $defaults = $request;
        $compose = $defaults->dockerComposeLocation !== ''
            ? $defaults->dockerComposeLocation
            : (string) config('ops.deamon.compose_file', CreateComposeAppRequest::DEFAULT_COMPOSE_LOCATION);

        if ($compose !== $defaults->dockerComposeLocation) {
            $defaults = new CreateComposeAppRequest(
                projectUuid: $request->projectUuid,
                serverUuid: $request->serverUuid,
                gitRepository: $request->gitRepository,
                gitBranch: $request->gitBranch,
                environmentName: $request->environmentName,
                environmentUuid: $request->environmentUuid,
                githubAppUuid: $request->githubAppUuid,
                privateKeyUuid: $request->privateKeyUuid,
                name: $request->name,
                instantDeploy: $request->instantDeploy,
                dockerComposeDomains: $request->dockerComposeDomains,
                dockerComposeLocation: $compose,
                destinationUuid: $request->destinationUuid,
            );
        }

        return $this->client->createComposeApp($defaults);
    }

    /**
     * @param  array<string, string>|list<array{key: string, value: string}>  $pairs
     * @return Collection<int, CoolifyEnvironmentVariable>
     */
    public function updateEnvs(string $uuid, array $pairs): Collection
    {
        return $this->client->updateEnvs($uuid, $pairs);
    }

    /**
     * @return Collection<int, CoolifyEnvironmentVariable>
     */
    public function listEnvs(string $uuid): Collection
    {
        return $this->client->listEnvs($uuid);
    }

    public function deleteEnv(string $uuid, string $envUuid): void
    {
        $this->client->deleteEnv($uuid, $envUuid);
    }

    public function createDockerImageApp(CreateDockerImageAppRequest $request): CoolifyApplication
    {
        return $this->client->createDockerImageApp($request);
    }

    public function addPersistentStorage(string $uuid, string $name, string $mountPath): void
    {
        $this->client->addPersistentStorage($uuid, $name, $mountPath);
    }

    public function listStorages(string $uuid): CoolifyStorages
    {
        return $this->client->listStorages($uuid);
    }

    /**
     * Bind hosts the way the app's build pack routes them. Compose: the `app` service's
     * `docker_compose_domains`. Docker Image: `domains`, the container labels
     * (DockerImageLabels, Coolify leaves them alone) and APP_URL / DEAMON_SITE_HOST,
     * which the compose file used to derive from the domains. Either way Coolify applies
     * it at the next deploy. `$app` is the app when the caller already read it; else
     * one GET first. An unreadable app gets the compose PATCH, as before (on a Docker
     * Image app that changes nothing, it never breaks one).
     *
     * @param  string|array<int|string, mixed>  $fqdn
     */
    public function setDomains(string $uuid, string|array $fqdn, bool $forceDomainOverride = false, ?CoolifyApplication $app = null): CoolifyApplication
    {
        if (! $app instanceof CoolifyApplication) {
            try {
                $app = $this->client->getApp($uuid);
            } catch (CoolifyApiException $exception) {
                Log::warning('coolify.set_domains_pack_unknown', ['app_uuid' => $uuid, 'status' => $exception->status]);
            }
        }

        if (! $app?->isDockerImagePack()) {
            return $this->client->setDomains($uuid, $fqdn, $forceDomainOverride);
        }

        $hosts = DockerImageLabels::hosts(array_column(CoolifyDomainParser::normalize($fqdn), 'domain'));
        if ($hosts === []) {
            throw new CoolifyApiException('At least one domain is required to bind a Docker Image app.', 422);
        }

        // Always forced: the site's stopped compose app (kept until deleted) still holds the hosts.
        $patched = $this->client->setImageDomains($uuid, $hosts, DockerImageLabels::encodedForSite($uuid, $hosts));
        $this->client->updateEnvs($uuid, self::imageUrlEnv($hosts[0]));

        return $patched;
    }

    /**
     * The env a Docker Image app needs for its primary host (a compose app gets the
     * same from SERVICE_URL_APP / SERVICE_FQDN_APP).
     *
     * @return array{APP_URL: string, DEAMON_SITE_HOST: string}
     */
    public static function imageUrlEnv(string $host): array
    {
        return ['APP_URL' => 'https://'.$host, 'DEAMON_SITE_HOST' => $host];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    public function patchApplication(string $uuid, array $body): CoolifyApplication
    {
        return $this->client->patchApplication($uuid, $body);
    }

    public function upsertEnvOnService(string $uuid, string $key, string $value, string $service = 'app'): CoolifyEnvironmentVariable
    {
        return $this->client->upsertEnvOnService($uuid, $key, $value, $service);
    }

    public function updateBranch(string $uuid, string $branch, bool $skipAllowlist = false): CoolifyApplication
    {
        if (! $skipAllowlist) {
            $this->assertChannel($branch);
        }

        return $this->client->updateBranch($uuid, $branch);
    }

    public function startApplication(string $uuid, bool $force = false, bool $instantDeploy = false): CoolifyDeployResult
    {
        return $this->client->startApplication($uuid, $force, $instantDeploy);
    }

    public function stopApplication(string $uuid): void
    {
        $this->client->stopApplication($uuid);
    }

    public function restartApplication(string $uuid): void
    {
        $this->client->restartApplication($uuid);
    }

    public function getApplicationLogs(string $uuid, int $lines = 200): string
    {
        return $this->client->getApplicationLogs($uuid, $lines);
    }

    public function cancelDeployment(string $deploymentUuid): void
    {
        $this->client->cancelDeployment($deploymentUuid);
    }

    /**
     * @return Collection<int, CoolifyDeployment>
     */
    public function listRunningDeployments(): Collection
    {
        return $this->client->listRunningDeployments();
    }

    /**
     * Purge only. Channel switch, pack migrate, and provision retry must never call this.
     */
    public function deleteApplication(string $uuid, bool $deleteVolumes): void
    {
        $this->client->deleteApplication($uuid, $deleteVolumes);
    }

    public function deploy(string $uuid, bool $force = false): CoolifyDeployResult
    {
        $this->assertDeployGate($uuid);
        $this->syncSiteEnv($uuid);
        $this->bindComposeDomainsIfMissing($uuid);

        return $this->client->deploy($uuid, $force);
    }

    /**
     * A compose app only gets Traefik labels from `docker_compose_domains`. Apps whose
     * hosts were left on the Dockerfile-era `fqdn` deploy healthy but unrouted, and the
     * proxy serves the "domain is ready" fallback (doremanmedya, lokcetekstil, 2026-09).
     * Bind them before the deploy so the labels are generated; never block the deploy.
     */
    private function bindComposeDomainsIfMissing(string $uuid): void
    {
        $site = $this->siteForAppUuid($uuid);
        if (! $site instanceof Site) {
            return;
        }

        try {
            $app = $this->client->getApp($uuid);
            if (! $app->isComposePack() || $app->composeDomains !== []) {
                return;
            }

            $fqdn = trim((string) ($app->raw['fqdn'] ?? ''));
            $domains = $fqdn !== '' ? $fqdn : implode(',', $site->operatorHosts());
            if ($domains === '') {
                return;
            }

            $this->client->setDomains($uuid, $domains);
            Log::warning('coolify.compose_domains_bound', [
                'site_id' => $site->id,
                'site_slug' => $site->slug,
                'source' => $fqdn !== '' ? 'fqdn' : 'site_domains',
            ]);
        } catch (CoolifyApiException $exception) {
            Log::warning('coolify.compose_domains_bind_failed', [
                'site_id' => $site->id,
                'site_slug' => $site->slug,
                'status' => $exception->status,
            ]);
        }
    }

    /**
     * Every Plane-triggered deploy checks Coolify env against Settings catalogs.
     * Apps that are not Plane sites (this Plane itself) are skipped.
     */
    private function syncSiteEnv(string $uuid): void
    {
        $site = $this->siteForAppUuid($uuid);
        if (! $site instanceof Site) {
            return;
        }

        app(CoolifyAppEnvSync::class)->sync($site, $this);
    }

    private function assertDeployGate(string $uuid): void
    {
        $site = $this->siteForAppUuid($uuid);
        if (! $site instanceof Site) {
            return;
        }

        app(CoolifyDeployGate::class)->assertCanStartDeploy($site);
    }

    private function siteForAppUuid(string $uuid): ?Site
    {
        $site = Site::query()->where('coolify_app_uuid', $uuid)->first();

        return $site instanceof Site ? $site : null;
    }

    public function getDeployment(string $deploymentUuid): CoolifyDeployment
    {
        return $this->client->getDeployment($deploymentUuid);
    }

    /**
     * @return Collection<int, CoolifyDeployment>
     */
    public function listAppDeployments(string $appUuid, ?int $skip = null, ?int $take = null): Collection
    {
        return $this->client->listAppDeployments($appUuid, $skip, $take);
    }

    private function assertChannel(string $branch): void
    {
        $channel = Channel::tryFrom($branch);
        if ($channel === null) {
            throw new InvalidArgumentException("Channel [{$branch}] is not in the allowlist.");
        }

        Channel::assertAllowed($channel);
    }
}
