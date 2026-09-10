<?php

namespace App\Models;

use App\Enums\MailProvider;
use Database\Factories\MailServerFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class MailServer extends Model
{
    /** @use HasFactory<MailServerFactory> */
    use HasFactory, HasUlids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'provider',
        'api_token',
        'hostinger_order_id',
        'mail_domain',
        'is_enabled',
        'last_probe_at',
        'last_probe_payload',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'api_token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => MailProvider::class,
            'api_token' => 'encrypted',
            'is_enabled' => 'boolean',
            'last_probe_at' => 'datetime',
            'last_probe_payload' => 'array',
        ];
    }

    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }

    public function auditLogs(): MorphMany
    {
        return $this->morphMany(AuditLog::class, 'subject');
    }

    public function hasToken(): bool
    {
        $raw = $this->getRawOriginal('api_token');
        if (is_string($raw) && $raw !== '') {
            return true;
        }

        $attribute = $this->attributes['api_token'] ?? null;

        return is_string($attribute) && $attribute !== '';
    }

    public function isHostingerReady(): bool
    {
        return $this->is_enabled
            && $this->provider === MailProvider::Hostinger
            && $this->hasToken()
            && filled($this->hostinger_order_id)
            && filled($this->mail_domain);
    }

    /**
     * @return list<array{id: string, domain: string|null, status: string|null, seats: int|null}>
     */
    public function probedOrders(): array
    {
        $payload = is_array($this->last_probe_payload) ? $this->last_probe_payload : [];
        $orders = $payload['orders'] ?? [];
        if (! is_array($orders)) {
            return [];
        }

        $out = [];
        foreach ($orders as $order) {
            if (! is_array($order) || ! is_string($order['id'] ?? null) || $order['id'] === '') {
                continue;
            }
            $out[] = [
                'id' => $order['id'],
                'domain' => is_string($order['domain'] ?? null) ? $order['domain'] : null,
                'status' => is_string($order['status'] ?? null) ? $order['status'] : null,
                'seats' => is_numeric($order['seats'] ?? null) ? (int) $order['seats'] : null,
            ];
        }

        return $out;
    }
}
