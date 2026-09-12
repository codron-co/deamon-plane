<?php

namespace App\Services\Ops;

/**
 * One truthful sentence per bulk sweep, for both the jobs widget and the
 * no-JavaScript flash. The throttle note is attached only when the rate limit
 * actually left sites untriggered, so "atlandı" never appears next to a sweep
 * where every selected site started.
 *
 * Two count vocabularies, because Plane finishes two different kinds of work:
 * `format()` for work Plane itself completed ("3 tamam") and
 * `formatTriggered()` for work that only handed a deploy to Coolify
 * ("3 deploy tetiklendi"). A sweep that merely enqueued builds must never
 * borrow the finished vocabulary.
 */
class BulkResultSummary
{
    /**
     * @param  array{ok?: int, failed?: int, skipped?: int, errors?: list<string>}  $result
     * @param  int  $errorLimit  How many per-site error lines to append; 0 keeps the summary short.
     */
    public static function format(string $prefix, array $result, int $errorLimit = 0): string
    {
        return self::compose($prefix, $result, $errorLimit, self::counts($result), []);
    }

    /**
     * Sweeps whose success only means "Coolify accepted a deploy request".
     * The build itself is reported by the per-site `Coolify deploy` rows.
     *
     * @param  array{ok?: int, failed?: int, skipped?: int, errors?: list<string>}  $result
     */
    public static function formatTriggered(string $prefix, array $result, int $errorLimit = 0): string
    {
        $notes = (int) ($result['ok'] ?? 0) > 0 ? [(string) __('ops.bulk.triggered_note')] : [];

        return self::compose($prefix, $result, $errorLimit, self::triggeredCounts($result), $notes);
    }

    /**
     * @param  array{ok?: int, failed?: int, skipped?: int}  $result
     */
    public static function counts(array $result): string
    {
        return self::countsFrom($result, 'result');
    }

    /**
     * @param  array{ok?: int, failed?: int, skipped?: int}  $result
     */
    public static function triggeredCounts(array $result): string
    {
        return self::countsFrom($result, 'triggered');
    }

    /**
     * @param  array{skipped?: int}  $result
     */
    public static function throttleNote(array $result): string
    {
        return self::skipped($result) > 0 ? (string) __('ops.bulk.rate_limited') : '';
    }

    /**
     * The sentence that keeps a trigger-only sweep honest about Coolify.
     */
    public static function triggeredNote(): string
    {
        return (string) __('ops.bulk.triggered_note');
    }

    /**
     * @param  array{skipped?: int}  $result
     */
    public static function skipped(array $result): int
    {
        return max(0, (int) ($result['skipped'] ?? 0));
    }

    /**
     * @param  array{ok?: int, failed?: int, skipped?: int, errors?: list<string>}  $result
     * @param  list<string>  $notes
     */
    private static function compose(
        string $prefix,
        array $result,
        int $errorLimit,
        string $counts,
        array $notes,
    ): string {
        $text = trim($prefix.' '.$counts);

        $errors = array_values(array_filter(
            $result['errors'] ?? [],
            static fn ($error): bool => is_string($error) && $error !== '',
        ));

        if ($errorLimit > 0 && $errors !== []) {
            $text .= ': '.implode(' ', array_slice($errors, 0, $errorLimit));
        }

        $notes[] = self::throttleNote($result);
        $notes = array_values(array_filter($notes, static fn (string $note): bool => $note !== ''));

        return $notes === [] ? $text : $text.' — '.implode(' ', $notes);
    }

    /**
     * @param  array{ok?: int, failed?: int, skipped?: int}  $result
     */
    private static function countsFrom(array $result, string $group): string
    {
        $ok = (int) ($result['ok'] ?? 0);
        $failed = (int) ($result['failed'] ?? 0);
        $skipped = self::skipped($result);

        return match (true) {
            $failed > 0 && $skipped > 0 => __('ops.bulk.'.$group.'_failed_skipped', [
                'ok' => $ok,
                'failed' => $failed,
                'skipped' => $skipped,
            ]),
            $skipped > 0 => __('ops.bulk.'.$group.'_skipped', ['ok' => $ok, 'skipped' => $skipped]),
            $failed > 0 => __('ops.bulk.'.$group.'_failed', ['ok' => $ok, 'failed' => $failed]),
            default => __('ops.bulk.'.$group, ['ok' => $ok]),
        };
    }
}
