<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Runtime on/off switch for one automatic action (App\Services\Ops\AutomationGuard).
 * No row means "on"; the env flag in config/ops.php is the floor above it.
 */
class AutomationSetting extends Model
{
    protected $fillable = [
        'rule',
        'enabled',
        'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }
}
