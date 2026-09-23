<?php

namespace App\Services\GitHub;

final class ThemeManifest
{
    private const MAX_SMOKE_PATHS = 10;

    /**
     * @param  list<string>  $smokePaths
     */
    public function __construct(
        public readonly string $themeId,
        public readonly ?string $name,
        public readonly ?string $minimumDeamonVersion,
        public readonly ?string $description,
        public readonly string $path,
        public readonly array $smokePaths = [],
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
            smokePaths: self::smokePaths($json['smoke_paths'] ?? $json['smokePaths'] ?? null),
        );
    }

    /**
     * Storefront paths only ("/timeline", "/hizmetler?x=1"): no scheme, host or
     * traversal, so a manifest can never point Plane's probe at another origin.
     *
     * @return list<string>
     */
    public static function smokePaths(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $paths = [];
        foreach ($value as $path) {
            if (! is_string($path)) {
                continue;
            }

            $path = trim($path);
            if (preg_match('#^/(?!/)[A-Za-z0-9\-._~/%?=&]*$#', $path) !== 1 || str_contains($path, '..')) {
                continue;
            }

            $paths[$path] = true;
            if (count($paths) >= self::MAX_SMOKE_PATHS) {
                break;
            }
        }

        return array_keys($paths);
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
