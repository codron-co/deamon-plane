<?php

namespace App\Services\Ops;

use App\Models\Deployment;

/**
 * What happened to a single-site deploy request: it started on Coolify, or it
 * waits in the Plane line because the host's build cap was full.
 */
final class DeployRequestOutcome
{
    private function __construct(
        public readonly Deployment $deployment,
        public readonly bool $queued,
        public readonly int $position = 0,
        public readonly int $running = 0,
        public readonly bool $duplicate = false,
    ) {}

    public static function started(Deployment $deployment): self
    {
        return new self($deployment, false);
    }

    public static function queued(Deployment $deployment, int $position, int $running, bool $duplicate = false): self
    {
        return new self($deployment, true, $position, $running, $duplicate);
    }

    /**
     * The operator flash: the caller's own "started" copy, or the queue copy
     * ("Sunucuda 2 derleme sürüyor; deploy sıraya alındı (sırada 1.)").
     */
    public function flash(string $startedMessage): string
    {
        if (! $this->queued) {
            return $startedMessage;
        }

        return __($this->duplicate ? 'site_ops.queue.already_queued' : 'site_ops.queue.queued', [
            'running' => $this->running,
            'position' => $this->position,
        ]);
    }
}
