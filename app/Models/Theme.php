<?php

namespace App\Models;

use App\Enums\ThemeVisibility;
use Database\Factories\ThemeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Theme extends Model
{
    /** @use HasFactory<ThemeFactory> */
    use HasFactory, HasUlids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'theme_id',
        'name',
        'repo_full_name',
        'default_ref',
        'visibility',
        'minimum_deamon_version',
        'smoke_paths',
        'latest_sha',
        'latest_tag',
        'last_synced_at',
        'description',
        'theme_git_connection_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'visibility' => ThemeVisibility::class,
            'last_synced_at' => 'datetime',
            'smoke_paths' => 'array',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'theme_id';
    }

    public function gitConnection(): BelongsTo
    {
        return $this->belongsTo(ThemeGitConnection::class, 'theme_git_connection_id');
    }

    public function auditLogs(): MorphMany
    {
        return $this->morphMany(AuditLog::class, 'subject');
    }

    public function installations(): HasMany
    {
        return $this->hasMany(SiteThemeInstallation::class);
    }

    public function accessEntries(): HasMany
    {
        return $this->hasMany(ThemeSiteAccess::class);
    }

    public function allowedSites(): BelongsToMany
    {
        return $this->belongsToMany(Site::class, 'theme_site_access')
            ->withTimestamps();
    }

    public function isAllowedFor(Site $site): bool
    {
        return $this->allowedSites()->where('sites.id', $site->id)->exists();
    }

    public function displayName(): string
    {
        $name = trim((string) ($this->name ?? ''));

        return $name !== '' ? $name : $this->theme_id;
    }

    /**
     * The same search and visibility filters the Themes toolbar posts.
     *
     * @param  Builder<Theme>  $query
     * @return Builder<Theme>
     */
    public function scopeMatchingListFilters(Builder $query, string $search = '', string $visibility = ''): Builder
    {
        $visibility = in_array($visibility, ThemeVisibility::values(), true) ? $visibility : '';

        if ($search !== '') {
            $term = addcslashes($search, '%_\\');
            $query->where(function (Builder $builder) use ($term): void {
                $builder->where('theme_id', 'like', "%{$term}%")
                    ->orWhere('name', 'like', "%{$term}%")
                    ->orWhere('repo_full_name', 'like', "%{$term}%");
            });
        }

        if ($visibility !== '') {
            $query->where('visibility', $visibility);
        }

        return $query;
    }

    public function githubHttpsUrl(): string
    {
        return 'https://github.com/'.$this->repo_full_name;
    }
}
