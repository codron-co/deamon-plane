<?php

namespace App\Support\Ops;

use App\Models\Site;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * One merged activity line: a job, a deployment, or an audit row.
 */
final class ActivityRow
{
    public function __construct(
        public readonly string $kind,
        public readonly string $id,
        public readonly CarbonInterface $occurredAt,
        public readonly ?User $actor,
        public readonly ?Site $site,
        public readonly string $title,
        public readonly string $detail,
        public readonly string $outcome,
        public readonly string $url,
    ) {}

    public function kindLabel(): string
    {
        return (string) __('ops.activity.kinds.'.$this->kind);
    }

    public function outcomeLabel(): string
    {
        return (string) __('ops.activity.outcomes.'.$this->outcome);
    }

    public function outcomeTone(): string
    {
        return match ($this->outcome) {
            'ok' => 'ok',
            'failed' => 'failed',
            'cancelled' => 'cancelled',
            'running' => 'in_progress',
            default => 'queued',
        };
    }

    public function actorName(): string
    {
        $name = trim((string) ($this->actor?->name ?? ''));

        return $name !== '' ? $name : (string) __('ops.activity.system');
    }
}
