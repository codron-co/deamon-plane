<?php

namespace App\Services\Coolify\EnvCatalog;

use App\Enums\CoolifyEnvKind;

/**
 * One catalog row parsed from the CMS `.env.production.example`.
 */
final class ParsedEnvRow
{
    public function __construct(
        public readonly string $key,
        public readonly CoolifyEnvKind $kind,
        public readonly ?string $value,
        public readonly bool $isSecret,
        public readonly ?string $description,
    ) {}

    /**
     * @return array{key: string, kind: string, value: ?string, is_secret: bool, description: ?string}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'kind' => $this->kind->value,
            'value' => $this->value,
            'is_secret' => $this->isSecret,
            'description' => $this->description,
        ];
    }
}
