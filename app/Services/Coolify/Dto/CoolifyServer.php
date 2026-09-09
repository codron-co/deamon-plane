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
        public readonly array $raw = [],
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            uuid: (string) ($payload['uuid'] ?? ''),
            name: (string) ($payload['name'] ?? ''),
            raw: $payload,
        );
    }
}
