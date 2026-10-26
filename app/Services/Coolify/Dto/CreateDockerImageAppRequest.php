<?php

namespace App\Services\Coolify\Dto;

use App\Services\Coolify\DockerImageLabels;
use InvalidArgumentException;

/**
 * POST /applications/dockerimage for a Deamon site: the same app
 * tools/ops/deamon-image/to-dockerimage.sh creates (registry image pinned to a built
 * commit, health check on /up, auto-deploy off). Env, volumes and labels follow in
 * their own calls (SiteProvisioner); the labels need the app uuid.
 */
final class CreateDockerImageAppRequest
{
    /**
     * @param  list<string>  $domains  hosts or URLs, primary first
     */
    public function __construct(
        public readonly string $projectUuid,
        public readonly string $serverUuid,
        public readonly string $imageName,
        public readonly string $imageTag,
        public readonly array $domains,
        public readonly ?string $environmentName = 'main',
        public readonly ?string $environmentUuid = null,
        public readonly ?string $destinationUuid = null,
        public readonly ?string $name = null,
        public readonly ?string $description = null,
        public readonly int $port = 8080,
        public readonly string $limitsCpus = '2',
        public readonly string $limitsMemory = '1024m',
        public readonly string $runOptions = '--pids-limit 256',
        public readonly string $healthCheckPath = '/up',
        public readonly int $healthCheckInterval = 5,
        public readonly int $healthCheckTimeout = 5,
        public readonly int $healthCheckRetries = 36,
        public readonly int $healthCheckStartPeriod = 5,
    ) {
        if ($this->projectUuid === '' || $this->serverUuid === '') {
            throw new InvalidArgumentException('project_uuid and server_uuid are required to create a Coolify Docker Image app.');
        }

        if ($this->imageName === '' || preg_match('/^[0-9a-f]{40}$/', $this->imageTag) !== 1) {
            throw new InvalidArgumentException('A registry image and a built commit tag are required to create a Coolify Docker Image app.');
        }

        if (($this->environmentName === null || $this->environmentName === '') && ($this->environmentUuid === null || $this->environmentUuid === '')) {
            throw new InvalidArgumentException('environment_name or environment_uuid is required to create a Coolify Docker Image app.');
        }
    }

    public function endpoint(): string
    {
        return '/applications/dockerimage';
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        $payload = [
            'project_uuid' => $this->projectUuid,
            'server_uuid' => $this->serverUuid,
        ];

        if (filled($this->environmentUuid)) {
            $payload['environment_uuid'] = $this->environmentUuid;
        } else {
            $payload['environment_name'] = $this->environmentName;
        }

        if (filled($this->destinationUuid)) {
            $payload['destination_uuid'] = $this->destinationUuid;
        }

        if (filled($this->name)) {
            $payload['name'] = $this->name;
        }

        if (filled($this->description)) {
            $payload['description'] = $this->description;
        }

        $payload += [
            'docker_registry_image_name' => $this->imageName,
            'docker_registry_image_tag' => $this->imageTag,
            'ports_exposes' => (string) $this->port,
        ];

        // force: a stopped compose app of the same site may still hold the hosts.
        $domains = DockerImageLabels::domainsValue($this->domains);
        if ($domains !== '') {
            $payload['domains'] = $domains;
            $payload['force_domain_override'] = true;
        }

        return $payload + [
            'limits_cpus' => $this->limitsCpus,
            'limits_memory' => $this->limitsMemory,
            'custom_docker_run_options' => $this->runOptions,
            'health_check_enabled' => true,
            'health_check_path' => $this->healthCheckPath,
            'health_check_port' => (string) $this->port,
            'health_check_interval' => $this->healthCheckInterval,
            'health_check_timeout' => $this->healthCheckTimeout,
            'health_check_retries' => $this->healthCheckRetries,
            'health_check_start_period' => $this->healthCheckStartPeriod,
            'is_auto_deploy_enabled' => false,
            'autogenerate_domain' => false,
            'instant_deploy' => false,
        ];
    }
}
