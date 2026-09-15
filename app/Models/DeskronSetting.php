<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * The DeskRon Application every Deamon CMS site uses for its admin Support page.
 * Written into each site's Coolify env through `{{plane.deskron_*}}` catalog tokens.
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
