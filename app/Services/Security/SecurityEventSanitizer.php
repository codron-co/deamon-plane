<?php

namespace App\Services\Security;

use App\Models\SiteSecurityEvent;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * The CMS is trusted to sign, not to send clean data: every field of an event and
 * of the counters block is bounded, typed and stripped before it reaches the DB.
 * Query strings are cut from paths (tokens travel there), nothing else is kept.
 */
final class SecurityEventSanitizer
{
    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>|null null = drop the event
     */
    public static function event(array $raw): ?array
    {
        $uid = is_string($raw['uid'] ?? null) || is_int($raw['uid'] ?? null) ? trim((string) $raw['uid']) : '';
        if ($uid === '' || strlen($uid) > 64 || preg_match('/\A[A-Za-z0-9._:-]+\z/', $uid) !== 1) {
            return null;
        }

        $ip = is_string($raw['ip'] ?? null) ? trim($raw['ip']) : '';
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        try {
            $at = CarbonImmutable::parse((string) ($raw['occurred_at'] ?? ''))->setTimezone((string) config('app.timezone'));
        } catch (Throwable) {
            return null;
        }
        if ($at->greaterThan(now()->addMinutes(10))) {
            return null;
        }

        $rule = self::slug($raw['rule'] ?? null, 60);
        if ($rule === '') {
            return null;
        }

        $country = strtoupper(trim((string) ($raw['country'] ?? '')));
        $status = (int) ($raw['status'] ?? 0);
        $method = strtoupper(preg_replace('/[^A-Za-z]/', '', (string) ($raw['method'] ?? '')) ?? '');

        return [
            'event_uid' => $uid,
            'occurred_at' => $at->format('Y-m-d H:i:s'),
            'type' => self::slug($raw['type'] ?? null, 40) ?: 'unknown',
            'severity' => max(SiteSecurityEvent::SEVERITY_INFO, min(SiteSecurityEvent::SEVERITY_CRITICAL, (int) ($raw['severity'] ?? 0))),
            'rule' => $rule,
            'ip' => $ip,
            'country' => preg_match('/\A[A-Z]{2}\z/', $country) === 1 ? $country : null,
            'user_agent' => self::text($raw['user_agent'] ?? null, 255),
            'method' => $method !== '' ? substr($method, 0, 10) : null,
            'path' => self::path($raw['path'] ?? null),
            'status' => $status >= 100 && $status <= 599 ? $status : null,
            'count' => max(1, min(1_000_000, (int) ($raw['count'] ?? 1))),
        ];
    }

    /**
     * The counters block a site sends next to its events. Anything unexpected is dropped.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function summary(array $payload): array
    {
        $counts = [];
        foreach (['24h', '7d'] as $window) {
            $row = is_array($payload['counts'][$window] ?? null) ? $payload['counts'][$window] : [];
            $bySeverity = [];
            foreach (['0', '1', '2'] as $level) {
                $bySeverity[$level] = max(0, (int) ($row['by_severity'][$level] ?? 0));
            }
            $byType = [];
            foreach ((array) ($row['by_type'] ?? []) as $type => $count) {
                $key = self::slug($type, 40);
                if ($key !== '' && count($byType) < 20) {
                    $byType[$key] = max(0, (int) $count);
                }
            }
            $counts[$window] = [
                'total' => max(0, (int) ($row['total'] ?? array_sum($bySeverity))),
                'by_severity' => $bySeverity,
                'by_type' => $byType,
            ];
        }

        $topIps = [];
        foreach (array_slice((array) ($payload['top_ips'] ?? []), 0, 10) as $row) {
            $ip = is_array($row) && is_string($row['ip'] ?? null) ? trim($row['ip']) : '';
            if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
                continue;
            }
            $topIps[] = [
                'ip' => $ip,
                'events' => max(0, (int) ($row['events'] ?? 0)),
                'critical' => max(0, (int) ($row['critical'] ?? 0)),
                'rules' => array_values(array_slice(array_filter(array_map(
                    fn ($rule): string => self::slug($rule, 60),
                    (array) ($row['rules'] ?? []),
                )), 0, 8)),
            ];
        }

        return [
            'counts' => $counts,
            'top_ips' => $topIps,
            'blocks_active' => max(0, (int) ($payload['blocks_active'] ?? 0)),
            'generated_at' => self::text($payload['generated_at'] ?? null, 40),
            // CMS 1.2.122+: runtime sensors and scanner counters. Absent on older CMS: null.
            'system' => self::system($payload['system'] ?? null),
            'scans' => self::scans($payload['scans'] ?? null),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function system(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $slugs = fn (mixed $list, int $max): array => array_values(array_slice(array_filter(array_map(
            fn ($item): string => self::slug($item, 60),
            is_array($list) ? $list : [],
        )), 0, $max));

        $drift = [];
        foreach (array_slice(is_array($value['ini_drift'] ?? null) ? $value['ini_drift'] : [], 0, 20) as $row) {
            $key = is_array($row) ? self::slug($row['key'] ?? null, 60) : '';
            if ($key !== '') {
                $drift[] = [
                    'key' => $key,
                    'expected' => self::text(is_scalar($row['expected'] ?? null) ? (string) $row['expected'] : null, 60),
                    'actual' => self::text(is_scalar($row['actual'] ?? null) ? (string) $row['actual'] : null, 60),
                ];
            }
        }

        $eol = null;
        if (is_array($value['php_eol'] ?? null)) {
            $eol = [
                'version' => self::text($value['php_eol']['version'] ?? null, 20),
                'eol' => self::text($value['php_eol']['eol'] ?? null, 20),
                'days_left' => is_numeric($value['php_eol']['days_left'] ?? null) ? (int) $value['php_eol']['days_left'] : null,
                'status' => self::slug($value['php_eol']['status'] ?? null, 20) ?: null,
            ];
        }

        $integrity = null;
        if (is_array($value['integrity'] ?? null)) {
            $status = self::slug($value['integrity']['status'] ?? null, 20);
            $integrity = [
                'status' => in_array($status, ['ok', 'warning', 'critical', 'unknown'], true) ? $status : 'unknown',
                'open' => max(0, (int) ($value['integrity']['open'] ?? 0)),
            ];
        }

        return [
            'insecure_settings' => max(0, (int) ($value['insecure_settings'] ?? 0)),
            'insecure_keys' => $slugs($value['insecure_keys'] ?? [], 20),
            'exempt' => ($value['exempt'] ?? false) === true,
            'ini_drift' => $drift,
            'php_eol' => $eol,
            'telemetry_gaps' => $slugs($value['telemetry_gaps'] ?? [], 10),
            'integrity' => $integrity,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function scans(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $byType = [];
        foreach ((array) ($value['by_type'] ?? []) as $type => $count) {
            $key = self::slug($type, 40);
            if ($key !== '' && count($byType) < 12) {
                $byType[$key] = max(0, (int) $count);
            }
        }

        return [
            'critical_production' => max(0, (int) ($value['critical_production'] ?? 0)),
            'high_production' => max(0, (int) ($value['high_production'] ?? 0)),
            'pending_review' => max(0, (int) ($value['pending_review'] ?? 0)),
            'last_scan_at' => self::text($value['last_scan_at'] ?? null, 40),
            'by_type' => $byType,
        ];
    }

    private static function slug(mixed $value, int $max): string
    {
        if (! is_string($value) && ! is_int($value)) {
            return '';
        }

        $clean = preg_replace('/[^A-Za-z0-9_.:-]/', '', trim((string) $value)) ?? '';

        return substr($clean, 0, $max);
    }

    private static function text(mixed $value, int $max): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $clean = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '');

        return $clean === '' ? null : mb_substr($clean, 0, $max);
    }

    private static function path(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $path = preg_split('/[?#]/', $value, 2)[0] ?? '';

        return self::text($path, 255);
    }
}
