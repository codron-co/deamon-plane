<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * The DeskRon Application every Deamon CMS site uses for its admin Support page.
 * Pushed to each CMS over the signed agent (POST /deskron/configure), never env.
 */
class DeskronSetting extends Model
{
    use HasUlids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'application_id',
        'api_key',
        'webhook_secret',
        'last_pushed_at',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'api_key',
        'webhook_secret',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'webhook_secret' => 'encrypted',
            'last_pushed_at' => 'datetime',
        ];
    }

    public static function current(): self
    {
        return static::query()->first() ?? new static;
    }

    public function isReady(): bool
    {
        return filled($this->application_id) && $this->hasApiKey();
    }

    public function hasApiKey(): bool
    {
        return filled($this->getRawOriginal('api_key') ?? $this->attributes['api_key'] ?? null);
    }

    public function hasWebhookSecret(): bool
    {
        return filled($this->getRawOriginal('webhook_secret') ?? $this->attributes['webhook_secret'] ?? null);
    }
}
