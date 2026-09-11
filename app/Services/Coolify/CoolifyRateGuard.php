<?php

namespace App\Services\Coolify;

use Illuminate\Support\Sleep;

/**
 * Paces outgoing Coolify calls per host and shares a cooldown once one call is
 * throttled. Registered as a container singleton so a bulk sweep over many sites
 * backs off as a whole instead of every site rediscovering the 429.
 */
class CoolifyRateGuard
{
    /**
     * @var array<string, float> Unix timestamp of the last send per host.
     */
    private array $lastSentAt = [];

    /**
     * @var array<string, float> Unix timestamp until which the host is on cooldown.
     */
    private array $cooldownUntil = [];

    /**
     * Wait out the per-host spacing and any cooldown left from an earlier 429.
     */
    public function await(string $host): void
    {
        $host = $this->normalize($host);
        $now = $this->now();

        $readyAt = max(
            ($this->lastSentAt[$host] ?? 0.0) + $this->minInterval(),
            $this->cooldownUntil[$host] ?? 0.0,
        );

        $wait = $readyAt - $now;
        if ($wait > 0) {
            Sleep::for($this->toMilliseconds($wait))->milliseconds();
            $now += $wait;
        }

        $this->lastSentAt[$host] = $now;
    }

    /**
     * Hold every later call to this host until the advertised retry window passes.
     */
    public function penalize(string $host, float $seconds): void
    {
        $host = $this->normalize($host);
        $seconds = max(0.0, min($seconds, $this->maxCooldown()));
        $until = $this->now() + $seconds;

        if ($until > ($this->cooldownUntil[$host] ?? 0.0)) {
            $this->cooldownUntil[$host] = $until;
        }
    }

    /**
     * Seconds a caller must still wait before this host accepts traffic again.
     */
    public function cooldownRemaining(string $host): float
    {
        $remaining = ($this->cooldownUntil[$this->normalize($host)] ?? 0.0) - $this->now();

        return max(0.0, $remaining);
    }

    public function forget(string $host): void
    {
        $host = $this->normalize($host);
        unset($this->lastSentAt[$host], $this->cooldownUntil[$host]);
    }

    private function normalize(string $host): string
    {
        $host = trim($host);

        return $host === '' ? 'default' : strtolower($host);
    }

    private function minInterval(): float
    {
        return max(0, (int) config('ops.coolify.rate.min_interval_ms', 120)) / 1000;
    }

    private function maxCooldown(): float
    {
        return max(0, (int) config('ops.coolify.rate.max_cooldown_ms', 30000)) / 1000;
    }

    private function toMilliseconds(float $seconds): int
    {
        return max(1, (int) ceil($seconds * 1000));
    }

    private function now(): float
    {
        return (float) now()->getPreciseTimestamp(6) / 1_000_000;
    }
}
