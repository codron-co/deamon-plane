<?php

namespace App\Http\Controllers\Ops;

use App\Enums\Channel;
use App\Enums\CmsPublishStatus;
use App\Enums\CoolifyGitSourceKind;
use App\Enums\OpsRole;
use App\Enums\SiteStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Ops\Concerns\LoadsSiteOpsContext;
use App\Http\Controllers\Ops\Concerns\QueuesOpsJob;
use App\Http\Requests\Ops\BulkSiteIdsRequest;
use App\Http\Requests\Ops\StoreSiteRequest;
use App\Http\Requests\Ops\SwitchSiteChannelRequest;
use App\Http\Requests\Ops\UpdateSiteRequest;
use App\Models\CoolifyConnection;
use App\Models\Deployment;
use App\Models\MailServer;
use App\Models\Site;
use App\Models\SiteMailboxRequest;
use App\Services\Agent\AgentHealthStatus;
use App\Services\Agent\SiteHealthChecker;
use App\Services\Cloudflare\CloudflareAccounts;
use App\Services\Coolify\CoolifyApiException;
use App\Services\Hostinger\HostingerMailException;
use App\Services\Mail\PlatformMailConfigurer;
use App\Services\Mail\PlatformNotificationCatalog;
use App\Services\Mail\SiteMailConfigurer;
use App\Services\Mail\SiteMailOrderBinder;
use App\Services\Mail\SiteMailOrderBindResult;
use App\Services\Sites\ChannelSwitcher;
use App\Services\Sites\ChannelSwitchException;
use App\Services\Sites\SiteAgentSecretInjector;
use App\Services\Sites\SiteAgentSecretSweep;
use App\Services\Sites\SiteAppHealthFixer;
use App\Services\Sites\SiteAttacher;
use App\Services\Sites\SiteDomainSync;
use App\Services\Sites\SiteLanding;
use App\Services\Sites\SiteLifecycle;
use App\Services\Sites\SiteLifecycleException;
use App\Services\Sites\SiteProvisioner;
use App\Services\Sites\SiteProvisionException;
use App\Support\Lists\ListFragment;
use App\Support\Lists\SiteListView;
use App\Support\Lists\SiteSavedViews;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SiteController extends Controller
{
    use LoadsSiteOpsContext;
    use QueuesOpsJob;

    public function index(Request $request): Response|RedirectResponse
    {
        $this->authorize('viewAny', Site::class);

        $savedViews = SiteSavedViews::resolve($request, $request->user());
        if ($savedViews->applyDefaultRedirect) {
            return redirect()->to($savedViews->redirectUrl());
        }
        $savedViews->rememberColumns($request->user());

        $search = $savedViews->filters['q'] ?? '';
        $channel = $savedViews->filters['channel'] ?? '';
        $status = $savedViews->filters['status'] ?? '';
        $publish = $savedViews->filters['publish'] ?? '';
        $deploy = $savedViews->filters['deploy'] ?? '';
        $agent = $savedViews->filters['agent'] ?? '';
        $pack = $savedViews->filters['pack'] ?? '';
        $health = $savedViews->filters['health'] ?? '';
        $app = $savedViews->filters['app'] ?? '';

        $allowedChannels = config('ops.channels', []);
        $publishFilters = [...CmsPublishStatus::values(), 'unknown'];
        // The label carries the window, so the option and the chip say the same
        // thing the fleet card says instead of an open-ended "failed".
        $deployFilters = [];
        foreach (Site::DEPLOY_FILTERS as $deployOption) {
            $deployFilters[$deployOption] = (string) __('sites.deploy_states.'.$deployOption, [
                'hours' => Deployment::failedWindowHours(),
            ]);
        }
        $agentFilters = [];
        foreach (Site::AGENT_FILTERS as $agentOption) {
            $agentFilters[$agentOption] = (string) __('sites.agent_states.'.$agentOption);
        }
        $healthFilters = [];
        foreach (Site::HEALTH_FILTERS as $healthOption) {
            $healthFilters[$healthOption] = (string) __('sites.health_states.'.$healthOption);
        }
        $appFilters = [];
        foreach (Site::APP_FILTERS as $appOption) {
            $appFilters[$appOption] = (string) __('sites.app_states.'.$appOption);
        }

        $listView = SiteListView::resolve($request, $request->user(), $savedViews);
        $listView->rememberSort($request->user());

        $query = Site::query()
            ->with(['activeThemeInstallation.theme', 'latestDeployment'])
            ->matchingListFilters($search, $channel, $status, $publish, $deploy, $agent, $pack, $health, $app);

        // A search reaches alias hosts, so a matched row must be able to say which host
        // matched. Only loaded while searching: one extra query instead of 25.
        if ($search !== '') {
            $query->with('domains');
        }

        $hasDockerfileSites = (clone $query)->withDockerfileBuildPackWarning()->exists();
        $sites = $listView->applySort($query)->paginate(25)->withQueryString();
        $activeFilters = $this->activeListFilters($search, $channel, $status, $publish, $deploy, $deployFilters, $agent, $pack, $health, $app);
        $bulkPinCommits = $this->bulkPinSuggestions($sites);

        /*
         * Fleet-wide fix counts only feed the page header menu, and the toolbar
         * re-requests this URL on every keystroke, filter, sort and page. A region
         * response never renders that menu, so the fleet scan must not run for one.
         */
        $appHealthCounts = ['counts' => [], 'computed_at' => null];
        if (! ListFragment::wanted($request) && ($request->user()?->canWriteOps() ?? false)) {
            $appHealthCounts = app(SiteAppHealthFixer::class)->cachedCategoryCounts();
        }

        return ListFragment::respond($request, 'ops.sites.index', 'ops.sites._region', [
            'sites' => $sites,
            'search' => $search,
            'channel' => $channel,
            'status' => $status,
            'publish' => $publish,
            'deploy' => $deploy,
            'agent' => $agent,
            'pack' => $pack,
            'health' => $health,
            'app' => $app,
            'savedViews' => $savedViews,
            'channels' => $allowedChannels,
            'statuses' => SiteStatus::values(),
            'publishFilters' => $publishFilters,
            'deployFilters' => $deployFilters,
            'agentFilters' => $agentFilters,
            'healthFilters' => $healthFilters,
            'appFilters' => $appFilters,
            'listView' => $listView,
            'filtersActive' => $activeFilters !== [],
            'activeFilters' => $activeFilters,
            // Distinguishes "no sites yet" from "no sites match" without a second filtered query.
            'totalSites' => $sites->total() > 0 ? $sites->total() : Site::query()->count(),
            'hasDockerfileSites' => $hasDockerfileSites,
            'bulkPinCommits' => $bulkPinCommits,
            'canCreate' => $request->user()?->can('create', Site::class) ?? false,
            'canWrite' => $request->user()?->canWriteOps() ?? false,
            'appHealthFixCounts' => $appHealthCounts['counts'],
            'appHealthCountsAt' => $appHealthCounts['computed_at'],
        ]);
    }

    /**
     * The filters currently narrowing the list, each with the URL that drops only
     * that one. Empty when the operator is looking at the whole fleet.
     *
     * @param  array<string, string>  $deployFilters  Deploy filter key => operator-facing label.
     * @return list<array{key: string, label: string, value: string, url: string}>
     */
    private function activeListFilters(string $search, string $channel, string $status, string $publish, string $deploy = '', array $deployFilters = [], string $agent = '', string $pack = '', string $health = '', string $app = ''): array
    {
        $applied = array_filter([
            'q' => $search,
            'channel' => $channel,
            'status' => $status,
            'publish' => $publish,
            'deploy' => $deploy,
            'agent' => $agent,
            'pack' => $pack,
            'health' => $health,
            'app' => $app,
        ], static fn (string $value): bool => $value !== '');

        $labels = [
            'q' => __('sites.filter_search'),
            'channel' => __('sites.filter_branch'),
            'status' => __('sites.filter_status'),
            'publish' => __('sites.filter_publish'),
            'deploy' => __('sites.filter_deploy'),
            'agent' => __('sites.filter_agent'),
            'pack' => __('sites.filter_pack'),
            'health' => __('sites.filter_health'),
            'app' => __('sites.filter_app'),
        ];

        $displayed = [
            'q' => $search,
            'channel' => $channel,
            'status' => $status === '' ? '' : __('ops.site_status.'.$status),
            'publish' => $publish === '' ? '' : __('sites.publish.states.'.$publish),
            'deploy' => $deployFilters[$deploy] ?? '',
            'agent' => $agent === '' ? '' : __('sites.agent_states.'.$agent),
            'pack' => $pack === '' ? '' : __('sites.pack_states.'.$pack),
            'health' => $health === '' ? '' : __('sites.health_states.'.$health),
            'app' => $app === '' ? '' : __('sites.app_states.'.$app),
        ];

        $chips = [];
        foreach ($applied as $key => $value) {
            $chips[] = [
                'key' => $key,
                'label' => $labels[$key],
                'value' => $displayed[$key],
                'url' => SiteSavedViews::withoutFilter($applied, $key),
            ];
        }

        return $chips;
    }

    /**
     * Commits offered as pin shortcuts. Empty when the filtered set spills past
     * this page — a dropdown built from 25 of 200 sites would silently omit the rest.
     *
     * @param  LengthAwarePaginator<int, Site>  $sites
     * @return Collection<int, Deployment>
     */
    private function bulkPinSuggestions($sites): Collection
    {
        if ($sites->hasMorePages()) {
            return collect();
        }

        return $sites->getCollection()
            ->map(fn (Site $site) => $site->latestDeployment)
            ->filter(fn ($deployment): bool => $deployment instanceof Deployment && filled($deployment->commit_sha))
            ->unique(fn (Deployment $deployment): string => (string) $deployment->commit_sha)
            ->values();
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
            'cloudflare_setting_id' => CloudflareAccounts::default()?->id,
        ]);

        return view('ops.sites.create', [
            'site' => $site,
            'channels' => config('ops.channels', []),
            'readonly' => false,
            'mailServers' => $this->mailServerOptions(),
            'cloudflareAccounts' => CloudflareAccounts::enabled(),
            ...$this->coolifyFormData($connection, $request, $site),
        ]);
    }

    public function store(StoreSiteRequest $request, SiteAttacher $attacher, SiteMailOrderBinder $binder, SiteMailConfigurer $configurer, SiteDomainSync $domains): RedirectResponse
    {
        $data = $request->validated();
        $targets = $this->coolifyTargetsFrom($data);

        try {
            $site = DB::transaction(function () use ($request, $data, $targets, $attacher, $domains): Site {
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
                    'mail_server_id' => $data['mail_server_id'] ?? null,
                    'cloudflare_setting_id' => $data['cloudflare_setting_id'] ?? null,
                ]);

                $domains->sync($site, $data['domain'], $data['aliases'] ?? []);

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
            ? __('sites.flash.attached')
            : __('sites.flash.created');

        if (filled($site->mail_server_id)) {
            $bind = $binder->bind($site);
            if ($bind->isLookupFailed()) {
                return redirect()
                    ->route('ops.sites.show', $site)
                    ->with('status', $message)
                    ->with('error', __('mail.errors.order_lookup_failed'));
            }
            $message .= $this->mailQueueSuffix($site, $bind, $configurer);
        }

        return redirect()
            ->route('ops.sites.show', $site)
            ->with('status', $message);
    }

    public function edit(Request $request, Site $site): View
    {
        $this->authorize('view', $site);

        $site->load(['primaryDomainRecord', 'domains', 'coolifyConnection', 'mailBindings']);
        $connection = $site->coolifyConnection ?: CoolifyConnection::default();

        return view('ops.sites.edit', [
            'site' => $site,
            'channels' => config('ops.channels', []),
            'readonly' => ! ($request->user()?->can('update', $site) ?? false),
            'channelLocked' => $site->status !== SiteStatus::Draft,
            'mailServers' => $this->mailServerOptions(),
            'cloudflareAccounts' => CloudflareAccounts::enabled(),
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
                ->route('ops.sites.show', $site)
                ->with('error', $exception->getMessage());
        }

        $site->refresh();

        if ($site->status === SiteStatus::Active) {
            $channel = $site->channel instanceof Channel ? $site->channel->value : $site->channel;

            return redirect()
                ->route('ops.sites.show', $site)
                ->with('status', __('sites.flash.channel_now', ['channel' => $channel]));
        }

        if ($site->status === SiteStatus::Error) {
            return redirect()
                ->route('ops.sites.show', $site)
                ->with('error', __('sites.flash.channel_failed'));
        }

        $desired = $site->desired_channel instanceof Channel
            ? $site->desired_channel->value
            : $site->desired_channel;

        return redirect()
            ->route('ops.sites.show', $site)
            ->with('status', __('sites.flash.channel_started', ['channel' => $desired]));
    }

    public function provision(Request $request, Site $site, SiteProvisioner $provisioner): RedirectResponse
    {
        $this->authorize('provision', $site);

        try {
            $provisioner->start($site, $request->user(), $request->ip());
        } catch (SiteProvisionException $exception) {
            return redirect()
                ->route('ops.sites.show', $site)
                ->with('error', $exception->getMessage());
        }

        $site->refresh();

        if ($site->status === SiteStatus::Active) {
            return redirect()
                ->route('ops.sites.show', $site)
                ->with('status', __('sites.flash.provisioned'));
        }

        if ($site->status === SiteStatus::Error) {
            $failed = $site->auditLogs()
                ->where('action', 'site.provision_failed')
                ->latest('id')
                ->first();
            $detail = is_array($failed?->after) ? trim((string) ($failed->after['error'] ?? '')) : '';

            return redirect()
                ->route('ops.sites.show', $site)
                ->with('error', $detail !== ''
                    ? $detail
                    : __('sites.flash.provision_failed'));
        }

        return redirect()
            ->route('ops.sites.show', $site)
            ->with('status', __('sites.flash.provision_started'));
    }

    public function checkHealth(Request $request, Site $site, SiteHealthChecker $checker): RedirectResponse
    {
        $this->authorize('checkHealth', $site);

        $result = $checker->check($site);

        if ($result->status === AgentHealthStatus::NeedsSecret) {
            return redirect()
                ->route('ops.sites.show', $site)
                ->with('error', $result->safeMessage.' Inject CONTROL_PLANE_AGENT_SECRET on the CMS Coolify app.');
        }

        if ($result->ok) {
            $status = $result->deamonVersion !== null
                ? __('sites.flash.health_ok_version', ['version' => $result->deamonVersion])
                : __('sites.flash.health_ok');

            return redirect()
                ->route('ops.sites.show', $site)
                ->with('status', $status);
        }

        return redirect()
            ->route('ops.sites.show', $site)
            ->with('error', $result->safeMessage);
    }

    public function injectAgentSecret(Request $request, Site $site, SiteAgentSecretInjector $injector, SiteMailOrderBinder $binder, SiteMailConfigurer $configurer): RedirectResponse
    {
        $this->authorize('update', $site);

        $rotate = $site->hasAgentSecret();

        try {
            $injector->inject($site, $request->user(), $request->ip(), $rotate);
        } catch (SiteProvisionException $exception) {
            return redirect()
                ->route('ops.sites.show', $site)
                ->with('error', $exception->getMessage());
        }

        $flash = $rotate ? __('sites.flash.secret_rotated') : __('sites.flash.secret_injected');

        return redirect()
            ->route('ops.sites.show', $site)
            ->with('status', $flash.$this->mailQueueSuffix($site, $binder->bind($site), $configurer));
    }

    public function bulkInjectAgentSecret(BulkSiteIdsRequest $request, SiteAgentSecretSweep $sweep): JsonResponse|RedirectResponse
    {
        $sites = $this->sitesFromBulk($request);

        foreach ($sites as $site) {
            $this->authorize('update', $site);
        }

        $targets = $sites->filter(static fn (Site $site): bool => ! $site->hasAgentSecret())->values();

        if ($targets->isEmpty()) {
            return back()->with('error', __('sites.agent.bulk_empty'));
        }

        if ($request->expectsJson()) {
            return $this->queueOpsJob($request, 'sites.bulk_inject_agent_secret', __('ops.jobs.bulk_inject_agent_secret'), [
                'site_ids' => $targets->pluck('id')->all(),
                'ip' => $request->ip(),
            ]);
        }

        $result = $sweep->run($targets, $request->user(), $request->ip());
        $unfinished = ($result['failed'] ?? 0) > 0 || ($result['waiting'] ?? 0) > 0;

        return back()->with($unfinished ? 'error' : 'status', $sweep->summarize($result));
    }

    /**
     * @return Collection<int, Site>
     */
    private function sitesFromBulk(BulkSiteIdsRequest $request): Collection
    {
        if ($request->boolean('all')) {
            return Site::query()
                ->matchingListFilters(
                    trim((string) $request->input('filter_q', '')),
                    (string) $request->input('filter_channel', ''),
                    (string) $request->input('filter_status', ''),
                    (string) $request->input('filter_publish', ''),
                    (string) $request->input('filter_deploy', ''),
                    (string) $request->input('filter_agent', ''),
                    (string) $request->input('filter_pack', ''),
                    (string) $request->input('filter_health', ''),
                    (string) $request->input('filter_app', ''),
                )
                ->orderBy('name')
                ->get();
        }

        return Site::query()
            ->whereIn('id', $request->validated('site_ids') ?? [])
            ->orderBy('name')
            ->get();
    }

    public function refreshMailOrder(Request $request, Site $site, SiteMailOrderBinder $binder, SiteMailConfigurer $configurer): RedirectResponse
    {
        $this->authorize('update', $site);

        $site->loadMissing('mailServer');
        if ($site->mailServer === null) {
            return redirect()
                ->route('ops.sites.show', $site)
                ->with('error', __('mail.errors.order_lookup_failed'));
        }

        try {
            $binder->refreshCatalog($site->mailServer);
        } catch (HostingerMailException) {
            return redirect()
                ->route('ops.sites.show', $site)
                ->with('error', __('mail.errors.order_lookup_failed'));
        }

        $site->load('mailBindings');
        $bind = $site->mailBindings->isEmpty()
            ? $binder->bind($site)
            : SiteMailOrderBindResult::matched(
                (string) $site->mailBindings->first()->hostinger_order_id,
                (string) $site->mailBindings->first()->mail_domain,
            );
        if ($bind->isLookupFailed()) {
            return redirect()
                ->route('ops.sites.show', $site)
                ->with('error', __('mail.errors.order_lookup_failed'));
        }

        return redirect()
            ->route('ops.sites.show', $site)
            ->with('status', __('mail.flash.catalog_refreshed').$this->mailQueueSuffix($site, $bind, $configurer));
    }

    /**
     * Re-push the mail binding to the CMS after a failed or missing configure.
     */
    public function resendMailConfigure(Request $request, Site $site, SiteMailConfigurer $configurer): RedirectResponse
    {
        $this->authorize('update', $site);

        if (! $site->hasAgentSecret()) {
            return redirect()
                ->route('ops.sites.show', $site)
                ->with('error', __('mail.flash.needs_secret'));
        }

        $configurer->queue($site);

        $site->auditLogs()->create([
            'actor_user_id' => $request->user()?->id,
            'action' => 'site.mail_configure_requeued',
            'after' => ['mail_domains' => $site->mailDomains()],
            'ip' => $request->ip(),
        ]);

        return redirect()
            ->route('ops.sites.show', $site)
            ->with('status', __('mail.flash.configure_queued'));
    }

    public function assignMail(Request $request, Site $site, SiteMailOrderBinder $binder, SiteMailConfigurer $configurer): RedirectResponse
    {
        $this->authorize('update', $site);

        $request->merge([
            'mail_server_id' => filled($request->input('mail_server_id')) ? $request->input('mail_server_id') : null,
        ]);

        $validated = $request->validate([
            'mail_server_id' => ['nullable', 'string', Rule::exists('mail_servers', 'id')],
            'mail_bindings_explicit' => ['sometimes', 'boolean'],
            'hostinger_order_ids' => ['sometimes', 'array'],
            'hostinger_order_ids.*' => ['nullable', 'string', 'max:128'],
        ]);

        $before = $this->auditSnapshot($site);
        $previousServerId = $site->mail_server_id;
        $site->mail_server_id = $validated['mail_server_id'] ?? null;
        $site->save();

        if ($previousServerId !== $site->mail_server_id) {
            $site->mailBindings()->delete();
            $site->unsetRelation('mailBindings');
        }

        $bind = $site->mail_server_id === null
            ? $binder->clear($site)
            : ($request->boolean('mail_bindings_explicit')
                ? $binder->bindSelected($site, $validated['hostinger_order_ids'] ?? [])
                : $binder->bind($site));
        $site->refresh();

        $site->auditLogs()->create([
            'actor_user_id' => $request->user()?->id,
            'action' => 'site.mail_assigned',
            'before' => $before,
            'after' => $this->auditSnapshot($site),
            'ip' => $request->ip(),
        ]);

        if ($bind->isLookupFailed()) {
            return redirect()
                ->route('ops.sites.show', $site)
                ->with('error', __('mail.errors.order_lookup_failed'));
        }

        return redirect()
            ->route('ops.sites.show', $site)
            ->with('status', __('mail.flash.assigned').$this->mailQueueSuffix($site, $bind, $configurer));
    }

    public function assignPlatformMail(Request $request, Site $site, PlatformMailConfigurer $configurer): RedirectResponse
    {
        $this->authorize('update', $site);

        $validated = $request->validate([
            'platform_mail_recipient' => ['nullable', 'email', 'max:255'],
            'notifications' => ['nullable', 'array'],
        ]);

        $before = $this->auditSnapshot($site);
        $defaults = PlatformNotificationCatalog::defaultNotifications();
        $input = is_array($validated['notifications'] ?? null) ? $validated['notifications'] : [];
        $overrides = [];

        foreach ($defaults as $key => $defaultRow) {
            if (! array_key_exists($key, $input) || ! is_array($input[$key])) {
                continue;
            }
            $row = $input[$key];
            $merged = [
                'enabled' => (bool) ($row['enabled'] ?? false),
            ];
            if ($key === PlatformNotificationCatalog::WEEKLY_VISITOR_REPORT) {
                $merged['day'] = max(0, min(6, (int) ($row['day'] ?? $defaultRow['day'] ?? 1)));
                $merged['hour'] = max(0, min(23, (int) ($row['hour'] ?? $defaultRow['hour'] ?? 8)));
            }
            if ($key === PlatformNotificationCatalog::SITE_VERSION_UPDATE) {
                $on = (string) ($row['on'] ?? $defaultRow['on'] ?? 'patch');
                $merged['on'] = in_array($on, ['major', 'minor', 'patch'], true) ? $on : 'patch';
            }
            $overrides[$key] = $merged;
        }

        $site->platform_mail_recipient = filled($validated['platform_mail_recipient'] ?? null)
            ? (string) $validated['platform_mail_recipient']
            : null;
        $site->platform_notification_overrides = $overrides;
        $site->save();

        $site->auditLogs()->create([
            'actor_user_id' => $request->user()?->id,
            'action' => 'platform_mail.site_override_updated',
            'before' => $before,
            'after' => $this->auditSnapshot($site),
            'ip' => $request->ip(),
        ]);

        $configure = $configurer->sync($site);
        $status = __('platform_mail.flash.site_saved');
        if ($configure->status === 'needs_secret') {
            $status .= ' '.__('mail.flash.needs_secret');
        } elseif ($configure->status === 'failed') {
            $status .= ' '.__('mail.flash.configure_failed');
        }

        return redirect()
            ->route('ops.sites.show', $site)
            ->with('status', $status);
    }

    public function fulfillMailboxRequest(Request $request, Site $site, SiteMailboxRequest $mailboxRequest): RedirectResponse
    {
        $this->authorize('update', $site);
        $this->assertMailboxRequestForSite($site, $mailboxRequest);

        $mailboxRequest->status = SiteMailboxRequest::STATUS_FULFILLED;
        $mailboxRequest->save();

        $site->auditLogs()->create([
            'actor_user_id' => $request->user()?->id,
            'action' => 'mail.mailbox_request_fulfilled',
            'after' => $mailboxRequest->toPublicArray(),
            'ip' => $request->ip(),
        ]);

        return redirect()
            ->route('ops.sites.show', $site)
            ->with('status', __('mail.flash.request_fulfilled', ['email' => $mailboxRequest->email()]));
    }

    public function rejectMailboxRequest(Request $request, Site $site, SiteMailboxRequest $mailboxRequest): RedirectResponse
    {
        $this->authorize('update', $site);
        $this->assertMailboxRequestForSite($site, $mailboxRequest);

        $mailboxRequest->status = SiteMailboxRequest::STATUS_REJECTED;
        $mailboxRequest->save();

        $site->auditLogs()->create([
            'actor_user_id' => $request->user()?->id,
            'action' => 'mail.mailbox_request_rejected',
            'after' => $mailboxRequest->toPublicArray(),
            'ip' => $request->ip(),
        ]);

        return redirect()
            ->route('ops.sites.show', $site)
            ->with('status', __('mail.flash.request_rejected', ['email' => $mailboxRequest->email()]));
    }

    public function update(UpdateSiteRequest $request, Site $site, SiteMailOrderBinder $binder, SiteMailConfigurer $configurer, SiteDomainSync $domains, SiteLanding $landing): RedirectResponse
    {
        $data = $request->validated();
        $site->load(['primaryDomainRecord', 'domains']);
        $previousMailServerId = $site->mail_server_id;
        $previousDomain = $site->primary_domain;
        $previousHosts = $site->operatorHosts();
        $removedHosts = [];

        DB::transaction(function () use ($request, $site, $data, $domains, &$removedHosts): void {
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
                'mail_server_id' => $data['mail_server_id'] ?? null,
                'cloudflare_setting_id' => $data['cloudflare_setting_id'] ?? null,
            ];

            if ($site->status === SiteStatus::Draft) {
                $payload['slug'] = $data['slug'];
                $payload['channel'] = $data['channel'];
            }

            $site->fill($payload);
            $site->save();

            $domains->sync($site, $data['domain'], $data['aliases'] ?? []);
            $removedHosts = $domains->lastRemoved;

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

        $site->refresh();
        $message = (string) __('sites.flash.updated');
        $error = null;

        // Rows dropped from the form still have Cloudflare A records; release them
        // (best effort: the Plane change already landed).
        $dnsError = $landing->releaseHostDns($site, $removedHosts);
        if ($dnsError !== null) {
            $error = __('sites.flash.domain_removed_partial', ['reason' => $dnsError]);
        }

        // The form is desired state, but a host list change must reach Cloudflare and
        // Coolify like the detail-page Add domain does — otherwise the alias only exists in Plane.
        if ($previousHosts !== $site->operatorHosts() && filled($site->coolify_app_uuid)) {
            try {
                $landing->applyAliasDns($site);
                $outcome = $landing->bindAndRedeploy($site, $request->user(), $request->ip());
                $message .= SiteLanding::bindFlashSuffix($outcome);
            } catch (SiteProvisionException $exception) {
                $error = $exception->getMessage();
            }
            $site->refresh();
        }

        if ($previousMailServerId !== $site->mail_server_id) {
            $site->mailBindings()->delete();
            $site->unsetRelation('mailBindings');
        }
        if ($previousMailServerId !== $site->mail_server_id || $previousDomain !== $site->primary_domain) {
            $bind = $binder->bind($site);
            if ($bind->isLookupFailed()) {
                $error = $error ?? __('mail.errors.order_lookup_failed');
            } else {
                $message .= $this->mailQueueSuffix($site, $bind, $configurer);
            }
        }

        $redirect = redirect()
            ->route('ops.sites.show', $site)
            ->with('status', $message);

        return $error !== null ? $redirect->with('error', $error) : $redirect;
    }

    public function activate(Request $request, Site $site, SiteLifecycle $lifecycle): RedirectResponse
    {
        $this->authorize('update', $site);

        try {
            $lifecycle->activate($site, $request->user(), $request->ip());
        } catch (SiteLifecycleException|CoolifyApiException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', __('sites.flash.activated'));
    }

    public function deactivate(Request $request, Site $site, SiteLifecycle $lifecycle): RedirectResponse
    {
        $this->authorize('update', $site);

        try {
            $lifecycle->deactivate($site, $request->user(), $request->ip());
        } catch (SiteLifecycleException|CoolifyApiException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', __('sites.flash.deactivated'));
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
            ->with('status', __('sites.flash.archived'));
    }

    public function purge(Request $request, Site $site, SiteLifecycle $lifecycle): RedirectResponse
    {
        $this->authorize('forceDelete', $site);

        try {
            $lifecycle->purge($site, $request->user(), $request->ip());
        } catch (SiteLifecycleException|CoolifyApiException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('ops.sites')
            ->with('status', __('sites.flash.purged'));
    }

    /**
     * @return array<string, mixed>
     */
    private function auditSnapshot(Site $site): array
    {
        $site->loadMissing('mailBindings');

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
            'mail_server_id' => $site->mail_server_id,
            'hostinger_order_id' => $site->hostinger_order_id,
            'mail_domain' => $site->mail_domain,
            'mail_domains' => $site->mailDomains(),
            'cloudflare_setting_id' => $site->cloudflare_setting_id,
        ];
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
     * @return Collection<int, MailServer>
     */
    private function mailServerOptions()
    {
        return MailServer::query()->where('is_enabled', true)->orderBy('name')->get();
    }

    /**
     * Queue the CMS mail configure (never in the request: the CMS may run its module
     * migrations on first configure) and describe what the operator should expect.
     */
    private function mailQueueSuffix(Site $site, SiteMailOrderBindResult $bind, SiteMailConfigurer $configurer): string
    {
        $parts = [];
        if ($bind->isMatched()) {
            $parts[] = (string) __('mail.flash.order_matched', ['domain' => $bind->mailDomain]);
        } elseif ($bind->isUnmatched()) {
            $parts[] = (string) __('mail.flash.order_unmatched');
        }

        if (! $site->hasAgentSecret()) {
            $parts[] = (string) __('mail.flash.needs_secret');
        } else {
            $configurer->queue($site);
            $parts[] = (string) __('mail.flash.configure_queued');
        }

        return ' '.implode(' ', $parts);
    }

    private function assertMailboxRequestForSite(Site $site, SiteMailboxRequest $mailboxRequest): void
    {
        if ($mailboxRequest->site_id !== $site->id) {
            abort(404);
        }
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
            'attachableApps' => $connection instanceof CoolifyConnection
                ? app(SiteAttacher::class)->attachableApps($connection)
                : [],
            'githubAppsListAvailable' => $connection?->github_apps_list_available,
            'isSuperAdmin' => $request->user()?->hasRole(OpsRole::SuperAdmin->value) ?? false,
            'optionsUrl' => $connection instanceof CoolifyConnection
                ? route('ops.coolify.options', $connection)
                : null,
        ];
    }
}
