<?php

namespace App\Models;

use Database\Factories\GithubSettingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GithubSetting extends Model
{
    /** @use HasFactory<GithubSettingFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'org',
        'token',
        'app_id',
        'installation_id',
        'slug',
        'client_id',
        'client_secret',
        'private_key',
        'webhook_secret',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'token',
        'private_key',
        'webhook_secret',
        'client_secret',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
            'private_key' => 'encrypted',
            'webhook_secret' => 'encrypted',
            'client_secret' => 'encrypted',
        ];
    }

    public static function current(): self
    {
        return static::query()->first() ?? new static;
    }

    public function hasToken(): bool
    {
        return filled($this->token);
    }

    public function hasAppCredentials(): bool
    {
        return filled($this->app_id) && filled($this->private_key);
    }

    public function hasManifestApp(): bool
    {
        return $this->hasAppCredentials() && filled($this->slug);
    }

    public function hasWebhookSecret(): bool
    {
        return filled($this->webhook_secret);
    }

    public function resolvedOrg(): string
    {
        $org = trim((string) ($this->org ?: config('ops.themes.org')));

        return $org !== '' ? $org : 'deamon-themes';
    }

    public static function resolvedWebhookSecret(): ?string
    {
        $row = static::current();
        if ($row->hasWebhookSecret()) {
            return (string) $row->webhook_secret;
        }

        $fromEnv = trim((string) config('ops.github.webhook_secret'));

        return $fromEnv !== '' ? $fromEnv : null;
    }

    public function hasAnyCredentials(): bool
    {
        if ($this->hasToken() || $this->hasAppCredentials()) {
            return true;
        }

        return filled(config('ops.github.token'))
            || (filled(config('ops.github.app_id'))
                && filled(config('ops.github.installation_id'))
                && filled(config('ops.github.private_key')));
    }
}
