<?php

namespace App\Services\Coolify\Dto;

final class CoolifyEnvironmentVariable
{
    public function __construct(
        public readonly string $key,
        public readonly ?string $uuid = null,
        private readonly ?string $value = null,
        public readonly bool $isPreview = false,
        public readonly bool $isLiteral = false,
        public readonly ?string $serviceName = null,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $value = $payload['value'] ?? null;

        $service = $payload['available_in_services'] ?? $payload['service_name'] ?? $payload['compose_service'] ?? null;

        return new self(
            key: (string) ($payload['key'] ?? ''),
            uuid: isset($payload['uuid']) ? (string) $payload['uuid'] : null,
            value: is_string($value) ? $value : null,
            isPreview: (bool) ($payload['is_preview'] ?? false),
            isLiteral: (bool) ($payload['is_literal'] ?? $payload['is_literally'] ?? false),
            serviceName: is_string($service) && $service !== '' ? $service : null,
        );
    }

    public function value(): ?string
    {
        return $this->value;
    }

    /**
     * @return array{key: string, uuid: string|null, value: string|null, isPreview: bool, isLiteral: bool}
     */
    public function __debugInfo(): array
    {
        return [
            'key' => $this->key,
            'uuid' => $this->uuid,
            'value' => $this->value !== null ? '[redacted]' : null,
            'isPreview' => $this->isPreview,
            'isLiteral' => $this->isLiteral,
        ];
    }
}
