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
        'base_url',
        'mail_hostname',
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

    /**
     * The CoMail server: the first enabled mailcow server with an API key and base URL.
     */
    public static function comail(): ?self
    {
        return self::query()
            ->where('provider', MailProvider::Mailcow->value)
            ->where('is_enabled', true)
            ->whereNotNull('api_token')
            ->whereNotNull('base_url')
            ->orderBy('created_at')
            ->get()
            ->first(fn (self $server): bool => $server->isComailReady());
    }

    public function isComailReady(): bool
    {
        return $this->is_enabled
            && $this->provider === MailProvider::Mailcow
            && $this->hasToken()
            && $this->comailBaseUrl() !== '';
    }

    /**
     * Web root of the mailcow install (`https://mail.codron.co`), never with `/api/v1`.
     */
    public function comailBaseUrl(): string
    {
        $url = rtrim(trim((string) $this->base_url), '/');
        if (str_ends_with(strtolower($url), '/api/v1')) {
            $url = substr($url, 0, -7);
        }

        return rtrim($url, '/');
    }

    /**
     * Host customers' MX, SPF, CNAME and client settings point at.
     */
    public function comailHostname(): string
    {
        $host = strtolower(trim((string) $this->mail_hostname));
        if ($host !== '') {
            return $host;
        }

        $fromUrl = parse_url($this->comailBaseUrl(), PHP_URL_HOST);

        return is_string($fromUrl) ? strtolower($fromUrl) : '';
    }

    public function isHostingerReady(): bool
    {
        return $this->is_enabled
            && $this->provider === MailProvider::Hostinger
            && $this->hasToken();
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
