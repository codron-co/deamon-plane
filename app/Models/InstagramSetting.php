<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * The Meta (Instagram API with Instagram Login) app every Deamon CMS site connects through.
 * The secret never leaves Plane: Plane brokers the OAuth flow and pushes only the
 * resulting per-account token to the site over the signed agent.
 */
class InstagramSetting extends Model
{
    use HasUlids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'app_id',
        'app_secret',
        'last_pushed_at',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'app_secret',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'app_secret' => 'encrypted',
            'last_pushed_at' => 'datetime',
        ];
    }

    public static function current(): self
    {
        return static::query()->first() ?? new self;
    }

    public function isReady(): bool
    {
        return filled($this->app_id) && $this->hasAppSecret();
    }

    public function hasAppSecret(): bool
    {
        return filled($this->getRawOriginal('app_secret') ?? $this->attributes['app_secret'] ?? null);
    }
}
