<?php

namespace App\Models;

use App\Enums\Channel;
use App\Enums\FleetRolloutStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * A CI-gated CMS rollout of one green commit on one channel branch.
 *
 * `summary` layout (all keys optional until the stage starts):
 *   canary: {site_ids, pending, deployments: {site_id: deployment_id}, healthy, health_failures: {site_id: n}, failed}
 *   fanout: {site_ids, pending, deployed, failed}
 *   errors: list<string>
 *
 * @property int $id
 * @property Channel $channel
 * @property string $sha
 * @property FleetRolloutStatus $status
 * @property Carbon|null $started_at
 * @property Carbon|null $stage_started_at
 * @property Carbon|null $finished_at
 * @property array<string, mixed>|null $summary
 * @property string|null $halted_reason
 * @property int|null $halted_by
 */
class FleetRollout extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'channel',
        'sha',
        'status',
        'started_at',
        'stage_started_at',
        'finished_at',
        'summary',
        'halted_reason',
        'halted_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => Channel::class,
            'status' => FleetRolloutStatus::class,
            'started_at' => 'datetime',
            'stage_started_at' => 'datetime',
            'finished_at' => 'datetime',
            'summary' => 'array',
        ];
    }

    /**
     * @param  Builder<FleetRollout>  $query
     * @return Builder<FleetRollout>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [
            FleetRolloutStatus::Canary->value,
            FleetRolloutStatus::Fanout->value,
        ]);
    }

    public function haltedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'halted_by');
    }

    public function auditLogs(): MorphMany
    {
        return $this->morphMany(AuditLog::class, 'subject');
    }

    public function shortSha(): string
    {
        return substr($this->sha, 0, 7);
    }

    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }

    /**
     * @return array<string, mixed>
     */
    public function stage(string $name): array
    {
        $summary = is_array($this->summary) ? $this->summary : [];
        $stage = $summary[$name] ?? [];

        return is_array($stage) ? $stage : [];
    }

    /**
     * Targets / deployed / healthy-or-failed counts for the list and detail page.
     *
     * @return array{targets: int, deployed: int, healthy: int, failed: int, pending: int}
     */
    public function stageCounts(string $name): array
    {
        $stage = $this->stage($name);
        $count = static fn (mixed $value): int => is_array($value) ? count($value) : 0;

        return [
            'targets' => $count($stage['site_ids'] ?? null),
            'deployed' => $name === 'canary'
                ? $count($stage['deployments'] ?? null)
                : $count($stage['deployed'] ?? null),
            'healthy' => $count($stage['healthy'] ?? null),
            'failed' => $count($stage['failed'] ?? null),
            'pending' => $count($stage['pending'] ?? null),
        ];
    }

    public function label(): string
    {
        return $this->channel->value.' · '.$this->shortSha();
    }
}
