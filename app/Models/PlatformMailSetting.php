<?php

namespace App\Models;

use App\Services\Mail\PlatformNotificationCatalog;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class PlatformMailSetting extends Model
{
    use HasUlids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'enabled',
        'host',
        'port',
        'encryption',
        'username',
        'password',
        'from_address',
        'from_name',
        'default_admin_recipient',
        'notifications',
        'last_pushed_at',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'port' => 'integer',
            'password' => 'encrypted',
            'notifications' => 'array',
            'last_pushed_at' => 'datetime',
        ];
    }

    public static function current(): self
    {
        $row = static::query()->first();
        if ($row instanceof self) {
            return $row;
        }

        return new static([
            'enabled' => false,
            'port' => 465,
            'encryption' => 'ssl',
            'from_name' => 'Deamon Support Team',
            'notifications' => PlatformNotificationCatalog::defaultNotifications(),
        ]);
    }

    public function isReady(): bool
    {
        return $this->enabled
            && filled($this->host)
            && filled($this->username)
            && filled($this->password)
            && filled($this->from_address);
    }

    public function hasPassword(): bool
    {
        $raw = $this->getRawOriginal('password');
        if (is_string($raw) && $raw !== '') {
            return true;
        }

        $attribute = $this->attributes['password'] ?? null;

        return is_string($attribute) && $attribute !== '';
    }
}
