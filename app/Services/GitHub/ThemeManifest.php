<?php

namespace App\Services\GitHub;

final class ThemeManifest
{
    public function __construct(
        public readonly string $themeId,
        public readonly ?string $name,
        public readonly ?string $minimumDeamonVersion,
        public readonly ?string $description,
        public readonly string $path,
    ) {}

    /**
     * @param  array<string, mixed>  $json
     */
    public static function fromJson(array $json, string $fallbackThemeId, string $path): self
    {
        $id = self::stringOrNull($json['id'] ?? null) ?? $fallbackThemeId;
        $min = self::stringOrNull($json['minimum_deamon_version'] ?? $json['minimumDeamonVersion'] ?? null);

        return new self(
            themeId: $id,
            name: self::stringOrNull($json['name'] ?? null),
            minimumDeamonVersion: $min,
            description: self::stringOrNull($json['description'] ?? null),
            path: $path,
        );
    }

    private static function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
