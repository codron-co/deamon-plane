<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One security event reported by a CMS (security-alerts module). Never holds a
 * request body or a secret: only the fields the agent contract lists.
 */
class SiteSecurityEvent extends Model
{
    use MassPrunable;

    public const SEVERITY_INFO = 0;

    public const SEVERITY_WARNING = 1;

    public const SEVERITY_CRITICAL = 2;

    public const RETENTION_DAYS = 90;

    public $timestamps = false;

    protected $fillable = [
        'site_id',
        'event_uid',
        'occurred_at',
        'type',
        'severity',
        'rule',
        'ip',
        'country',
        'user_agent',
        'method',
        'path',
        'status',
        'count',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
            'severity' => 'integer',
            'status' => 'integer',
            'count' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class)->withTrashed();
    }

    public function prunable(): Builder
    {
        return static::query()->where('occurred_at', '<', now()->subDays(self::retentionDays()));
    }

    public static function retentionDays(): int
    {
        return max(7, (int) config('ops.security.retention_days', self::RETENTION_DAYS));
    }

    public function severityKey(): string
    {
        return match (true) {
            $this->severity >= self::SEVERITY_CRITICAL => 'critical',
            $this->severity === self::SEVERITY_WARNING => 'warning',
            default => 'info',
        };
    }
}
