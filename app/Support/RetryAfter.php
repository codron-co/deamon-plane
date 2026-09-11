<?php

namespace App\Support;

use Illuminate\Http\Client\Response;

/**
 * Reads the `Retry-After` header a throttled Laravel app (Coolify, Deamon CMS)
 * sends with 429, and falls back to jittered exponential backoff.
 */
final class RetryAfter
{
    /**
     * Seconds to wait, or null when the response carries no usable header.
     */
    public static function fromResponse(Response $response): ?float
    {
        foreach (['Retry-After', 'X-RateLimit-Reset'] as $header) {
            $value = trim((string) $response->header($header));
            if ($value === '') {
                continue;
            }

            if (is_numeric($value)) {
                return max(0.0, (float) $value);
            }

            $timestamp = strtotime($value);
            if ($timestamp !== false) {
                return max(0.0, (float) ($timestamp - time()));
            }
        }

        return null;
    }

    /**
     * Exponential backoff with full jitter, clamped to $maxMs.
     */
    public static function backoff(int $attempt, int $baseMs, int $maxMs): float
    {
        $baseMs = max(1, $baseMs);
        $maxMs = max($baseMs, $maxMs);
        $exponential = min($maxMs, $baseMs * (2 ** max(0, $attempt - 1)));
        $jittered = random_int((int) ($exponential / 2), (int) $exponential);

        return $jittered / 1000;
    }

    public static function clamp(float $seconds, int $maxMs): float
    {
        return max(0.0, min($seconds, max(1, $maxMs) / 1000));
    }
}
