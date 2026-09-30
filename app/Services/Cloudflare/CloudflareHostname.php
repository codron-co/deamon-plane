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
     * Multi-label public suffixes a customer can register under. Cloudflare rejects a
     * zone named after one of these ("provide the root domain and not a TLD"), so the
     * registrable apex of `firma.com.tr` is `firma.com.tr`, not `com.tr`. Not the full
     * Public Suffix List: the .tr second levels plus the common foreign ones.
     *
     * @var list<string>
     */
    public const MULTI_LABEL_SUFFIXES = [
        // .tr (TRABIS)
        'com.tr', 'net.tr', 'org.tr', 'gen.tr', 'web.tr', 'biz.tr', 'info.tr', 'name.tr',
        'tel.tr', 'av.tr', 'dr.tr', 'bbs.tr', 'bel.tr', 'pol.tr', 'kep.tr', 'k12.tr',
        'edu.tr', 'gov.tr', 'mil.tr', 'tsk.tr', 'nc.tr', 'tv.tr',
        // Common elsewhere
        'co.uk', 'org.uk', 'me.uk', 'ltd.uk', 'plc.uk', 'ac.uk', 'gov.uk', 'net.uk',
        'com.au', 'net.au', 'org.au', 'co.nz', 'org.nz', 'co.za', 'com.br', 'com.cy',
        'com.mx', 'co.jp', 'co.kr', 'com.cn', 'com.hk', 'com.sg', 'co.in', 'com.ua',
        'com.az', 'com.ge', 'co.il', 'com.sa', 'com.qa', 'com.eg',
    ];

    /**
     * Longest hostname first, down to the registrable apex. Never a TLD or a public
     * suffix such as `com.tr`.
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
        $minimum = self::suffixLabelCount($host) + 1;
        $candidates = [];

        while (count($labels) >= $minimum) {
            $candidates[] = implode('.', $labels);
            array_shift($labels);
        }

        return $candidates;
    }

    public static function isApex(string $host): bool
    {
        $normalized = self::normalize($host);

        return $normalized !== '' && $normalized === self::apex($normalized);
    }

    /**
     * Registrable apex (one label above the public suffix) used when Plane must
     * create a customer zone. Empty when the host is itself a suffix (`com.tr`).
     */
    public static function apex(string $host): string
    {
        $candidates = self::zoneCandidates($host);

        return $candidates === [] ? '' : (string) $candidates[array_key_last($candidates)];
    }

    public static function isPublicSuffix(string $host): bool
    {
        $host = ltrim(self::normalize($host), '.');

        return $host !== '' && (in_array($host, self::MULTI_LABEL_SUFFIXES, true) || ! str_contains($host, '.'));
    }

    private static function suffixLabelCount(string $host): int
    {
        foreach (self::MULTI_LABEL_SUFFIXES as $suffix) {
            if ($host === $suffix || str_ends_with($host, '.'.$suffix)) {
                return substr_count($suffix, '.') + 1;
            }
        }

        return 1;
    }

    /**
     * Cloudflare * covers exactly one label (foo.zone), not a.b.zone.
     */
    public static function starCovers(string $relative): bool
    {
        return $relative !== '' && $relative !== '@' && ! str_contains($relative, '.');
    }
}
