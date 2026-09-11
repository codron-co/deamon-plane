<?php

namespace App\Http\Controllers\Ops;

use App\Enums\Channel;
use App\Enums\CoolifyGitSourceKind;
use App\Enums\OpsRole;
use App\Enums\SiteStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Ops\Concerns\LoadsSiteOpsContext;
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
use App\Services\Mail\SiteMailConfigureResult;
use App\Services\Mail\SiteMailOrderBinder;
use App\Services\Mail\SiteMailOrderBindResult;
use App\Services\Sites\ChannelSwitcher;
use App\Services\Sites\ChannelSwitchException;
use App\Services\Sites\SiteAgentSecretInjector;
use App\Services\Sites\SiteAppHealthFixer;
use App\Services\Sites\SiteAttacher;
use App\Services\Sites\SiteDomainSync;
use App\Services\Sites\SiteLifecycle;
use App\Services\Sites\SiteLifecycleException;
use App\Services\Sites\SiteProvisioner;
use App\Services\Sites\SiteProvisionException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SiteController extends Controller
{
    use LoadsSiteOpsContext;

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Site::class);

        $search = trim((string) $request->query('q', ''));
        $channel = (string) $request->query('channel', '');
        $status = (string) $request->query('status', '');

        $allowedChannels = config('ops.channels', []);
        $channel = in_array($channel, $allowedChannels, true) ? $channel : '';
        $status = in_array($status, SiteStatus::values(), true) ? $status : '';

        $query = Site::query()
            ->with(['activeThemeInstallation.theme', 'latestDeployment'])
            ->matchingListFilters($search, $channel, $status)
            ->orderBy('name');

        $hasDockerfileSites = (clone $query)->withDockerfileBuildPackWarning()->exists();
        $sites = $query->paginate(25)->withQueryString();
        $bulkPinCommits = $sites->getCollection()
            ->map(fn (Site $site) => $site->latestDeployment)
            ->filter(fn ($deployment): bool => $deployment instanceof Deployment && filled($deployment->commit_sha))
            ->unique(fn (Deployment $deployment): string => (string) $deployment->commit_sha)
            ->values();

        return view('ops.sites.index', [
            'sites' => $sites,
            'search' => $search,
            'channel' => $channel,
            'status' => $status,
            'channels' => $allowedChannels,
            'statuses' => SiteStatus::values(),
            'filtersActive' => $search !== '' || $channel !== '' || $status !== '',
            'hasDockerfileSites' => $hasDockerfileSites,
            'bulkPinCommits' => $bulkPinCommits,
            'canCreate' => $request->user()?->can('create', Site::class) ?? false,
            'canWrite' => $request->user()?->canWriteOps() ?? false,
            'appHealthFixCounts' => $request->user()?->canWriteOps()
                ? app(SiteAppHealthFixer::class)->categoryCounts()
                : [],
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
            $configure = $configurer->sync($site);
            if ($configure->status === 'failed' || $bind->isLookupFailed()) {
                return redirect()
                    ->route('ops.sites.show', $site)
                    ->with('status', $message)
                    ->with('error', $this->mailErrorMessage($bind, $configure));
            }
            $message = $this->appendMailFlash($message, $bind, $configure);
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
            ->with('status', $flash.$this->mailFlashSuffix($binder->bind($site), $configurer->sync($site)));
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
        $configure = $configurer->sync($site);
        if ($configure->status === 'failed' || $bind->isLookupFailed()) {
            return redirect()
                ->route('ops.sites.show', $site)
                ->with('error', $this->mailErrorMessage($bind, $configure));
        }

        return redirect()
            ->route('ops.sites.show', $site)
            ->with('status', __('mail.flash.catalog_refreshed').$this->mailFlashSuffix($bind, $configure));
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
        $configure = $configurer->sync($site);

        $site->auditLogs()->create([
            'actor_user_id' => $request->user()?->id,
            'action' => 'site.mail_assigned',
            'before' => $before,
            'after' => $this->auditSnapshot($site),
            'ip' => $request->ip(),
        ]);

        if ($configure->status === 'failed' || $bind->isLookupFailed()) {
            return redirect()
                ->route('ops.sites.show', $site)
                ->with('error', $this->mailErrorMessage($bind, $configure));
        }

        return redirect()
            ->route('ops.sites.show', $site)
            ->with('status', __('mail.flash.assigned').$this->mailFlashSuffix($bind, $configure));
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

    public function update(UpdateSiteRequest $request, Site $site, SiteMailOrderBinder $binder, SiteMailConfigurer $configurer, SiteDomainSync $domains): RedirectResponse
    {
        $data = $request->validated();
        $site->load(['primaryDomainRecord', 'domains']);
        $previousMailServerId = $site->mail_server_id;
        $previousDomain = $site->primary_domain;

        DB::transaction(function () use ($request, $site, $data, $domains): void {
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
        if ($previousMailServerId !== $site->mail_server_id) {
            $site->mailBindings()->delete();
            $site->unsetRelation('mailBindings');
        }
        if ($previousMailServerId !== $site->mail_server_id || $previousDomain !== $site->primary_domain) {
            $bind = $binder->bind($site);
            $configure = $configurer->sync($site);
            $suffix = $this->mailFlashSuffix($bind, $configure);
            if ($configure->status === 'failed' || $bind->isLookupFailed()) {
                return redirect()
                    ->route('ops.sites.show', $site)
                    ->with('status', __('sites.flash.updated'))
                    ->with('error', $this->mailErrorMessage($bind, $configure));
            }

            return redirect()
                ->route('ops.sites.show', $site)
                ->with('status', __('sites.flash.updated').$suffix);
        }

        return redirect()
            ->route('ops.sites.show', $site)
            ->with('status', __('sites.flash.updated'));
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

    private function appendMailFlash(string $message, SiteMailOrderBindResult $bind, SiteMailConfigureResult $configure): string
    {
        return $message.$this->mailFlashSuffix($bind, $configure);
    }

    private function mailFlashSuffix(SiteMailOrderBindResult $bind, SiteMailConfigureResult $configure): string
    {
        $parts = [];
        if ($bind->isMatched()) {
            $parts[] = (string) __('mail.flash.order_matched', ['domain' => $bind->mailDomain]);
        } elseif ($bind->isUnmatched()) {
            $parts[] = (string) __('mail.flash.order_unmatched');
        }

        if ($configure->status === 'needs_secret') {
            $parts[] = $configure->flashMessage();
        } elseif ($configure->status === 'ok' && ($bind->isMatched() || $bind->status === 'cleared')) {
            $parts[] = $configure->flashMessage();
        }

        return $parts === [] ? '' : ' '.implode(' ', $parts);
    }

    private function assertMailboxRequestForSite(Site $site, SiteMailboxRequest $mailboxRequest): void
    {
        if ($mailboxRequest->site_id !== $site->id) {
            abort(404);
        }
    }

    private function mailErrorMessage(SiteMailOrderBindResult $bind, SiteMailConfigureResult $configure): string
    {
        if ($bind->isLookupFailed()) {
            return (string) __('mail.errors.order_lookup_failed');
        }

        return $configure->flashMessage();
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
