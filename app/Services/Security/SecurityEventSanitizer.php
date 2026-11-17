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
