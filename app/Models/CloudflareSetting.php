<?php

namespace App\Models;

use App\Services\Cloudflare\CloudflareAccounts;
use App\Services\Cloudflare\CloudflareHostname;
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
        'name',
        'account_id',
        'api_token',
        'origin_ipv4',
        'wildcard_domain',
        'proxied',
        'mail_template_enabled',
        'is_enabled',
        'is_default',
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
            'is_enabled' => 'boolean',
            'is_default' => 'boolean',
            'last_probe_at' => 'datetime',
            'last_probe_payload' => 'array',
        ];
    }

    public static function current(): self
    {
        return CloudflareAccounts::default() ?? new static;
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

    public function resolvedWildcardDomain(): string
    {
        $fromSettings = CloudflareHostname::normalize((string) ($this->wildcard_domain ?? ''));
        if ($fromSettings !== '') {
            return $fromSettings;
        }

        return CloudflareHostname::normalize(
            (string) config('ops.cloudflare.wildcard_domain', 'codron.co'),
        );
    }
}
