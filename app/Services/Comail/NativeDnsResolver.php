<?php

namespace App\Services\Comail;

/**
 * dns_get_record() wrapper (the system resolver of the Plane container).
 */
class NativeDnsResolver implements DnsResolver
{
    public function lookup(string $name, string $type): array
    {
        $flag = match ($type) {
            'A' => DNS_A,
            'MX' => DNS_MX,
            'TXT' => DNS_TXT,
            'CNAME' => DNS_CNAME,
            default => DNS_SRV,
        };

        $records = @dns_get_record(rtrim($name, '.').'.', $flag);
        if ($records === false) {
            return [];
        }

        $out = [];
        foreach ($records as $record) {
            if (strtoupper((string) ($record['type'] ?? '')) !== $type) {
                continue;
            }

            $out[] = match ($type) {
                'A' => ['ip' => (string) ($record['ip'] ?? '')],
                'MX' => ['target' => self::host($record['target'] ?? ''), 'priority' => (int) ($record['pri'] ?? 0)],
                'TXT' => ['value' => is_array($record['entries'] ?? null)
                    ? implode('', array_map('strval', $record['entries']))
                    : (string) ($record['txt'] ?? '')],
                'CNAME' => ['target' => self::host($record['target'] ?? '')],
                default => [
                    'target' => self::host($record['target'] ?? ''),
                    'priority' => (int) ($record['pri'] ?? 0),
                    'weight' => (int) ($record['weight'] ?? 0),
                    'port' => (int) ($record['port'] ?? 0),
                ],
            };
        }

        return $out;
    }

    private static function host(mixed $value): string
    {
        return strtolower(rtrim(trim((string) $value), '.'));
    }
}
