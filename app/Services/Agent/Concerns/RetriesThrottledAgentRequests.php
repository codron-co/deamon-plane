<?php

namespace App\Services\Agent\Concerns;

use App\Support\RetryAfter;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Sleep;

/**
 * CMS routes sit behind Laravel `throttle`; a burst of Plane calls to one site gets
 * 429. Retry those with Retry-After / backoff. Connection failures (timeouts) are
 * retried too when the caller opts in — only from a queued job, never inside an
 * operator request, because each attempt costs the full agent timeout.
 */
trait RetriesThrottledAgentRequests
{
    /**
     * @param  callable(): Response  $send
     */
    private function sendWithRetry(callable $send, bool $retryConnection = false): Response
    {
        $max = max(1, (int) config('ops.agent.retry.max_attempts', 2));
        $baseMs = max(1, (int) config('ops.agent.retry.base_delay_ms', 400));
        $maxMs = max(1, (int) config('ops.agent.retry.max_delay_ms', 5000));
        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                $response = $send();
            } catch (ConnectionException $exception) {
                if (! $retryConnection || $attempt >= $max) {
                    throw $exception;
                }

                $this->pause(RetryAfter::clamp(RetryAfter::backoff($attempt, $baseMs, $maxMs), $maxMs));

                continue;
            }

            if ($response->status() !== 429 || $attempt >= $max) {
                return $response;
            }

            $this->pause(RetryAfter::clamp(
                RetryAfter::fromResponse($response) ?? RetryAfter::backoff($attempt, $baseMs, $maxMs),
                $maxMs,
            ));
        }
    }

    private function pause(float $seconds): void
    {
        if ($seconds > 0) {
            Sleep::for((int) ceil($seconds * 1000))->milliseconds();
        }
    }
}
