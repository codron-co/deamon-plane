<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An address (or CIDR) every site must never block. The allowlist always wins
 * over a block on the CMS side.
 */
class FleetAllowlistEntry extends Model
{
    protected $table = 'fleet_allowlist';

    protected $fillable = [
        'uid',
        'ip',
        'label',
        'source',
        'created_by',
        'removed_at',
        'status',
        'applied',
    ];

    protected function casts(): array
    {
        return [
            'removed_at' => 'datetime',
            'applied' => 'array',
        ];
    }

    /**
     * @param  Builder<FleetAllowlistEntry>  $query
     * @return Builder<FleetAllowlistEntry>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('removed_at');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
