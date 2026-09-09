<?php

namespace App\Http\Controllers\Ops;

use App\Enums\Channel;
use App\Enums\CoolifyGitSourceKind;
use App\Enums\OpsRole;
use App\Enums\SiteStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ops\StoreSiteRequest;
use App\Http\Requests\Ops\SwitchSiteChannelRequest;
use App\Http\Requests\Ops\UpdateSiteRequest;
use App\Models\CoolifyConnection;
use App\Models\CoolifySetting;
use App\Models\Site;
use App\Models\Theme;
use App\Services\Agent\AgentHealthStatus;
use App\Services\Agent\SiteHealthChecker;
use App\Services\Agent\SiteHealthEvaluator;
use App\Services\Sites\ChannelSwitcher;
use App\Services\Sites\ChannelSwitchException;
use App\Services\Sites\SiteAgentSecretInjector;
use App\Services\Sites\SiteAttacher;
use App\Services\Sites\SiteProvisioner;
use App\Services\Sites\SiteProvisionException;
use App\Services\Themes\ThemeVisibilityGate;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SiteController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Site::class);

        $search = trim((string) $request->query('q', ''));
        $channel = (string) $request->query('channel', '');
        $status = (string) $request->query('status', '');

        $allowedChannels = config('ops.channels', []);
        $channel = in_array($channel, $allowedChannels, true) ? $channel : '';
        $status = in_array($status, SiteStatus::values(), true) ? $status : '';

        $query = Site::query()->with('activeThemeInstallation.theme')->orderBy('name');

        if ($search !== '') {
            $term = addcslashes($search, '%_\\');

            $query->where(function ($builder) use ($term): void {
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

        return view('ops.sites.index', [
            'sites' => $query->paginate(25)->withQueryString(),
            'search' => $search,
            'channel' => $channel,
            'status' => $status,
            'channels' => $allowedChannels,
            'statuses' => SiteStatus::values(),
            'filtersActive' => $search !== '' || $channel !== '' || $status !== '',
            'canCreate' => $request->user()?->can('create', Site::class) ?? false,
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', Site::class);

        $default = CoolifyConnection::default();
        $requested = $request->integer('connection');
        $connection = $requested > 0
            ? CoolifyConnection::query()->find($requested)
            : $default;
        if (! $connection instanceof CoolifyConnection) {
            $connection = $default;
        }

        $site = new Site([
            'channel' => Channel::Main,
            'coolify_connection_id' => $connection?->id,
            'coolify_server_uuid' => $connection?->default_server_uuid ?: config('ops.coolify.default_server_uuid'),
            'coolify_project_uuid' => $connection?->default_project_uuid,
            'coolify_environment_uuid' => $connection?->default_environment_uuid,
            'coolify_git_source_uuid' => $connection?->default_git_source_uuid,
            'coolify_git_source_kind' => $connection?->default_git_source_kind,
        ]);

        return view('ops.sites.create', [
            'site' => $site,
            'channels' => config('ops.channels', []),
            'readonly' => false,
            ...$this->coolifyFormData($connection, $request, $site),
        ]);
    }

    public function store(StoreSiteRequest $request, SiteAttacher $attacher): RedirectResponse
    {
        $data = $request->validated();
        $targets = $this->coolifyTargetsFrom($data);

        try {
            $site = DB::transaction(function () use ($request, $data, $targets, $attacher): Site {
                $site = Site::query()->create([
                    'slug' => $data['slug'],
                    'name' => $data['name'],
                    'primary_domain' => $data['domain'],
                    'channel' => $data['channel'],
                    'status' => SiteStatus::Draft,
                    'coolify_connection_id' => $targets['connection_id'],
                    'coolify_server_uuid' => $targets['server_uuid'] ?? $this->defaultServerUuid(),
                    'coolify_project_uuid' => $targets['project_uuid'],
                    'coolify_environment_uuid' => $targets['environment_uuid'],
                    'coolify_git_source_uuid' => $targets['git_uuid'],
                    'coolify_git_source_kind' => $targets['git_kind'],
                    'notes' => $data['notes'] ?? null,
                ]);

                $this->syncPrimaryDomain($site, $data['domain']);

                if (($data['placement'] ?? 'provision') === 'attach' && filled($data['attach_app_uuid'] ?? null)) {
                    $connection = CoolifyConnection::query()->find($targets['connection_id']);
                    if (! $connection instanceof CoolifyConnection) {
                        throw new SiteProvisionException('Mevcut uygulamayı bağlamak için Coolify bağlantısı seçin.');
                    }

                    $attacher->apply($site, $connection, (string) $data['attach_app_uuid']);
                    $site->refresh();
                }

                $site->auditLogs()->create([
                    'actor_user_id' => $request->user()?->id,
                    'action' => 'site.created',
                    'after' => $this->auditSnapshot($site->fresh() ?? $site),
                    'ip' => $request->ip(),
                ]);

                return $site;
            });
        } catch (SiteProvisionException $exception) {
            return redirect()
                ->route('ops.sites.create')
                ->withInput()
                ->with('error', $exception->getMessage());
        }

        $message = $site->coolify_app_uuid
            ? 'Mevcut Coolify uygulaması bağlandı. Yeni stack oluşturulmadı.'
            : 'Draft site saved. Use Provision to create the Coolify compose stack.';

        return redirect()
            ->route('ops.sites.edit', $site)
            ->with('status', $message);
    }

    public function edit(Request $request, Site $site, SiteHealthEvaluator $agentHealth): View
    {
        $this->authorize('view', $site);

        $site->load(['primaryDomainRecord', 'themeInstallations.theme', 'coolifyConnection']);
        $connection = $site->coolifyConnection ?: CoolifyConnection::default();

        return view('ops.sites.edit', [
            'site' => $site,
            'channels' => config('ops.channels', []),
            'readonly' => ! ($request->user()?->can('update', $site) ?? false),
            'canDelete' => $request->user()?->can('delete', $site) ?? false,
            'canProvision' => ($request->user()?->can('provision', $site) ?? false)
                && $site->canBeProvisioned(),
            'canSwitchChannel' => ($request->user()?->can('switchChannel', $site) ?? false)
                && $site->canSwitchChannel(),
            'canForceChannel' => $request->user()?->hasRole(OpsRole::SuperAdmin->value) ?? false,
            'canCheckHealth' => $request->user()?->can('checkHealth', $site) ?? false,
            'canInjectAgentSecret' => ($request->user()?->can('update', $site) ?? false)
                && filled($site->coolify_app_uuid),
            'agentHealth' => $agentHealth,
            'channelSwitchTargets' => $this->channelSwitchTargets($site),
            'channelSwitchInProgress' => $site->status === SiteStatus::Deploying,
            'channelLocked' => $site->status !== SiteStatus::Draft,
            'deployments' => $site->deployments()
                ->with('requestedBy')
                ->latest('id')
                ->limit(25)
                ->get(),
            'coolifyAppUrl' => ($connection ?? CoolifySetting::current())->applicationUiUrl($site->coolify_app_uuid),
            'themeInstallations' => $site->themeInstallations,
            'assignableThemes' => $this->assignableThemes($site),
            'canAssignTheme' => $request->user()?->can('assign', Theme::class) ?? false,
            ...$this->coolifyFormData($connection, $request, $site),
        ]);
    }

    public function switchChannel(SwitchSiteChannelRequest $request, Site $site, ChannelSwitcher $switcher): RedirectResponse
    {
        try {
            $switcher->start(
                $site,
                Channel::from((string) $request->validated('channel')),
                $request->user(),
                $request->ip(),
                $request->boolean('confirmed'),
                $request->boolean('force'),
            );
        } catch (ChannelSwitchException $exception) {
            return redirect()
                ->route('ops.sites.edit', $site)
                ->with('error', $exception->getMessage());
        }

        $site->refresh();

        if ($site->status === SiteStatus::Active) {
            $channel = $site->channel instanceof Channel ? $site->channel->value : $site->channel;

            return redirect()
                ->route('ops.sites.edit', $site)
                ->with('status', 'Channel is now '.$channel.'. Coolify volumes were left in place.');
        }

        if ($site->status === SiteStatus::Error) {
            return redirect()
                ->route('ops.sites.edit', $site)
                ->with('error', 'Channel switch failed. The Coolify app was not deleted. Roll back the branch from this form when Coolify is healthy.');
        }

        $desired = $site->desired_channel instanceof Channel
            ? $site->desired_channel->value
            : $site->desired_channel;

        return redirect()
            ->route('ops.sites.edit', $site)
            ->with('status', 'Channel switch to '.$desired.' started. Coolify will PATCH git_branch and redeploy; volumes persist.');
    }

    public function provision(Request $request, Site $site, SiteProvisioner $provisioner): RedirectResponse
    {
        $this->authorize('provision', $site);

        try {
            $provisioner->start($site, $request->user(), $request->ip());
        } catch (SiteProvisionException $exception) {
            return redirect()
                ->route('ops.sites.edit', $site)
                ->with('error', $exception->getMessage());
        }

        $site->refresh();

        if ($site->status === SiteStatus::Active) {
            return redirect()
                ->route('ops.sites.edit', $site)
                ->with('status', 'Site provisioned. Coolify deploy finished. Use Check health to poll the CMS agent.');
        }

        if ($site->status === SiteStatus::Error) {
            $failed = $site->auditLogs()
                ->where('action', 'site.provision_failed')
                ->latest('id')
                ->first();
            $detail = is_array($failed?->after) ? trim((string) ($failed->after['error'] ?? '')) : '';

            return redirect()
                ->route('ops.sites.edit', $site)
                ->with('error', $detail !== ''
                    ? $detail
                    : 'Provisioning failed. See the audit log. Secrets were not written to logs.');
        }

        return redirect()
            ->route('ops.sites.edit', $site)
            ->with('status', 'Provisioning started. Coolify is creating the compose stack.');
    }

    public function checkHealth(Request $request, Site $site, SiteHealthChecker $checker): RedirectResponse
    {
        $this->authorize('checkHealth', $site);

        $result = $checker->check($site);

        if ($result->status === AgentHealthStatus::NeedsSecret) {
            return redirect()
                ->route('ops.sites.edit', $site)
                ->with('error', $result->safeMessage.' Inject CONTROL_PLANE_AGENT_SECRET on the CMS Coolify app.');
        }

        if ($result->ok) {
            $version = $result->deamonVersion !== null ? ' Deamon '.$result->deamonVersion.'.' : '';

            return redirect()
                ->route('ops.sites.edit', $site)
                ->with('status', 'Agent health OK.'.$version);
        }

        return redirect()
            ->route('ops.sites.edit', $site)
            ->with('error', $result->safeMessage);
    }

    public function injectAgentSecret(Request $request, Site $site, SiteAgentSecretInjector $injector): RedirectResponse
    {
        $this->authorize('update', $site);

        try {
            $injector->inject($site, $request->user(), $request->ip());
        } catch (SiteProvisionException $exception) {
            return redirect()
                ->route('ops.sites.edit', $site)
                ->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('ops.sites.edit', $site)
            ->with('status', 'Agent secret üretildi ve Coolify env’e yazıldı. Secret bir daha gösterilmez.');
    }

    public function update(UpdateSiteRequest $request, Site $site): RedirectResponse
    {
        $data = $request->validated();
        $site->load('primaryDomainRecord');

        DB::transaction(function () use ($request, $site, $data): void {
            $before = $this->auditSnapshot($site);
            $domainChanged = $site->primary_domain !== $data['domain'];

            $targets = $this->coolifyTargetsFrom($data);

            $payload = [
                'name' => $data['name'],
                'primary_domain' => $data['domain'],
                'coolify_connection_id' => $targets['connection_id'],
                'coolify_server_uuid' => $targets['server_uuid'],
                'coolify_project_uuid' => $targets['project_uuid'],
                'coolify_environment_uuid' => $targets['environment_uuid'],
                'coolify_git_source_uuid' => $targets['git_uuid'],
                'coolify_git_source_kind' => $targets['git_kind'],
                'notes' => $data['notes'] ?? null,
            ];

            if ($site->status === SiteStatus::Draft) {
                $payload['slug'] = $data['slug'];
                $payload['channel'] = $data['channel'];
            }

            $site->fill($payload);
            $site->save();

            $this->syncPrimaryDomain($site, $data['domain']);

            $after = $this->auditSnapshot($site->fresh() ?? $site);

            $site->auditLogs()->create([
                'actor_user_id' => $request->user()?->id,
                'action' => 'site.updated',
                'before' => $before,
                'after' => $after,
                'ip' => $request->ip(),
            ]);

            if ($domainChanged) {
                $site->auditLogs()->create([
                    'actor_user_id' => $request->user()?->id,
                    'action' => 'site.domain_changed',
                    'before' => ['primary_domain' => $before['primary_domain']],
                    'after' => ['primary_domain' => $after['primary_domain']],
                    'ip' => $request->ip(),
                ]);
            }
        });

        return redirect()
            ->route('ops.sites.edit', $site)
            ->with('status', 'Site desired state updated. Status is unchanged.');
    }

    public function destroy(Request $request, Site $site): RedirectResponse
    {
        $this->authorize('delete', $site);

        DB::transaction(function () use ($request, $site): void {
            $before = $this->auditSnapshot($site);

            $site->auditLogs()->create([
                'actor_user_id' => $request->user()?->id,
                'action' => 'site.deleted',
                'before' => $before,
                'ip' => $request->ip(),
            ]);

            $site->delete();
        });

        return redirect()
            ->route('ops.sites')
            ->with('status', 'Site archived (soft delete). Coolify was not contacted.');
    }

    private function syncPrimaryDomain(Site $site, string $domain): void
    {
        $primary = $site->primaryDomainRecord;

        if ($primary === null) {
            $site->domains()->create([
                'domain' => $domain,
                'is_primary' => true,
            ]);

            $site->unsetRelation('primaryDomainRecord');

            return;
        }

        if ($primary->domain !== $domain) {
            $primary->update(['domain' => $domain]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function auditSnapshot(Site $site): array
    {
        return [
            'slug' => $site->slug,
            'name' => $site->name,
            'primary_domain' => $site->primary_domain,
            'channel' => $site->channel instanceof Channel ? $site->channel->value : $site->channel,
            'status' => $site->status instanceof SiteStatus ? $site->status->value : $site->status,
            'coolify_server_uuid' => $site->coolify_server_uuid,
            'coolify_connection_id' => $site->coolify_connection_id,
            'coolify_project_uuid' => $site->coolify_project_uuid,
            'notes' => $site->notes,
        ];
    }

    /**
     * @return Collection<int, Theme>
     */
    private function assignableThemes(Site $site)
    {
        $gate = new ThemeVisibilityGate;

        return Theme::query()
            ->orderBy('theme_id')
            ->get()
            ->filter(static fn (Theme $theme): bool => $gate->canAssign($theme, $site))
            ->values();
    }

    private function defaultServerUuid(): ?string
    {
        $fromConnection = trim((string) (CoolifyConnection::default()?->default_server_uuid ?? ''));
        if ($fromConnection !== '') {
            return $fromConnection;
        }

        $uuid = trim((string) config('ops.coolify.default_server_uuid'));

        return $uuid === '' ? null : $uuid;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{connection_id: ?int, server_uuid: ?string, project_uuid: ?string, environment_uuid: ?string, git_uuid: ?string, git_kind: ?CoolifyGitSourceKind}
     */
    private function coolifyTargetsFrom(array $data): array
    {
        $git = is_string($data['coolify_git_source'] ?? null) ? $data['coolify_git_source'] : '';
        $kind = null;
        $gitUuid = null;
        if ($git !== '' && str_contains($git, ':')) {
            [$kindRaw, $gitUuid] = explode(':', $git, 2);
            $kind = CoolifyGitSourceKind::tryFrom($kindRaw);
            $gitUuid = $gitUuid !== '' ? $gitUuid : null;
        }

        $connectionId = isset($data['coolify_connection_id']) && filled($data['coolify_connection_id'])
            ? (int) $data['coolify_connection_id']
            : CoolifyConnection::default()?->id;

        return [
            'connection_id' => $connectionId,
            'server_uuid' => $data['coolify_server_uuid'] ?? null,
            'project_uuid' => $data['coolify_project_uuid'] ?? null,
            'environment_uuid' => $data['coolify_environment_uuid'] ?? null,
            'git_uuid' => $gitUuid,
            'git_kind' => $kind,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function coolifyFormData(?CoolifyConnection $connection, Request $request, ?Site $site = null): array
    {
        $connections = CoolifyConnection::query()
            ->where('is_enabled', true)
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        if ($connection instanceof CoolifyConnection) {
            $connection->load(['servers', 'projects', 'environments', 'gitSources']);
        }

        $selectedProject = old(
            'coolify_project_uuid',
            $site?->coolify_project_uuid ?: $connection?->default_project_uuid,
        );

        return [
            'coolifyConnections' => $connections,
            'coolifyConnection' => $connection,
            'coolifyServers' => $connection?->servers->where('is_active', true)->values() ?? collect(),
            'coolifyProjects' => $connection?->projects->where('is_active', true)->values() ?? collect(),
            'coolifyEnvironments' => $connection?->environmentsForProject(is_string($selectedProject) ? $selectedProject : null) ?? collect(),
            'coolifyEnvironmentOptions' => $connection instanceof CoolifyConnection
                ? $connection->environments
                    ->where('is_active', true)
                    ->values()
                    ->map(static fn ($row): array => [
                        'uuid' => $row->uuid,
                        'project_uuid' => $row->project_uuid,
                        'label' => $row->label(),
                    ])
                    ->all()
                : [],
            'coolifyGitSources' => $connection?->gitSources->where('is_active', true)->values() ?? collect(),
            'attachableApps' => [],
            'githubAppsListAvailable' => $connection?->github_apps_list_available,
            'isSuperAdmin' => $request->user()?->hasRole(OpsRole::SuperAdmin->value) ?? false,
            'optionsUrl' => $connection instanceof CoolifyConnection
                ? route('ops.coolify.options', $connection)
                : null,
        ];
    }

    /**
     * @return list<string>
     */
    private function channelSwitchTargets(Site $site): array
    {
        $current = $site->channel instanceof Channel ? $site->channel->value : (string) $site->channel;

        return array_values(array_filter(
            config('ops.channels', []),
            static fn (string $channel): bool => $channel !== $current,
        ));
    }
}
