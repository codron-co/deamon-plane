<?php

namespace App\Models;

use App\Enums\Channel;
use App\Enums\CoolifyGitSourceKind;
use App\Enums\SiteStatus;
use Database\Factories\SiteFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use LogicException;

class Site extends Model
{
    /** @use HasFactory<SiteFactory> */
    use HasFactory, HasUlids, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'slug',
        'name',
        'primary_domain',
        'channel',
        'desired_channel',
        'status',
        'coolify_app_uuid',
        'coolify_connection_id',
        'coolify_server_uuid',
        'coolify_project_uuid',
        'coolify_environment_uuid',
        'coolify_git_source_uuid',
        'coolify_git_source_kind',
        'channel_needs_review',
        'git_repository',
        'app_key_encrypted',
        'agent_secret_encrypted',
        'agent_base_url',
        'notes',
        'last_health_at',
        'last_health_payload',
    ];

    /**
     * Secrets must never appear in arrays, JSON, or logs.
     *
     * @var list<string>
     */
    protected $hidden = [
        'app_key_encrypted',
        'agent_secret_encrypted',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => Channel::class,
            'desired_channel' => Channel::class,
            'coolify_git_source_kind' => CoolifyGitSourceKind::class,
            'channel_needs_review' => 'boolean',
            'status' => SiteStatus::class,
            'app_key_encrypted' => 'encrypted',
            'agent_secret_encrypted' => 'encrypted',
            'last_health_at' => 'datetime',
            'last_health_payload' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Site $site): void {
            if ($site->status === null) {
                $site->status = SiteStatus::Draft;
            }

            if (blank($site->git_repository)) {
                $site->git_repository = config('ops.deamon.repository')
                    ?: 'https://github.com/codron-co/deamon.git';
            }
        });

        static::saving(function (Site $site): void {
            if ($site->channel instanceof Channel) {
                Channel::assertAllowed($site->channel);
            }

            if ($site->desired_channel instanceof Channel) {
                Channel::assertAllowed($site->desired_channel);
            }
        });
    }

    public function coolifyConnection(): BelongsTo
    {
        return $this->belongsTo(CoolifyConnection::class);
    }

    public function domains(): HasMany
    {
        return $this->hasMany(SiteDomain::class);
    }

    public function primaryDomainRecord(): HasOne
    {
        return $this->hasOne(SiteDomain::class)->where('is_primary', true);
    }

    public function deployments(): HasMany
    {
        return $this->hasMany(Deployment::class);
    }

    public function auditLogs(): MorphMany
    {
        return $this->morphMany(AuditLog::class, 'subject');
    }

    public function themeInstallations(): HasMany
    {
        return $this->hasMany(SiteThemeInstallation::class);
    }

    public function activeThemeInstallation(): HasOne
    {
        return $this->hasOne(SiteThemeInstallation::class)->where('is_active', true);
    }

    public function allowedThemes(): BelongsToMany
    {
        return $this->belongsToMany(Theme::class, 'theme_site_access')->withTimestamps();
    }

    public function canTransitionTo(SiteStatus $next): bool
    {
        $current = $this->status ?? SiteStatus::Draft;

        return $current->canTransitionTo($next);
    }

    public function transitionTo(SiteStatus $next): void
    {
        if (! $this->canTransitionTo($next)) {
            $from = ($this->status ?? SiteStatus::Draft)->value;

            throw new LogicException("Cannot transition site status from [{$from}] to [{$next->value}].");
        }

        $this->status = $next;
    }

    public function canBeProvisioned(): bool
    {
        return in_array($this->status, [SiteStatus::Draft, SiteStatus::Error], true);
    }

    public function canSwitchChannel(): bool
    {
        if (blank($this->coolify_app_uuid)) {
            return false;
        }

        return in_array($this->status, [SiteStatus::Active, SiteStatus::Error], true);
    }

    public function hasAgentSecret(): bool
    {
        $raw = $this->getRawOriginal('agent_secret_encrypted');
        if (is_string($raw) && $raw !== '') {
            return true;
        }

        $attribute = $this->attributes['agent_secret_encrypted'] ?? null;

        return is_string($attribute) && $attribute !== '';
    }

    public function resolvedAgentBaseUrl(): ?string
    {
        $base = trim((string) ($this->agent_base_url ?? ''));
        if ($base !== '') {
            return rtrim($base, '/');
        }

        $host = trim((string) ($this->primary_domain ?? ''));
        if ($host === '') {
            return null;
        }

        if (preg_match('#^https?://#i', $host) === 1) {
            return rtrim($host, '/');
        }

        return 'https://'.rtrim($host, '/');
    }

    public function reportedDeamonVersion(): ?string
    {
        $payload = is_array($this->last_health_payload) ? $this->last_health_payload : [];
        $version = $payload['deamon_version'] ?? $payload['version'] ?? null;
        if (! is_string($version) || trim($version) === '') {
            return null;
        }

        return trim($version);
    }

    public const DOCKERFILE_BUILD_PACK_MARKER = 'dockerfile_build_pack';

    /**
     * Coolify fleet import writes this marker into notes when build_pack is dockerfile.
     */
    public function hasDockerfileBuildPackWarning(): bool
    {
        return str_contains((string) $this->notes, self::DOCKERFILE_BUILD_PACK_MARKER);
    }

    /**
     * @param  Builder<Site>  $query
     * @return Builder<Site>
     */
    public function scopeWithDockerfileBuildPackWarning(Builder $query): Builder
    {
        return $query->where('notes', 'like', '%'.self::DOCKERFILE_BUILD_PACK_MARKER.'%');
    }
}
