<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\CarbonInterface;

/**
 * A site's paid CoMail package period. No row = the default (free) package.
 * active → grace (after current_period_end, for grace_days) → expired.
 */
class MailSubscription extends Model
{
    use HasUlids;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_GRACE = 'grace';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_CANCELED = 'canceled';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'site_id',
        'mail_package_id',
        'period',
        'status',
        'current_period_start',
        'current_period_end',
        'auto_renew',
        'canceled_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'auto_renew' => 'boolean',
            'canceled_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * @return BelongsTo<MailPackage, $this>
     */
    public function mailPackage(): BelongsTo
    {
        return $this->belongsTo(MailPackage::class);
    }

    /**
     * Still entitled to the package (active or within the grace period).
     */
    public function isLive(): bool
    {
        return in_array($this->status, [self::STATUS_ACTIVE, self::STATUS_GRACE], true);
    }

    public function graceEndsAt(int $graceDays): ?CarbonInterface
    {
        return $this->current_period_end?->copy()->addDays($graceDays);
    }
}
