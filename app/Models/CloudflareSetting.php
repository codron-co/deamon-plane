<?php

namespace App\Models;

use Database\Factories\CloudflareSettingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CloudflareSetting extends Model
{
    /** @use HasFactory<CloudflareSettingFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'account_id',
        'api_token',
        'origin_ipv4',
        'proxied',
        'mail_template_enabled',
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
            'api_token' => 'encrypted',
            'proxied' => 'boolean',
            'mail_template_enabled' => 'boolean',
            'last_probe_at' => 'datetime',
            'last_probe_payload' => 'array',
        ];
    }

    public static function current(): self
    {
        return static::query()->first() ?? new static;
    }

    public function hasToken(): bool
    {
        return filled($this->api_token);
    }

    public function hasCredentials(): bool
    {
        return $this->hasToken() && filled($this->account_id);
    }

    public function resolvedOriginIpv4(): string
    {
        $ip = trim((string) ($this->origin_ipv4 ?: config('ops.cloudflare.default_origin_ipv4')));

        return $ip !== '' ? $ip : '72.62.117.147';
    }
}
