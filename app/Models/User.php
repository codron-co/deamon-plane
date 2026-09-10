<?php

namespace App\Models;

use App\Enums\Appearance;
use App\Enums\OpsRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'locale',
        'appearance',
        'avatar_path',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'appearance' => Appearance::class,
        ];
    }

    public function initials(): string
    {
        $name = trim((string) $this->name);

        return $name !== '' ? mb_strtoupper(mb_substr($name, 0, 1)) : '?';
    }

    public function avatarUrl(): ?string
    {
        if (blank($this->avatar_path)) {
            return null;
        }

        return Storage::disk('public')->url((string) $this->avatar_path);
    }

    public function appearanceValue(): string
    {
        $value = $this->appearance instanceof Appearance
            ? $this->appearance->value
            : (string) $this->appearance;

        return in_array($value, Appearance::values(), true) ? $value : Appearance::Dark->value;
    }

    public function localeValue(): string
    {
        $locale = (string) ($this->locale ?: config('app.locale', 'en'));
        $supported = config('ops.locales', ['en', 'tr']);

        return in_array($locale, $supported, true) ? $locale : (string) config('app.locale', 'en');
    }

    public function canWriteOps(): bool
    {
        return $this->hasAnyRole([
            OpsRole::SuperAdmin->value,
            OpsRole::Operator->value,
        ]);
    }

    public function opsRole(): ?OpsRole
    {
        foreach (OpsRole::cases() as $role) {
            if ($this->hasRole($role->value)) {
                return $role;
            }
        }

        return null;
    }

    public function requestedDeployments(): HasMany
    {
        return $this->hasMany(Deployment::class, 'requested_by');
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class, 'actor_user_id');
    }
}
