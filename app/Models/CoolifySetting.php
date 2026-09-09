<?php

namespace App\Models;

use Database\Factories\CoolifySettingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CoolifySetting extends Model
{
    /** @use HasFactory<CoolifySettingFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'base_url',
        'api_token',
        'default_project_uuid',
        'default_server_uuid',
        'github_app_uuid',
        'private_key_uuid',
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
}
