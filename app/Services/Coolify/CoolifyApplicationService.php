<?php

namespace App\Services\Coolify;

use App\Enums\Channel;
use App\Services\Coolify\Dto\CoolifyApplication;
use App\Services\Coolify\Dto\CoolifyDeployment;
use App\Services\Coolify\Dto\CoolifyDeployResult;
use App\Services\Coolify\Dto\CoolifyEnvironmentVariable;
use App\Services\Coolify\Dto\CoolifyProject;
use App\Services\Coolify\Dto\CoolifyServer;
use App\Services\Coolify\Dto\CreateComposeAppRequest;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Thin wrappers over CoolifyClient for list / create / env / domain / branch / deploy.
 * Channel switch uses updateBranch + deploy only — never DELETE the application.
 */
class CoolifyApplicationService
{
    public function __construct(
        private readonly CoolifyClient $client,
    ) {}

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

    public function createComposeApp(CreateComposeAppRequest $request): CoolifyApplication
    {
        $this->assertChannel($request->gitBranch);

        $defaults = $request;
        $compose = $defaults->dockerComposeLocation !== ''
            ? $defaults->dockerComposeLocation
            : (string) config('ops.deamon.compose_file', 'docker-compose.coolify.yml');

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

    /**
     * @param  string|array<int|string, mixed>  $fqdn
     */
    public function setDomains(string $uuid, string|array $fqdn, bool $forceDomainOverride = false): CoolifyApplication
    {
        return $this->client->setDomains($uuid, $fqdn, $forceDomainOverride);
    }

    public function updateBranch(string $uuid, string $branch, bool $skipAllowlist = false): CoolifyApplication
    {
        if (! $skipAllowlist) {
            $this->assertChannel($branch);
        }

        return $this->client->updateBranch($uuid, $branch);
    }

    public function deploy(string $uuid, bool $force = false): CoolifyDeployResult
    {
        return $this->client->deploy($uuid, $force);
    }

    public function getDeployment(string $deploymentUuid): CoolifyDeployment
    {
        return $this->client->getDeployment($deploymentUuid);
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
