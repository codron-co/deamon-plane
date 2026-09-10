<?php

namespace App\Services\Cloudflare;

final class CloudflareHostname
{
    public static function normalize(string $primaryDomain): string
    {
        $host = self::host($primaryDomain);

        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }

        return $host;
    }

    /**
     * Hostname only — scheme/port/path stripped. Leading www is kept.
     */
    public static function host(string $value): string
    {
        $host = strtolower(trim($value));
        $host = (string) preg_replace('#^https?://#i', '', $host);
        $host = explode('/', $host)[0] ?? '';
        $host = explode(':', $host)[0] ?? '';

        return rtrim($host, '.');
    }

    public static function wwwHost(string $host): string
    {
        $apex = self::normalize($host);

        return $apex === '' ? '' : 'www.'.$apex;
    }

    public static function sameRegistrableApex(string $left, string $right): bool
    {
        $a = self::apex($left);
        $b = self::apex($right);

        return $a !== '' && strcasecmp($a, $b) === 0;
    }

    public static function zoneIsReady(?string $status): bool
    {
        $status = strtolower(trim((string) $status));

        return $status === '' || $status === 'active';
    }

    /**
     * Longest hostname first, down to the registrable 2-label apex. Never a TLD.
     *
     * @return list<string>
     */
    public static function zoneCandidates(string $host): array
    {
        $host = self::normalize($host);
        if ($host === '' || ! str_contains($host, '.')) {
            return [];
        }

        $labels = explode('.', $host);
        $candidates = [];

        while (count($labels) >= 2) {
            $candidates[] = implode('.', $labels);
            array_shift($labels);
        }

        return $candidates;
    }

    public static function isApex(string $host): bool
    {
        return count(explode('.', self::normalize($host))) === 2;
    }

    /**
     * Registrable 2-label suffix used when Plane must create a customer zone.
     */
    public static function apex(string $host): string
    {
        $candidates = self::zoneCandidates($host);

        return $candidates === [] ? '' : (string) $candidates[array_key_last($candidates)];
    }

    /**
     * Cloudflare * covers exactly one label (foo.zone), not a.b.zone.
     */
    public static function starCovers(string $relative): bool
    {
        return $relative !== '' && $relative !== '@' && ! str_contains($relative, '.');
    }
}
