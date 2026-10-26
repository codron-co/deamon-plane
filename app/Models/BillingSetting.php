<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * CoMail billing settings (singleton): which payment methods are offered,
 * the iyzico / PayTR keys (encrypted, never rendered back), the bank account
 * for EFT, the VAT label and the grace period after a subscription ends.
 */
class BillingSetting extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'iyzico_enabled',
        'iyzico_api_key',
        'iyzico_secret',
        'iyzico_sandbox',
        'paytr_enabled',
        'paytr_merchant_id',
        'paytr_merchant_key',
        'paytr_merchant_salt',
        'paytr_test_mode',
        'eft_enabled',
        'bank_name',
        'account_holder',
        'iban',
        'eft_note',
        'vat_included',
        'grace_days',
        'distance_sales_url',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'iyzico_api_key',
        'iyzico_secret',
        'paytr_merchant_key',
        'paytr_merchant_salt',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'iyzico_enabled' => false,
        'iyzico_sandbox' => true,
        'paytr_enabled' => false,
        'paytr_test_mode' => true,
        'eft_enabled' => false,
        'vat_included' => true,
        'grace_days' => 7,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'iyzico_enabled' => 'boolean',
            'iyzico_api_key' => 'encrypted',
            'iyzico_secret' => 'encrypted',
            'iyzico_sandbox' => 'boolean',
            'paytr_enabled' => 'boolean',
            'paytr_merchant_key' => 'encrypted',
            'paytr_merchant_salt' => 'encrypted',
            'paytr_test_mode' => 'boolean',
            'eft_enabled' => 'boolean',
            'vat_included' => 'boolean',
            'grace_days' => 'integer',
        ];
    }

    public static function current(): self
    {
        return self::query()->orderBy('id')->first() ?? new self;
    }

    public function hasSecret(string $attribute): bool
    {
        $raw = $this->exists ? $this->getRawOriginal($attribute) : null;

        return filled($raw ?? $this->attributes[$attribute] ?? null);
    }

    public function iyzicoReady(): bool
    {
        return $this->iyzico_enabled && $this->hasSecret('iyzico_api_key') && $this->hasSecret('iyzico_secret');
    }

    public function paytrReady(): bool
    {
        return $this->paytr_enabled
            && filled($this->paytr_merchant_id)
            && $this->hasSecret('paytr_merchant_key')
            && $this->hasSecret('paytr_merchant_salt');
    }

    public function eftReady(): bool
    {
        return $this->eft_enabled && filled($this->iban);
    }

    /**
     * Payment methods a site may use right now, in display order.
     *
     * @return list<string>
     */
    public function methods(): array
    {
        return array_values(array_filter([
            $this->iyzicoReady() ? MailOrder::METHOD_IYZICO : null,
            $this->paytrReady() ? MailOrder::METHOD_PAYTR : null,
            $this->eftReady() ? MailOrder::METHOD_EFT : null,
        ]));
    }

    public function graceDays(): int
    {
        return max(0, min(90, (int) ($this->grace_days ?? 7)));
    }

    /**
     * Bank details for EFT, or null when EFT is not offered.
     *
     * @return array{bank_name: string, account_holder: string, iban: string, note: string|null}|null
     */
    public function bank(): ?array
    {
        if (! $this->eftReady()) {
            return null;
        }

        return [
            'bank_name' => (string) $this->bank_name,
            'account_holder' => (string) $this->account_holder,
            'iban' => self::formatIban((string) $this->iban),
            'note' => filled($this->eft_note) ? (string) $this->eft_note : null,
        ];
    }

    public static function normalizeIban(string $iban): string
    {
        return strtoupper((string) preg_replace('/\s+/', '', $iban));
    }

    public static function formatIban(string $iban): string
    {
        return trim(chunk_split(self::normalizeIban($iban), 4, ' '));
    }
}
