<?php

namespace App\Models;

use App\Enums\CiGateMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fleet-wide CI gate mode (Settings → CI gate, Super Admin). One row at most;
 * no row means `enforce`.
 */
class CiGateSetting extends Model
{
    protected $fillable = [
        'mode',
        'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'mode' => CiGateMode::class,
        ];
    }

    public static function mode(): CiGateMode
    {
        return static::row()->mode ?? CiGateMode::Enforce;
    }

    public static function row(): ?self
    {
        return static::query()->oldest('id')->first();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }
}
