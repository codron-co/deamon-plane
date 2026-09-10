<?php

namespace App\Services\Cloudflare;

final class CloudflareHostname
{
    public static function normalize(string $primaryDomain): string
    {
        $host = strtolower(trim($primaryDomain));
        $host = (string) preg_replace('#^https?://#i', '', $host);
        $host = explode('/', $host)[0] ?? '';
        $host = explode(':', $host)[0] ?? '';
        $host = rtrim($host, '.');

        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }

        return $host;
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
     * Cloudflare * covers exactly one label (foo.zone), not a.b.zone.
     */
    public static function starCovers(string $relative): bool
    {
        return $relative !== '' && $relative !== '@' && ! str_contains($relative, '.');
    }
}
