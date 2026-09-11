<?php

namespace App\Services\Coolify\Dto;

final class CoolifyDeployResult
{
    /**
     * @param  list<array{resource_uuid: string, deployment_uuid: string, message: string|null}>  $deployments
     */
    public function __construct(
        public readonly array $deployments,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        if (isset($payload['deployment_uuid']) && ! isset($payload['deployments'])) {
            $payload = ['deployments' => [$payload]];
        }

        $rows = $payload['deployments'] ?? $payload;
        if (! is_array($rows)) {
            $rows = [];
        }

        $deployments = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $deploymentUuid = (string) ($row['deployment_uuid'] ?? $row['uuid'] ?? '');
            if ($deploymentUuid === '') {
                continue;
            }

            $deployments[] = [
                'resource_uuid' => (string) ($row['resource_uuid'] ?? ''),
                'deployment_uuid' => $deploymentUuid,
                'message' => isset($row['message']) ? (string) $row['message'] : null,
            ];
        }

        return new self($deployments);
    }

    public function firstDeploymentUuid(): ?string
    {
        return $this->deployments[0]['deployment_uuid'] ?? null;
    }
}
