<?php

namespace App\Services\Coolify\Dto;

final class CoolifyServer
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly string $uuid,
        public readonly string $name,
        public readonly ?string $ip = null,
        public readonly array $raw = [],
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $public = trim((string) ($payload['public_ip'] ?? $payload['public'] ?? ''));
        $ip = trim((string) ($payload['ip'] ?? ''));
        $resolved = $public !== '' ? $public : $ip;

        return new self(
            uuid: (string) ($payload['uuid'] ?? ''),
            name: (string) ($payload['name'] ?? ''),
            ip: $resolved !== '' ? $resolved : null,
            raw: $payload,
        );
    }
}
