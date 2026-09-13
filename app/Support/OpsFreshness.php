<?php

namespace App\Support;

use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * Relative age for operator-facing timestamps. Absolute time stays in the
 * tooltip; "stale" is a word, not a colour, and a missing timestamp is never
 * treated as stale.
 */
final class OpsFreshness
{
    /**
     * The agent health cron is clamped to 5–15 minutes in routes/console.php.
     * Live probes and the publish mirror ride the same job, so they share the
     * window: older than 2× that interval is stale.
     */
    public static function pollWindowMinutes(): int
    {
        return min(15, max(5, (int) config('ops.agent.poll_minutes', 10)));
    }

    public static function staleAfterMinutes(): int
    {
        return self::pollWindowMinutes() * 2;
    }

    /**
     * @return array{missing: bool, stale: bool, label: string, absolute: ?string}
     */
    public static function describe(DateTimeInterface|CarbonInterface|null $at, bool $markStale = true): array
    {
        if ($at === null) {
            return [
                'missing' => true,
                'stale' => false,
                'label' => '',
                'absolute' => null,
            ];
        }

        $moment = $at instanceof CarbonInterface ? $at : Carbon::instance($at);
        $local = $moment->timezone((string) config('app.timezone'));
        $seconds = (int) $local->diffInSeconds(now(), true);
        $minutes = intdiv($seconds, 60);

        return [
            'missing' => false,
            'stale' => $markStale && $minutes > self::staleAfterMinutes(),
            'label' => self::relativeLabel($seconds, $minutes),
            'absolute' => $local->format('Y-m-d H:i'),
        ];
    }

    private static function relativeLabel(int $seconds, int $minutes): string
    {
        if ($minutes < 1) {
            return (string) __('ops.freshness.just_now');
        }

        if ($minutes < 60) {
            return (string) trans_choice('ops.freshness.minutes', $minutes, ['count' => $minutes]);
        }

        $hours = intdiv($minutes, 60);
        if ($hours < 48) {
            return (string) trans_choice('ops.freshness.hours', $hours, ['count' => $hours]);
        }

        $days = max(1, intdiv($minutes, 1440));

        return (string) trans_choice('ops.freshness.days', $days, ['count' => $days]);
    }
}
