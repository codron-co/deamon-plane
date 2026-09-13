<?php

namespace App\Models;

use Database\Factories\AuditLogFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

class AuditLog extends Model
{
    /** @use HasFactory<AuditLogFactory> */
    use HasFactory;

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'actor_user_id',
        'action',
        'subject_type',
        'subject_id',
        'before',
        'after',
        'ip',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (AuditLog $log): void {
            $log->created_at ??= now();
        });

        static::updating(function (): void {
            throw new LogicException('Audit logs are append-only.');
        });

        static::deleting(function (): void {
            throw new LogicException('Audit logs are append-only.');
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $options
     */
    public function update(array $attributes = [], array $options = []): bool
    {
        throw new LogicException('Audit logs are append-only.');
    }

    public function delete(): ?bool
    {
        throw new LogicException('Audit logs are append-only.');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @param  Builder<AuditLog>  $query
     * @return Builder<AuditLog>
     */
    public function scopeForActor(Builder $query, int $actorId): Builder
    {
        return $query->where('actor_user_id', $actorId);
    }

    /**
     * @param  Builder<AuditLog>  $query
     * @return Builder<AuditLog>
     */
    public function scopeForSite(Builder $query, string $siteId): Builder
    {
        return $query->where('subject_type', Site::class)->where('subject_id', $siteId);
    }

    /**
     * @param  Builder<AuditLog>  $query
     * @return Builder<AuditLog>
     */
    public function scopeMatchingSearch(Builder $query, string $search): Builder
    {
        $term = addcslashes($search, '%_\\');

        return $query->where('action', 'like', "%{$term}%");
    }

    public function actionLabel(): string
    {
        $key = 'ops.activity.actions.'.$this->action;
        $label = __($key);

        return $label === $key ? $this->action : (string) $label;
    }

    /**
     * Audits record that something happened. Only actions that name a failure
     * are treated as a failed outcome; everything else is `ok`.
     */
    public function outcome(): string
    {
        return str_contains($this->action, 'failed') ? 'failed' : 'ok';
    }
}
