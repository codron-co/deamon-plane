<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Str;

/**
 * One CoMail package purchase: card (iyzico / PayTR hosted checkout on Plane)
 * or EFT (ops confirms). Card data never reaches Plane; provider_payload keeps
 * a sanitised copy of the provider's answer only.
 */
class MailOrder extends Model
{
    use HasUlids;

    public const METHOD_IYZICO = 'iyzico';

    public const METHOD_PAYTR = 'paytr';

    public const METHOD_EFT = 'eft';

    public const METHODS = [self::METHOD_IYZICO, self::METHOD_PAYTR, self::METHOD_EFT];

    public const PERIOD_MONTHLY = 'monthly';

    public const PERIOD_YEARLY = 'yearly';

    public const PERIODS = [self::PERIOD_MONTHLY, self::PERIOD_YEARLY];

    public const KIND_NEW = 'new';

    public const KIND_RENEWAL = 'renewal';

    public const KIND_CHANGE = 'change';

    public const STATUS_PENDING = 'pending';

    public const STATUS_AWAITING_TRANSFER = 'awaiting_transfer';

    public const STATUS_TRANSFER_REPORTED = 'transfer_reported';

    public const STATUS_PAID = 'paid';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELED = 'canceled';

    public const STATUS_EXPIRED = 'expired';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_AWAITING_TRANSFER,
        self::STATUS_TRANSFER_REPORTED,
        self::STATUS_PAID,
        self::STATUS_FAILED,
        self::STATUS_CANCELED,
        self::STATUS_EXPIRED,
    ];

    /** Card checkout window. */
    public const CARD_TTL_MINUTES = 30;

    /** EFT transfer window. */
    public const EFT_TTL_DAYS = 7;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'site_id',
        'mail_package_id',
        'period',
        'kind',
        'amount',
        'currency',
        'method',
        'status',
        'reference',
        'billing',
        'provider_token',
        'provider_payment_id',
        'provider_payload',
        'return_url',
        'paid_at',
        'confirmed_by',
        'notes',
        'transfer_reported_at',
        'expires_at',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'provider_token',
        'provider_payload',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'billing' => 'array',
            'provider_payload' => 'array',
            'paid_at' => 'datetime',
            'transfer_reported_at' => 'datetime',
            'expires_at' => 'datetime',
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
     * @return BelongsTo<User, $this>
     */
    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function auditLogs(): MorphMany
    {
        return $this->morphMany(AuditLog::class, 'subject');
    }

    public function isCard(): bool
    {
        return in_array($this->method, [self::METHOD_IYZICO, self::METHOD_PAYTR], true);
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    /**
     * Waiting for money: card checkout open or EFT not yet confirmed.
     */
    public function isOpen(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_AWAITING_TRANSFER, self::STATUS_TRANSFER_REPORTED], true);
    }

    public function isCancellable(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_AWAITING_TRANSFER], true);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * PayTR merchant_oid: alphanumeric only.
     */
    public function merchantOid(): string
    {
        return (string) preg_replace('/[^A-Za-z0-9]/', '', (string) $this->reference);
    }

    public function amountCents(): int
    {
        return (int) round(((float) $this->amount) * 100);
    }

    /**
     * Unique short reference like CM-7K3Q9P (no 0/O/1/I, easy to type into a transfer note).
     */
    public static function newReference(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $code = '';
            for ($i = 0; $i < 6; $i++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $reference = 'CM-'.$code;
            if (self::query()->where('reference', $reference)->doesntExist()) {
                return $reference;
            }
        }

        return 'CM-'.strtoupper(Str::random(10));
    }
}
