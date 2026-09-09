<?php

namespace App\Models;

use App\Enums\CoolifyGitSourceKind;
use App\Services\Coolify\CoolifyCredentials;
use Database\Factories\CoolifyConnectionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CoolifyConnection extends Model
{
    /** @use HasFactory<CoolifyConnectionFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'base_url',
        'api_token',
        'webhook_secret',
        'is_enabled',
        'is_default',
        'default_project_uuid',
        'default_server_uuid',
        'default_environment_uuid',
        'default_environment_name',
        'default_git_source_uuid',
        'default_git_source_kind',
        'github_apps_list_available',
        'last_synced_at',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'api_token',
        'webhook_secret',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'api_token' => 'encrypted',
            'webhook_secret' => 'encrypted',
            'is_enabled' => 'boolean',
            'is_default' => 'boolean',
            'github_apps_list_available' => 'boolean',
            'last_synced_at' => 'datetime',
            'default_git_source_kind' => CoolifyGitSourceKind::class,
        ];
    }

    public function servers(): HasMany
    {
        return $this->hasMany(CoolifyServer::class);
    }

    public function projects(): HasMany
    {
        return $this->hasMany(CoolifyProjectRecord::class);
    }

    public function environments(): HasMany
    {
        return $this->hasMany(CoolifyEnvironment::class);
    }

    /**
     * Active environments for one Coolify project. Empty project → none (never a global dump).
     *
     * @return Collection<int, CoolifyEnvironment>
     */
    public function environmentsForProject(?string $projectUuid)
    {
        $projectUuid = trim((string) $projectUuid);
        if ($projectUuid === '') {
            return collect();
        }

        return $this->environments
            ->where('is_active', true)
            ->where('project_uuid', $projectUuid)
            ->values();
    }

    /**
     * Single active server / project / env / git source becomes the default and is persisted.
     */
    public function applyUnambiguousDefaults(): bool
    {
        $this->loadMissing(['servers', 'projects', 'environments', 'gitSources']);

        $changed = false;

        $serverUuid = $this->soleActiveUuid($this->servers->where('is_active', true)->values(), $this->default_server_uuid);
        if ($serverUuid !== $this->default_server_uuid) {
            $this->default_server_uuid = $serverUuid;
            $changed = true;
        }

        $projectUuid = $this->soleActiveUuid($this->projects->where('is_active', true)->values(), $this->default_project_uuid);
        if ($projectUuid !== $this->default_project_uuid) {
            $this->default_project_uuid = $projectUuid;
            $changed = true;
        }

        $projectEnvs = $this->environmentsForProject($this->default_project_uuid);
        $envUuid = $this->soleActiveUuid($projectEnvs, $this->default_environment_uuid);
        $envName = $envUuid !== null
            ? trim((string) ($projectEnvs->firstWhere('uuid', $envUuid)?->name ?? ''))
            : null;
        $envName = $envName !== '' ? $envName : null;

        if ($envUuid !== $this->default_environment_uuid || $envName !== $this->default_environment_name) {
            $this->default_environment_uuid = $envUuid;
            $this->default_environment_name = $envName;
            $changed = true;
        }

        $sources = $this->gitSources->where('is_active', true)->values();
        if ($sources->count() === 1) {
            $source = $sources->first();
            $kind = $source->kind instanceof CoolifyGitSourceKind
                ? $source->kind
                : CoolifyGitSourceKind::tryFrom((string) $source->kind);
            if ($this->default_git_source_uuid !== $source->uuid || $this->default_git_source_kind !== $kind) {
                $this->default_git_source_uuid = $source->uuid;
                $this->default_git_source_kind = $kind;
                $changed = true;
            }
        } elseif (filled($this->default_git_source_uuid)
            && ! $sources->contains(fn (CoolifyGitSource $row): bool => $row->uuid === $this->default_git_source_uuid)) {
            $this->default_git_source_uuid = null;
            $this->default_git_source_kind = null;
            $changed = true;
        }

        if ($changed) {
            $this->save();
        }

        return $changed;
    }

    /**
     * @param  Collection<int, CoolifyServer|CoolifyProjectRecord|CoolifyEnvironment>  $rows
     */
    private function soleActiveUuid($rows, ?string $current): ?string
    {
        if ($rows->count() === 1) {
            return $rows->first()->uuid;
        }

        if (filled($current) && $rows->contains(static fn ($row): bool => $row->uuid === $current)) {
            return $current;
        }

        return null;
    }

    public function gitSources(): HasMany
    {
        return $this->hasMany(CoolifyGitSource::class);
    }

    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }

    public function hasToken(): bool
    {
        return filled($this->api_token);
    }

    public function hasWebhookSecret(): bool
    {
        return filled($this->webhook_secret);
    }

    public function credentials(): CoolifyCredentials
    {
        $base = filled($this->base_url)
            ? (string) $this->base_url
            : (string) config('ops.coolify.base_url', '');

        $token = filled($this->api_token)
            ? (string) $this->api_token
            : (string) config('ops.coolify.api_token', '');

        return new CoolifyCredentials($base, $token);
    }

    public static function default(): ?self
    {
        return static::query()
            ->where('is_enabled', true)
            ->where('is_default', true)
            ->first()
            ?? static::query()->where('is_enabled', true)->orderBy('id')->first();
    }

    public function markAsDefault(): void
    {
        DB::transaction(function (): void {
            static::query()->whereKeyNot($this->id)->update(['is_default' => false]);
            $this->forceFill(['is_default' => true, 'is_enabled' => true])->save();
        });
    }

    public function applicationUiUrl(?string $applicationUuid, ?string $projectUuid = null, ?string $environment = null): ?string
    {
        $base = filled($this->base_url)
            ? (string) $this->base_url
            : (string) config('ops.coolify.base_url');
        $base = CoolifyCredentials::normalizeBaseUrl((string) $base);

        if ($base === '' || blank($applicationUuid)) {
            return null;
        }

        $project = $projectUuid
            ?: (filled($this->default_project_uuid) ? (string) $this->default_project_uuid : (string) config('ops.coolify.default_project_uuid'));
        $environment ??= filled($this->default_environment_name)
            ? (string) $this->default_environment_name
            : (string) config('ops.provision.environment_name', 'production');

        if ($project !== '') {
            return $base.'/project/'.$project.'/environment/'.$environment.'/application/'.$applicationUuid;
        }

        return $base;
    }
}
