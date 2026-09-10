<?php

namespace App\Models;

use App\Enums\ThemeGitAccountType;
use App\Enums\ThemeGitConnectionKind;
use App\Enums\ThemeGitConnectionStatus;
use App\Enums\ThemeGitSelectionMode;
use Database\Factories\ThemeGitConnectionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ThemeGitConnection extends Model
{
    /** @use HasFactory<ThemeGitConnectionFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'account_login',
        'account_type',
        'installation_id',
        'selection_mode',
        'repo_name_prefix',
        'status',
        'last_error',
        'kind',
        'token',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'account_type' => ThemeGitAccountType::class,
            'selection_mode' => ThemeGitSelectionMode::class,
            'status' => ThemeGitConnectionStatus::class,
            'kind' => ThemeGitConnectionKind::class,
            'token' => 'encrypted',
        ];
    }

    public function repos(): HasMany
    {
        return $this->hasMany(ThemeGitConnectionRepo::class);
    }

    public function themes(): HasMany
    {
        return $this->hasMany(Theme::class);
    }

    public function includedRepos(): HasMany
    {
        return $this->hasMany(ThemeGitConnectionRepo::class)->where('included', true);
    }

    public function displayName(): string
    {
        $name = trim((string) ($this->name ?? ''));

        return $name !== '' ? $name : $this->account_login;
    }

    public function isConnected(): bool
    {
        return $this->status === ThemeGitConnectionStatus::Connected;
    }

    public function isGithubApp(): bool
    {
        return $this->kind === ThemeGitConnectionKind::GithubApp;
    }

    public function isPat(): bool
    {
        return $this->kind === ThemeGitConnectionKind::Pat;
    }

    public function usesSelectedRepos(): bool
    {
        return $this->selection_mode === ThemeGitSelectionMode::Selected;
    }

    public function normalizedPrefix(): ?string
    {
        $prefix = trim((string) ($this->repo_name_prefix ?? ''));

        return $prefix !== '' ? $prefix : null;
    }

    public function markConnected(): void
    {
        $this->status = ThemeGitConnectionStatus::Connected;
        $this->last_error = null;
        $this->save();
    }

    public function markError(string $message): void
    {
        $this->status = ThemeGitConnectionStatus::Error;
        $this->last_error = $message;
        $this->save();
    }

    public static function createFromLegacySettings(GithubSetting $settings): ?self
    {
        if (! $settings->exists) {
            return null;
        }

        if (static::query()->exists()) {
            return null;
        }

        $hasApp = filled($settings->app_id) && filled($settings->private_key);
        $hasPat = $settings->hasToken();
        if (! $hasApp && ! $hasPat) {
            return null;
        }

        $org = trim((string) ($settings->org ?: config('ops.themes.org', 'deamon-themes')));
        if ($org === '') {
            return null;
        }

        $connected = ($hasApp && filled($settings->installation_id)) || $hasPat;
        $prefix = trim((string) config('ops.themes.repo_prefix', 'deamon-theme-'));

        $connection = static::query()->create([
            'name' => $org,
            'account_login' => $org,
            'account_type' => ThemeGitAccountType::Organization,
            'installation_id' => $hasApp ? $settings->installation_id : null,
            'selection_mode' => ThemeGitSelectionMode::All,
            'repo_name_prefix' => $prefix !== '' ? $prefix : 'deamon-theme-',
            'status' => $connected ? ThemeGitConnectionStatus::Connected : ThemeGitConnectionStatus::Pending,
            'kind' => $hasApp ? ThemeGitConnectionKind::GithubApp : ThemeGitConnectionKind::Pat,
            'token' => $hasApp ? null : $settings->token,
        ]);

        Theme::query()
            ->whereNull('theme_git_connection_id')
            ->update(['theme_git_connection_id' => $connection->id]);

        $settings->installation_id = null;
        if (! $hasApp) {
            $settings->token = null;
        }
        $settings->save();

        return $connection;
    }
}
