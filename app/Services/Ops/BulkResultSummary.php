<?php

namespace App\Services\Ops;

/**
 * One truthful sentence per bulk sweep, for both the jobs widget and the
 * no-JavaScript flash. The throttle note is attached only when the rate limit
 * actually left sites untriggered, so "atlandı" never appears next to a sweep
 * where every selected site started.
 */
class BulkResultSummary
{
    /**
     * @param  array{ok?: int, failed?: int, skipped?: int, errors?: list<string>}  $result
     * @param  int  $errorLimit  How many per-site error lines to append; 0 keeps the summary short.
     */
    public static function format(string $prefix, array $result, int $errorLimit = 0): string
    {
        $text = trim($prefix.' '.self::counts($result));

        $errors = array_values(array_filter(
            $result['errors'] ?? [],
            static fn ($error): bool => is_string($error) && $error !== '',
        ));

        if ($errorLimit > 0 && $errors !== []) {
            $text .= ': '.implode(' ', array_slice($errors, 0, $errorLimit));
        }

        $note = self::throttleNote($result);

        return $note === '' ? $text : $text.' — '.$note;
    }

    /**
     * @param  array{ok?: int, failed?: int, skipped?: int}  $result
     */
    public static function counts(array $result): string
    {
        $ok = (int) ($result['ok'] ?? 0);
        $failed = (int) ($result['failed'] ?? 0);
        $skipped = self::skipped($result);

        return match (true) {
            $failed > 0 && $skipped > 0 => __('ops.bulk.result_failed_skipped', [
                'ok' => $ok,
                'failed' => $failed,
                'skipped' => $skipped,
            ]),
            $skipped > 0 => __('ops.bulk.result_skipped', ['ok' => $ok, 'skipped' => $skipped]),
            $failed > 0 => __('ops.bulk.result_failed', ['ok' => $ok, 'failed' => $failed]),
            default => __('ops.bulk.result', ['ok' => $ok]),
        };
    }

    /**
     * @param  array{skipped?: int}  $result
     */
    public static function throttleNote(array $result): string
    {
        return self::skipped($result) > 0 ? (string) __('ops.bulk.rate_limited') : '';
    }

    /**
     * @param  array{skipped?: int}  $result
     */
    public static function skipped(array $result): int
    {
        return max(0, (int) ($result['skipped'] ?? 0));
    }
}
