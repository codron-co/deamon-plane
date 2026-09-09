<?php

namespace App\Services\Coolify\Dto;

use App\Services\Coolify\CoolifyDomainParser;
use InvalidArgumentException;

final class CreateComposeAppRequest
{
    public const DEFAULT_COMPOSE_LOCATION = '/docker-compose.coolify.yml';

    /**
     * @param  string|list<array{name?: string, domain?: string}>|array<string, mixed>|null  $dockerComposeDomains
     */
    public function __construct(
        public readonly string $projectUuid,
        public readonly string $serverUuid,
        public readonly string $gitRepository,
        public readonly string $gitBranch,
        public readonly ?string $environmentName = 'production',
        public readonly ?string $environmentUuid = null,
        public readonly ?string $githubAppUuid = null,
        public readonly ?string $privateKeyUuid = null,
        public readonly ?string $name = null,
        public readonly bool $instantDeploy = false,
        public readonly string|array|null $dockerComposeDomains = null,
        public readonly string $dockerComposeLocation = self::DEFAULT_COMPOSE_LOCATION,
    ) {
        if ($this->projectUuid === '' || $this->serverUuid === '') {
            throw new InvalidArgumentException('project_uuid and server_uuid are required to create a Coolify compose app.');
        }

        if ($this->gitRepository === '' || $this->gitBranch === '') {
            throw new InvalidArgumentException('git_repository and git_branch are required to create a Coolify compose app.');
        }

        if (($this->environmentName === null || $this->environmentName === '') && ($this->environmentUuid === null || $this->environmentUuid === '')) {
            throw new InvalidArgumentException('environment_name or environment_uuid is required to create a Coolify compose app.');
        }
    }

    /**
     * Git + dockercompose only. Never POST /applications/dockercompose (raw YAML, no git).
     */
    public function endpoint(): string
    {
        if (filled($this->githubAppUuid)) {
            return '/applications/private-github-app';
        }

        if (filled($this->privateKeyUuid)) {
            return '/applications/private-deploy-key';
        }

        return '/applications/public';
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        $payload = [
            'project_uuid' => $this->projectUuid,
            'server_uuid' => $this->serverUuid,
            'git_repository' => $this->gitRepository,
            'git_branch' => $this->gitBranch,
            'build_pack' => 'dockercompose',
            'docker_compose_location' => self::normalizeLocation($this->dockerComposeLocation),
        ];

        if (filled($this->environmentUuid)) {
            $payload['environment_uuid'] = $this->environmentUuid;
        } else {
            $payload['environment_name'] = $this->environmentName;
        }

        if (filled($this->githubAppUuid)) {
            $payload['github_app_uuid'] = $this->githubAppUuid;
        }

        if (filled($this->privateKeyUuid)) {
            $payload['private_key_uuid'] = $this->privateKeyUuid;
        }

        if (filled($this->name)) {
            $payload['name'] = $this->name;
        }

        if ($this->instantDeploy) {
            $payload['instant_deploy'] = true;
        }

        if ($this->dockerComposeDomains !== null && $this->dockerComposeDomains !== '' && $this->dockerComposeDomains !== []) {
            $payload['docker_compose_domains'] = CoolifyDomainParser::forPatch($this->dockerComposeDomains);
            $fqdn = CoolifyDomainParser::firstDomain($payload['docker_compose_domains']);
            if ($fqdn !== null) {
                $payload['fqdn'] = $fqdn;
            }
        }

        return $payload;
    }

    /**
     * Coolify validates docker_compose_location as a rooted path (leading slash).
     */
    public static function normalizeLocation(string $location): string
    {
        $location = trim($location);
        if ($location === '') {
            return self::DEFAULT_COMPOSE_LOCATION;
        }

        return str_starts_with($location, '/') ? $location : '/'.$location;
    }
}
