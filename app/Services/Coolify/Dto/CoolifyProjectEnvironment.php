<?php

namespace App\Services\Coolify\Dto;

final class CoolifyProjectEnvironment
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly string $uuid,
        public readonly string $name,
        public readonly ?string $projectUuid = null,
        public readonly array $raw = [],
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload, ?string $projectUuid = null): self
    {
        $uuid = (string) ($payload['uuid'] ?? $payload['id'] ?? $payload['name'] ?? '');
        $name = (string) ($payload['name'] ?? $uuid);

        return new self(
            uuid: $uuid,
            name: $name,
            projectUuid: $projectUuid ?? self::nullableString($payload['project_uuid'] ?? null),
            raw: $payload,
        );
    }

    private static function nullableString(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }
}
