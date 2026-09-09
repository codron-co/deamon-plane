<?php

namespace App\Services\Coolify;

final class CoolifyDomainParser
{
    public const COMPOSE_SERVICE = 'app';

    /**
     * Parse live GET `docker_compose_domains` (JSON string object or array)
     * and accept the OpenAPI array shape.
     *
     * @return list<array{name: string, domain: string}>
     */
    public static function normalize(mixed $value): array
    {
        if ($value === null || $value === '' || $value === []) {
            return [];
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                return [];
            }

            if (str_starts_with($trimmed, '{') || str_starts_with($trimmed, '[')) {
                $decoded = json_decode($trimmed, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    return self::normalize($decoded);
                }
            }

            return [[
                'name' => self::COMPOSE_SERVICE,
                'domain' => self::normalizeDomainString($trimmed),
            ]];
        }

        if (! is_array($value)) {
            return [];
        }

        if (array_is_list($value)) {
            $out = [];
            foreach ($value as $item) {
                if (is_string($item)) {
                    $out[] = [
                        'name' => self::COMPOSE_SERVICE,
                        'domain' => self::normalizeDomainString($item),
                    ];

                    continue;
                }

                if (! is_array($item)) {
                    continue;
                }

                $name = (string) ($item['name'] ?? self::COMPOSE_SERVICE);
                $domain = $item['domain'] ?? null;
                if (! is_string($domain) || trim($domain) === '') {
                    continue;
                }

                $out[] = [
                    'name' => $name !== '' ? $name : self::COMPOSE_SERVICE,
                    'domain' => self::normalizeDomainString($domain),
                ];
            }

            return $out;
        }

        $out = [];
        foreach ($value as $service => $item) {
            if (is_string($item)) {
                $out[] = [
                    'name' => (string) $service,
                    'domain' => self::normalizeDomainString($item),
                ];

                continue;
            }

            if (! is_array($item)) {
                continue;
            }

            $domain = $item['domain'] ?? null;
            if (! is_string($domain) || trim($domain) === '') {
                continue;
            }

            $name = (string) ($item['name'] ?? $service);

            $out[] = [
                'name' => $name !== '' ? $name : self::COMPOSE_SERVICE,
                'domain' => self::normalizeDomainString($domain),
            ];
        }

        return $out;
    }

    /**
     * PATCH body: always the OpenAPI array, never the live JSON string.
     *
     * @param  string|array<int|string, mixed>  $fqdn
     * @return list<array{name: string, domain: string}>
     */
    public static function forPatch(string|array $fqdn): array
    {
        $normalized = self::normalize($fqdn);

        if ($normalized === []) {
            throw new CoolifyApiException('At least one domain is required to bind docker_compose_domains.', 422);
        }

        return $normalized;
    }

    public static function firstDomain(mixed $value): ?string
    {
        $normalized = self::normalize($value);
        if ($normalized === []) {
            return null;
        }

        $first = $normalized[0]['domain'];
        $hosts = array_map('trim', explode(',', $first));

        return $hosts[0] !== '' ? $hosts[0] : null;
    }

    public static function normalizeDomainString(string $domain): string
    {
        $parts = array_values(array_filter(array_map('trim', explode(',', $domain)), static fn (string $part): bool => $part !== ''));

        $normalized = array_map(static function (string $part): string {
            if (preg_match('#^https?://#i', $part) === 1) {
                return $part;
            }

            return 'https://'.$part;
        }, $parts);

        return implode(',', $normalized);
    }
}
