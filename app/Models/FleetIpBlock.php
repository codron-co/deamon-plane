<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A fleet-wide IP block. Plane owns the intent; `applied` keeps what each site
 * answered ({slug: {state, at, error}}), so a block that missed a site is visible
 * and can be pushed again (the site call is idempotent on `uid`).
 */
class FleetIpBlock extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPLIED = 'applied';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'uid',
        'ip',
        'reason',
        'expires_at',
        'created_by',
        'released_at',
        'released_by',
        'status',
        'applied',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'released_at' => 'datetime',
            'applied' => 'array',
        ];
    }

    /**
     * Blocks that are still in force: not released, not expired.
     *
     * @param  Builder<FleetIpBlock>  $query
     * @return Builder<FleetIpBlock>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('released_at')
            ->where(fn (Builder $inner) => $inner->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
