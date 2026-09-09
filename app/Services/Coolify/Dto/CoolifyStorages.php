<?php

namespace App\Services\Coolify\Dto;

final class CoolifyStorages
{
    /**
     * @param  list<array<string, mixed>>  $persistentStorages
     * @param  list<array<string, mixed>>  $fileStorages
     */
    public function __construct(
        public readonly array $persistentStorages,
        public readonly array $fileStorages,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $persistent = $payload['persistent_storages'] ?? [];
        $files = $payload['file_storages'] ?? [];

        return new self(
            persistentStorages: is_array($persistent) ? array_values($persistent) : [],
            fileStorages: is_array($files) ? array_values($files) : [],
        );
    }

    /**
     * @return list<string>
     */
    public function persistentNames(): array
    {
        $names = [];
        foreach ($this->persistentStorages as $storage) {
            if (is_array($storage) && isset($storage['name']) && is_string($storage['name'])) {
                $names[] = $storage['name'];
            }
        }

        return $names;
    }
}
