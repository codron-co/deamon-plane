<?php

namespace App\Services\Coolify\Dto;

final class CoolifyDeployment
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly string $uuid,
        public readonly ?string $status,
        public readonly ?string $commit,
        public readonly ?string $applicationUuid,
        public readonly array $raw,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $uuid = (string) ($payload['uuid'] ?? $payload['deployment_uuid'] ?? '');
        $raw = $payload;
        unset($raw['logs'], $raw['output']);

        return new self(
            uuid: $uuid,
            status: isset($payload['status']) ? (string) $payload['status'] : null,
            commit: isset($payload['commit']) ? (string) $payload['commit'] : null,
            applicationUuid: isset($payload['application_id'])
                ? (string) $payload['application_id']
                : (isset($payload['application_uuid']) ? (string) $payload['application_uuid'] : null),
            raw: $raw,
        );
    }
}
