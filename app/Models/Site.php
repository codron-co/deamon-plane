<?php

namespace App\Models;

use App\Enums\Channel;
use App\Enums\CoolifyGitSourceKind;
use App\Enums\SiteStatus;
use App\Services\Cloudflare\CloudflareHostname;
use App\Services\Sites\SiteAppHealthReport;
use App\Support\IdentityMark;
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
        'temporary_domain',
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
        'last_live_http_status',
        'last_live_checked_at',
        'last_live_favicon_url',
        'last_app_health_at',
        'last_app_health_payload',
        'cloudflare_zone_id',
        'cloudflare_nameservers',
        'cloudflare_zone_status',
        'cloudflare_setting_id',
        'dns_applied_at',
        'mail_server_id',
        'hostinger_order_id',
        'mail_domain',
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
            'last_live_http_status' => 'integer',
            'last_live_checked_at' => 'datetime',
            'last_app_health_at' => 'datetime',
            'last_app_health_payload' => 'array',
            'cloudflare_nameservers' => 'array',
            'dns_applied_at' => 'datetime',
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

    public function mailServer(): BelongsTo
    {
        return $this->belongsTo(MailServer::class);
    }

    public function cloudflareAccount(): BelongsTo
    {
        return $this->belongsTo(CloudflareSetting::class, 'cloudflare_setting_id');
    }

    /**
     * Coolify UI deep link. Path uses project + environment uuids, never the env/channel name.
     */
    public function coolifyUiUrl(): ?string
    {
        $connection = $this->coolifyConnection ?: CoolifyConnection::default();
        if ($connection instanceof CoolifyConnection) {
            return $connection->applicationUiUrl(
                $this->coolify_app_uuid,
                $this->coolify_project_uuid,
                $this->coolify_environment_uuid,
            );
        }

        return CoolifySetting::current()->applicationUiUrl(
            $this->coolify_app_uuid,
            null,
            $this->coolify_project_uuid,
            $this->coolify_environment_uuid,
        );
    }

    public function domains(): HasMany
    {
        return $this->hasMany(SiteDomain::class);
    }

    public function primaryDomainRecord(): HasOne
    {
        return $this->hasOne(SiteDomain::class)->where('is_primary', true);
    }

    public function isWaitingOnDns(): bool
    {
        return filled($this->temporary_domain)
            || ! CloudflareHostname::zoneIsReady($this->cloudflare_zone_status);
    }

    /**
     * Operator hostnames (no temporary preview). Primary first, then www, then aliases.
     *
     * @return list<string>
     */
    public function operatorHosts(): array
    {
        $rows = $this->relationLoaded('domains') ? $this->domains : $this->domains()->get();
        $stored = $rows
            ->where('is_temporary', false)
            ->pluck('domain')
            ->filter(fn (mixed $domain): bool => is_string($domain) && $domain !== '')
            ->map(fn (string $domain): string => CloudflareHostname::host($domain))
            ->unique()
            ->values();

        $primary = CloudflareHostname::normalize((string) $this->primary_domain);
        if ($stored->isEmpty() && $primary !== '') {
            $stored = collect([$primary, CloudflareHostname::wwwHost($primary)])->filter()->values();
        }

        $wwwOfPrimary = $primary !== '' ? CloudflareHostname::wwwHost($primary) : '';
        $ordered = [];
        if ($primary !== '' && $stored->contains($primary)) {
            $ordered[] = $primary;
        }
        if ($wwwOfPrimary !== '' && $stored->contains($wwwOfPrimary)) {
            $ordered[] = $wwwOfPrimary;
        }

        foreach ($stored as $host) {
            if (! in_array($host, $ordered, true)) {
                $ordered[] = $host;
            }
        }

        return $ordered;
    }

    /**
     * Coolify `app` domain list: comma-separated https hosts.
     */
    public function coolifyDomainBinding(): string
    {
        $hosts = $this->isWaitingOnDns() && filled($this->temporary_domain)
            ? [CloudflareHostname::host((string) $this->temporary_domain)]
            : $this->operatorHosts();

        return implode(',', array_values(array_filter($hosts)));
    }

    /**
     * Extra operator hosts shown on the form (not primary, not www, not temp).
     *
     * @return list<string>
     */
    public function aliasHosts(): array
    {
        $primary = CloudflareHostname::normalize((string) $this->primary_domain);

        return array_values(array_filter(
            $this->operatorHosts(),
            static function (string $host) use ($primary): bool {
                return $host !== $primary && ! str_starts_with($host, 'www.');
            },
        ));
    }

    public function deployments(): HasMany
    {
        return $this->hasMany(Deployment::class);
    }

    public function latestDeployment(): HasOne
    {
        return $this->hasOne(Deployment::class)->latestOfMany();
    }

    /**
     * Last Coolify/Cloudflare failure, redacted. Empty when the latest deployment has no error.
     */
    public function appHealth(): SiteAppHealthReport
    {
        return SiteAppHealthReport::forDisplay($this);
    }

    public function lastFailureMessage(): ?string
    {
        $deployment = $this->relationLoaded('latestDeployment')
            ? $this->latestDeployment
            : ($this->relationLoaded('deployments')
                ? $this->deployments->first()
                : $this->deployments()->latest('id')->first());

        if (filled($deployment?->error_message)) {
            return trim((string) $deployment->error_message);
        }

        $audit = $this->auditLogs()
            ->whereIn('action', ['site.provision_failed', 'site.channel_switch_failed'])
            ->latest('id')
            ->first();

        $detail = is_array($audit?->after) ? trim((string) ($audit->after['error'] ?? '')) : '';

        return $detail !== '' ? $detail : null;
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

    public function canBeActivated(): bool
    {
        return $this->canStartCoolify();
    }

    public function canBeDeactivated(): bool
    {
        return $this->canStopCoolify();
    }

    public function canSwitchChannel(): bool
    {
        if (blank($this->coolify_app_uuid)) {
            return false;
        }

        return in_array($this->status, [SiteStatus::Active, SiteStatus::Error], true);
    }

    public function canStartCoolify(): bool
    {
        return filled($this->coolify_app_uuid) && $this->status === SiteStatus::Stopped;
    }

    public function canStopCoolify(): bool
    {
        return filled($this->coolify_app_uuid) && $this->status === SiteStatus::Active;
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

    public function hasHostingerMailOrder(): bool
    {
        return filled($this->hostinger_order_id) && filled($this->mail_domain);
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

    public function identityMarkLetter(): string
    {
        $source = trim((string) $this->name);
        if ($source === '') {
            $source = trim((string) $this->primary_domain);
        }

        return IdentityMark::letter($source);
    }

    public function liveHttpLabel(): string
    {
        if ($this->last_live_checked_at === null || $this->last_live_http_status === null) {
            return __('ops.none');
        }

        $status = (int) $this->last_live_http_status;
        if ($status === 0) {
            return __('sites.live.down');
        }

        return (string) $status;
    }

    public function liveHttpTone(): string
    {
        $status = $this->last_live_http_status;
        if ($this->last_live_checked_at === null || $status === null) {
            return 'unknown';
        }

        $status = (int) $status;
        if ($status >= 200 && $status < 400) {
            return 'ok';
        }
        if ($status === 404) {
            return 'needs_secret';
        }

        return 'unhealthy';
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

    /**
     * Plane installation wins; otherwise the last signed health payload.
     */
    public function reportedActiveThemeId(): ?string
    {
        $installed = $this->activeThemeInstallation?->theme?->theme_id;
        if (is_string($installed) && trim($installed) !== '') {
            return trim($installed);
        }

        $payload = is_array($this->last_health_payload) ? $this->last_health_payload : [];
        $reported = $payload['active_theme_id'] ?? null;
        if (! is_string($reported) || trim($reported) === '') {
            return null;
        }

        return trim($reported);
    }

    /**
     * @return 'installation'|'health'|null
     */
    public function reportedThemeSource(): ?string
    {
        $installed = $this->activeThemeInstallation?->theme?->theme_id;
        if (is_string($installed) && trim($installed) !== '') {
            return 'installation';
        }

        return $this->reportedActiveThemeId() !== null ? 'health' : null;
    }

    public function healthReportedThemeId(): ?string
    {
        $payload = is_array($this->last_health_payload) ? $this->last_health_payload : [];
        $reported = $payload['active_theme_id'] ?? null;
        if (! is_string($reported) || trim($reported) === '') {
            return null;
        }

        return trim($reported);
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

    /**
     * @param  Builder<Site>  $query
     * @return Builder<Site>
     */
    public function scopeMatchingListFilters(Builder $query, string $search = '', string $channel = '', string $status = ''): Builder
    {
        $allowedChannels = config('ops.channels', []);
        $channel = in_array($channel, $allowedChannels, true) ? $channel : '';
        $status = in_array($status, SiteStatus::values(), true) ? $status : '';

        if ($search !== '') {
            $term = addcslashes($search, '%_\\');
            $query->where(function (Builder $builder) use ($term): void {
                $builder->where('name', 'like', "%{$term}%")
                    ->orWhere('slug', 'like', "%{$term}%")
                    ->orWhere('primary_domain', 'like', "%{$term}%");
            });
        }

        if ($channel !== '') {
            $query->where('channel', $channel);
        }

        if ($status !== '') {
            $query->where('status', $status);
        }

        return $query;
    }

    public function clearDockerfileBuildPackWarning(): void
    {
        $notes = (string) $this->notes;
        $pattern = '/(?:^|\n)\[import\]\s*'.preg_quote(self::DOCKERFILE_BUILD_PACK_MARKER, '/').':[^\n]*/';
        $cleaned = trim((string) preg_replace($pattern, '', $notes));
        $this->notes = $cleaned === '' ? null : $cleaned;
    }
}
