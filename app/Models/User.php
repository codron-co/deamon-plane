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
        'list_preferences',
        'mail_notification_opt_outs',
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
            'list_preferences' => 'array',
            'mail_notification_opt_outs' => 'array',
        ];
    }

    public function hasMailOptOut(string $key): bool
    {
        $optOuts = is_array($this->mail_notification_opt_outs) ? $this->mail_notification_opt_outs : [];

        return (bool) ($optOuts[$key] ?? false);
    }

    public function optOutMail(string $key): void
    {
        $optOuts = is_array($this->mail_notification_opt_outs) ? $this->mail_notification_opt_outs : [];
        $optOuts[$key] = true;

        $this->mail_notification_opt_outs = $optOuts;
        $this->save();
    }

    /**
     * Stored table layout for one ops list (visible columns + sort).
     *
     * @return array<string, mixed>
     */
    public function listPreference(string $list): array
    {
        $all = is_array($this->list_preferences) ? $this->list_preferences : [];
        $row = $all[$list] ?? null;

        return is_array($row) ? $row : [];
    }

    /**
     * Merges into the stored row so saving a sort does not wipe the columns.
     *
     * @param  array<string, mixed>  $values
     */
    public function saveListPreference(string $list, array $values): void
    {
        $all = is_array($this->list_preferences) ? $this->list_preferences : [];
        $all[$list] = array_merge($this->listPreference($list), $values);

        $this->list_preferences = $all;
        $this->save();
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
