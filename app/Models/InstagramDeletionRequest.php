<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A Meta deauthorize or data deletion callback. `manual` means no site could be reached
 * automatically; ops finishes it by hand within the 7 days the public policy promises.
 */
class InstagramDeletionRequest extends Model
{
    use HasUlids;

    public const TYPE_DELETION = 'deletion';

    public const TYPE_DEAUTHORIZE = 'deauthorize';

    public const STATUS_RECEIVED = 'received';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_MANUAL = 'manual';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'confirmation_code',
        'type',
        'ig_user_id',
        'status',
        'site_ids',
        'note',
        'completed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'site_ids' => 'array',
            'completed_at' => 'datetime',
        ];
    }

    public static function open(string $type, string $igUserId): self
    {
        return static::query()->create([
            'confirmation_code' => Str::upper(Str::random(12)),
            'type' => $type,
            'ig_user_id' => $igUserId,
            'status' => self::STATUS_RECEIVED,
        ]);
    }

    public function statusUrl(): string
    {
        return route('instagram.data-deletion.status', $this->confirmation_code);
    }
}
