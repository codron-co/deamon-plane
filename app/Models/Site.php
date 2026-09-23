<?php

namespace App\Models;

use App\Enums\Channel;
use App\Enums\CmsPublishStatus;
use App\Enums\CoolifyGitSourceKind;
use App\Enums\DeploymentStatus;
use App\Enums\SiteStatus;
use App\Services\Agent\AgentHealthStatus;
use App\Services\Agent\ControlPlaneAgentContract;
use App\Services\Cloudflare\CloudflareHostname;
use App\Services\Sites\SiteAppHealthReport;
use App\Services\Sites\SiteFilterVerdict;
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
        'cms_site_status',
        'cms_site_status_at',
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
        'mail_configured_at',
        'mail_configure_failed_at',
        'mail_configure_error',
        'mail_configure_message',
        'platform_notification_overrides',
        'platform_mail_recipient',
        'platform_mail_pushed_at',
        'platform_mail_push_failed_at',
        'platform_mail_push_error',
        'last_notified_deamon_version',
        'last_health_notify_status',
        'last_health_notify_at',
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
            'cms_site_status' => CmsPublishStatus::class,
            'cms_site_status_at' => 'datetime',
            'app_key_encrypted' => 'encrypted',
            'agent_secret_encrypted' => 'encrypted',
            'last_health_at' => 'datetime',
            'last_health_payload' => 'array',
            'health_unhealthy' => 'boolean',
            'health_verdict_at' => 'datetime',
            'last_live_http_status' => 'integer',
            'last_live_checked_at' => 'datetime',
            'last_app_health_at' => 'datetime',
            'last_app_health_payload' => 'array',
            'app_has_issues' => 'boolean',
            'app_health_issue_count' => 'integer',
            'app_health_verdict_at' => 'datetime',
            'cloudflare_nameservers' => 'array',
            'dns_applied_at' => 'datetime',
            'platform_notification_overrides' => 'array',
            'platform_mail_pushed_at' => 'datetime',
            'platform_mail_push_failed_at' => 'datetime',
            'mail_configured_at' => 'datetime',
            'mail_configure_failed_at' => 'datetime',
            'last_health_notify_at' => 'datetime',
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

            // ADR-10: stamp the list-filter columns on the same save as the
            // payload / status they are derived from. Never walk the fleet.
            if ($site->isDirty(['status', 'last_health_at', 'last_health_payload', 'agent_secret_encrypted'])) {
                SiteFilterVerdict::applyHealth($site);
            }

            if ($site->isDirty([
                'last_app_health_payload',
                'last_app_health_at',
                'last_health_payload',
                'last_health_at',
                'agent_secret_encrypted',
                'notes',
                'coolify_app_uuid',
            ])) {
                SiteFilterVerdict::applyApp($site);
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

    /**
     * @return HasMany<SiteMailBinding, $this>
     */
    public function mailBindings(): HasMany
    {
        return $this->hasMany(SiteMailBinding::class)->orderBy('mail_domain');
    }

    /**
     * @return HasMany<SiteMailboxRequest, $this>
     */
    public function mailboxRequests(): HasMany
    {
        return $this->hasMany(SiteMailboxRequest::class)->latest();
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
    /**
     * Alias hosts on their own Cloudflare zone that still waits for nameservers,
     * one entry per zone so the detail page can show the NS to hand the customer.
     *
     * @return list<array{host: string, zone_status: ?string, nameservers: list<string>}>
     */
    public function aliasZonesPending(): array
    {
        $rows = $this->relationLoaded('domains') ? $this->domains : $this->domains()->get();
        $out = [];

        foreach ($rows->where('is_temporary', false)->sortBy('is_www') as $row) {
            if (! $row->zonePending()) {
                continue;
            }

            $zoneId = (string) $row->cloudflare_zone_id;
            if (isset($out[$zoneId])) {
                continue;
            }

            $ns = is_array($row->cloudflare_nameservers) ? array_values($row->cloudflare_nameservers) : [];
            $out[$zoneId] = [
                'host' => (string) $row->domain,
                'zone_status' => $row->cloudflare_zone_status,
                'nameservers' => array_values(array_filter($ns, is_string(...))),
            ];
        }

        return array_values($out);
    }

    /**
     * Last CMS mail configure outcome: none | needs_secret | failed | configured | not_pushed.
     */
    public function mailConfigureState(): string
    {
        if (blank($this->mail_server_id)) {
            return 'none';
        }

        if (! $this->hasAgentSecret()) {
            return 'needs_secret';
        }

        if ($this->mail_configure_failed_at !== null) {
            return 'failed';
        }

        if ($this->mail_configured_at !== null) {
            return 'configured';
        }

        return 'not_pushed';
    }

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
                ? $this->deployments->sortByDesc('id')->first()
                : $this->deployments()->latest('id')->first());

        // Finished rows can still carry a stale poll-timeout message; only surface real failures.
        if (
            $deployment !== null
            && in_array($deployment->status, [DeploymentStatus::Failed, DeploymentStatus::Cancelled], true)
            && filled($deployment->error_message)
        ) {
            return trim((string) $deployment->error_message);
        }

        // A successful latest deploy means the site recovered; ignore older audit errors for the banner.
        if ($deployment?->status === DeploymentStatus::Finished) {
            return null;
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

    /**
     * Coolify accepts docker_compose_domains only after compose is loaded from a
     * real deploy. Draft / provisioning / failed-provision sites must not PATCH.
     */
    public function canBindCoolifyDomains(): bool
    {
        if (blank($this->coolify_app_uuid)) {
            return false;
        }

        $status = $this->status instanceof SiteStatus
            ? $this->status
            : SiteStatus::tryFrom((string) $this->status);

        return in_array($status, [
            SiteStatus::Active,
            SiteStatus::Deploying,
            SiteStatus::Stopped,
        ], true);
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
        if ($this->relationLoaded('mailBindings') && $this->mailBindings->isNotEmpty()) {
            return true;
        }

        return filled($this->hostinger_order_id) && filled($this->mail_domain);
    }

    /**
     * @return list<string>
     */
    public function mailDomains(): array
    {
        if ($this->relationLoaded('mailBindings') && $this->mailBindings->isNotEmpty()) {
            return $this->mailBindings
                ->pluck('mail_domain')
                ->filter()
                ->map(static fn (mixed $domain): string => strtolower(trim((string) $domain)))
                ->unique()
                ->values()
                ->all();
        }

        $domain = strtolower(trim((string) ($this->mail_domain ?? '')));

        return $domain === '' ? [] : [$domain];
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

    /**
     * CMS publish state, or null when the agent has never reported one.
     */
    public function publishStatus(): ?CmsPublishStatus
    {
        return $this->cms_site_status instanceof CmsPublishStatus
            ? $this->cms_site_status
            : CmsPublishStatus::tryFrom((string) $this->cms_site_status);
    }

    public function publishLabel(): string
    {
        return $this->publishStatus()?->label() ?? __('sites.publish.states.unknown');
    }

    public function publishTone(): string
    {
        return $this->publishStatus()?->tone() ?? 'unknown';
    }

    /**
     * Publish state can only be changed through the signed agent, so a site with
     * no secret has no write path — the operator has to inject one first.
     */
    public function canChangePublishStatus(): bool
    {
        return $this->hasAgentSecret() && $this->resolvedAgentBaseUrl() !== null;
    }

    /**
     * True when the reported CMS keeps site edits on theme merge and accepts overwrite.
     * An unknown version is treated as old: a wrong "yes" loses the owner's edits.
     */
    public function supportsEditSafeThemeSync(): bool
    {
        $reported = $this->reportedDeamonVersion();
        if ($reported === null) {
            return false;
        }

        return version_compare(
            ltrim($reported, 'vV'),
            ControlPlaneAgentContract::THEME_SYNC_EDIT_SAFE_VERSION,
            '>=',
        );
    }

    /**
     * True when the reported CMS keeps site-edited theme files on `/themes/update`.
     * Unknown version counts as old, same as supportsEditSafeThemeSync().
     */
    public function keepsThemeFileCustomizationsOnUpdate(): bool
    {
        $reported = $this->reportedDeamonVersion();
        if ($reported === null) {
            return false;
        }

        return version_compare(
            ltrim($reported, 'vV'),
            ControlPlaneAgentContract::THEME_UPDATE_KEEPS_CUSTOMIZATIONS_VERSION,
            '>=',
        );
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
     * Deploy-state list filters. `failed` is the set the fleet failed-deploy card
     * counts, so «Tümünü gör» lands on exactly the sites behind that number.
     *
     * @var list<string>
     */
    public const DEPLOY_FILTERS = ['failed'];

    /**
     * Agent-secret list filters. `missing` / `unverified` / `ok` are the three
     * buckets the fleet card counts, so «Tümünü gör» lands on exactly those sites.
     *
     * @var list<string>
     */
    public const AGENT_FILTERS = ['missing', 'unverified', 'ok'];

    /**
     * Build-pack list filters. `dockerfile` is the leftover set the fleet
     * attention card lists, so the built-in «Dockerfile kalanları» view lands
     * on exactly those sites.
     *
     * @var list<string>
     */
    public const PACK_FILTERS = ['dockerfile'];

    /**
     * Agent-health list filters. `unhealthy` is the set the fleet unhealthy
     * card counts, so «Tümünü gör» lands on exactly those sites.
     *
     * @var list<string>
     */
    public const HEALTH_FILTERS = ['unhealthy'];

    /**
     * App-health list filters. `issues` is the set the header Fix App issues
     * menu can drill into — sites with at least one needed fix.
     *
     * @var list<string>
     */
    public const APP_FILTERS = ['issues'];

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
    public function scopeMatchingListFilters(Builder $query, string $search = '', string $channel = '', string $status = '', string $publish = '', string $deploy = '', string $agent = '', string $pack = '', string $health = '', string $app = ''): Builder
    {
        $allowedChannels = config('ops.channels', []);
        $channel = in_array($channel, $allowedChannels, true) ? $channel : '';
        $status = in_array($status, SiteStatus::values(), true) ? $status : '';
        $publish = in_array($publish, [...CmsPublishStatus::values(), 'unknown'], true) ? $publish : '';
        $deploy = in_array($deploy, self::DEPLOY_FILTERS, true) ? $deploy : '';
        $agent = in_array($agent, self::AGENT_FILTERS, true) ? $agent : '';
        $pack = in_array($pack, self::PACK_FILTERS, true) ? $pack : '';
        $health = in_array($health, self::HEALTH_FILTERS, true) ? $health : '';
        $app = in_array($app, self::APP_FILTERS, true) ? $app : '';

        if ($search !== '') {
            $term = addcslashes($search, '%_\\');
            /*
             * The operator pastes whatever they have in hand: a www host, an alias, the
             * temporary preview host, or the Coolify app uuid. `site_domains` carries every
             * host, so it is reached with an exists subquery — a join would return the same
             * site once per matching alias.
             */
            $query->where(function (Builder $builder) use ($term, $search): void {
                $builder->where('name', 'like', "%{$term}%")
                    ->orWhere('slug', 'like', "%{$term}%")
                    ->orWhere('primary_domain', 'like', "%{$term}%")
                    ->orWhereHas('domains', function (Builder $domains) use ($term): void {
                        $domains->where('domain', 'like', "%{$term}%");
                    })
                    ->orWhere('coolify_app_uuid', $search);
            });
        }

        if ($channel !== '') {
            $query->where('channel', $channel);
        }

        if ($status !== '') {
            $query->where('status', $status);
        }

        if ($publish === 'unknown') {
            $query->whereNull('cms_site_status');
        } elseif ($publish !== '') {
            $query->where('cms_site_status', $publish);
        }

        if ($deploy === 'failed') {
            // `whereHas`, not a join: a site with five failed retries is one row.
            $query->whereHas('deployments', static fn (Builder $deployments): Builder => $deployments->failedInWindow());
        }

        if ($agent === 'missing') {
            $query->missingAgentSecret();
        } elseif ($agent === 'unverified') {
            $query->unverifiedAgentSecret();
        } elseif ($agent === 'ok') {
            $query->verifiedAgentSecret();
        }

        if ($pack === 'dockerfile') {
            $query->withDockerfileBuildPackWarning();
        }

        if ($health === 'unhealthy') {
            $query->unhealthy();
        }

        if ($app === 'issues') {
            $query->withAppIssues();
        }

        return $query;
    }

    /**
     * Fleet unhealthy KPI and `/sites?health=unhealthy`. One SQL predicate:
     * persisted verdict, status=error, stored agent-fail signals, or stale.
     *
     * @param  Builder<Site>  $query
     * @return Builder<Site>
     */
    public function scopeUnhealthy(Builder $query): Builder
    {
        return SiteFilterVerdict::constrainUnhealthy($query);
    }

    /**
     * Sites whose last inspect/health write left at least one needed App fix.
     *
     * @param  Builder<Site>  $query
     * @return Builder<Site>
     */
    public function scopeWithAppIssues(Builder $query): Builder
    {
        return $query->where('app_has_issues', true);
    }

    /**
     * Plane has no CONTROL_PLANE_AGENT_SECRET stored for this site.
     *
     * @param  Builder<Site>  $query
     * @return Builder<Site>
     */
    public function scopeMissingAgentSecret(Builder $query): Builder
    {
        // Encrypted column: a stored secret is ciphertext, never ''. Null is the
        // only "no secret" row import and draft-create leave behind.
        return $query->whereNull('agent_secret_encrypted');
    }

    /**
     * A stored secret the CMS has accepted with HTTP 200 (or status=ok).
     *
     * @param  Builder<Site>  $query
     * @return Builder<Site>
     */
    public function scopeVerifiedAgentSecret(Builder $query): Builder
    {
        return $query->whereNotNull('agent_secret_encrypted')
            ->where(function (Builder $verified): void {
                $verified->where('last_health_payload->http_status', 200)
                    ->orWhere('last_health_payload->status', AgentHealthStatus::Ok);
            });
    }

    /**
     * A stored secret the CMS has never answered 200 for.
     *
     * @param  Builder<Site>  $query
     * @return Builder<Site>
     */
    public function scopeUnverifiedAgentSecret(Builder $query): Builder
    {
        return $query->whereNotNull('agent_secret_encrypted')
            ->where(function (Builder $unverified): void {
                $unverified->where(function (Builder $http): void {
                    $http->whereNull('last_health_payload->http_status')
                        ->orWhere('last_health_payload->http_status', '!=', 200);
                })->where(function (Builder $status): void {
                    $status->whereNull('last_health_payload->status')
                        ->orWhere('last_health_payload->status', '!=', AgentHealthStatus::Ok);
                });
            });
    }

    /**
     * @return 'missing'|'unverified'|'ok'
     */
    public function agentSecretFleetState(): string
    {
        if (! $this->hasAgentSecret()) {
            return 'missing';
        }

        return $this->agentSecretIsVerified() ? 'ok' : 'unverified';
    }

    /**
     * CMS answered 200 for this secret (or stored status=ok from that path).
     */
    public function agentSecretIsVerified(): bool
    {
        if (! $this->hasAgentSecret()) {
            return false;
        }

        $payload = is_array($this->last_health_payload) ? $this->last_health_payload : [];
        if (($payload['status'] ?? null) === AgentHealthStatus::Ok) {
            return true;
        }

        return (int) ($payload['http_status'] ?? 0) === 200;
    }

    /**
     * Why this row is in a search result when its visible identity does not contain
     * the term: an alias host, or the Coolify app uuid that was pasted. Returns
     * `null` when the match is already on screen, so the row stays quiet.
     *
     * @return array{type: 'alias'|'uuid', value: string}|null
     */
    public function searchMatchReason(string $search): ?array
    {
        $term = mb_strtolower(trim($search));
        if ($term === '') {
            return null;
        }

        foreach ([$this->name, $this->slug, $this->primary_domain] as $visible) {
            if (str_contains(mb_strtolower((string) $visible), $term)) {
                return null;
            }
        }

        if (filled($this->coolify_app_uuid) && mb_strtolower((string) $this->coolify_app_uuid) === $term) {
            return ['type' => 'uuid', 'value' => (string) $this->coolify_app_uuid];
        }

        $rows = $this->relationLoaded('domains') ? $this->domains : $this->domains()->get();
        foreach ($rows as $row) {
            $host = (string) $row->domain;
            if ($host !== '' && str_contains(mb_strtolower($host), $term)) {
                return ['type' => 'alias', 'value' => $host];
            }
        }

        return null;
    }

    public function clearDockerfileBuildPackWarning(): void
    {
        $notes = (string) $this->notes;
        $pattern = '/(?:^|\n)\[import\]\s*'.preg_quote(self::DOCKERFILE_BUILD_PACK_MARKER, '/').':[^\n]*/';
        $cleaned = trim((string) preg_replace($pattern, '', $notes));
        $this->notes = $cleaned === '' ? null : $cleaned;
    }
}
