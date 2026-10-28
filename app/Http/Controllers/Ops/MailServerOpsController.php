<?php

namespace App\Http\Controllers\Ops;

use App\Enums\MailProvider;
use App\Http\Controllers\Controller;
use App\Models\MailServer;
use App\Models\Site;
use App\Models\SiteComailDomain;
use App\Services\Comail\ComailSiteConfigurer;
use App\Services\Comail\MailcowClient;
use App\Services\Comail\MailcowException;
use App\Services\Hostinger\HostingerMailException;
use App\Services\Mail\PlatformMailState;
use App\Services\Mail\SiteMailConfigurer;
use App\Services\Mail\SiteMailOrderBinder;
use App\Support\Lists\ListFragment;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class MailServerOpsController extends Controller
{
    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('q', ''));
        $status = (string) $request->query('status', '');
        $status = in_array($status, ['enabled', 'disabled'], true) ? $status : '';

        $query = MailServer::query()->withCount('sites')->orderBy('name');

        if ($search !== '') {
            $term = addcslashes($search, '%_\\');
            $query->where(function ($builder) use ($term): void {
                $builder->where('name', 'like', "%{$term}%")
                    ->orWhere('mail_domain', 'like', "%{$term}%");
            });
        }

        if ($status === 'enabled') {
            $query->where('is_enabled', true);
        } elseif ($status === 'disabled') {
            $query->where('is_enabled', false);
        }

        $servers = $query->paginate(25)->withQueryString();
        // CoMail is attached through CoMail domains, never through sites.mail_server_id.
        $servers->getCollection()
            ->filter(fn (MailServer $server): bool => $server->provider === MailProvider::Mailcow)
            ->each(fn (MailServer $server) => $server->sites_count = SiteComailDomain::query()->distinct()->count('site_id'));
        $activeFilters = $this->activeListFilters($search, $status);

        return ListFragment::respond($request, 'ops.mail-servers.index', 'ops.mail-servers._region', [
            'servers' => $servers,
            'search' => $search,
            'status' => $status,
            'filtersActive' => $activeFilters !== [],
            'activeFilters' => $activeFilters,
            'totalServers' => $servers->total() > 0 ? $servers->total() : MailServer::query()->count(),
            'canWrite' => $request->user()?->can('ops.write') ?? false,
            'platformMailState' => PlatformMailState::current(),
        ]);
    }

    /**
     * @return list<array{key: string, label: string, value: string, url: string}>
     */
    private function activeListFilters(string $search, string $status): array
    {
        $applied = array_filter([
            'q' => $search,
            'status' => $status,
        ], static fn (string $value): bool => $value !== '');

        $labels = [
            'q' => __('mail.filter_search'),
            'status' => __('mail.filter_status'),
        ];
        $displayed = [
            'q' => $search,
            'status' => match ($status) {
                'enabled' => __('ops.enabled'),
                'disabled' => __('ops.disabled'),
                default => '',
            },
        ];

        $chips = [];
        foreach ($applied as $key => $value) {
            $chips[] = [
                'key' => $key,
                'label' => $labels[$key],
                'value' => $displayed[$key],
                'url' => route('ops.mail-servers.index', array_diff_key($applied, [$key => null])),
            ];
        }

        return $chips;
    }

    public function create(): View
    {
        $this->authorize('ops.write');

        return view('ops.mail-servers.create', [
            'server' => new MailServer([
                'provider' => MailProvider::Hostinger,
                'is_enabled' => true,
            ]),
            'canWrite' => true,
            'requireToken' => true,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('ops.write');

        $validated = $this->validated($request, requireToken: true);
        if ($validated['provider'] === MailProvider::Mailcow->value) {
            // The CoMail key administers every customer mailbox.
            $this->authorize('ops.danger');
        }
        $server = new MailServer;
        $this->fillServer($server, $validated);
        $server->save();

        $this->audit($server, $request, 'mail_server.created', null, $this->auditSnapshot($server));

        return redirect()
            ->route('ops.mail-servers.show', $server)
            ->with('status', __('mail.flash.saved'));
    }

    public function show(MailServer $mailServer): View
    {
        $mailServer->loadCount('sites');

        return view('ops.mail-servers.show', [
            'server' => $mailServer,
            'canWrite' => request()->user()?->can('ops.write') ?? false,
            'canDanger' => request()->user()?->can('ops.danger') ?? false,
            'hasToken' => $mailServer->hasToken(),
            'sites' => $mailServer->sites()->with('mailBindings')->orderBy('name')->get(),
            'isComail' => $mailServer->provider === MailProvider::Mailcow,
            'comailDomains' => $mailServer->provider === MailProvider::Mailcow
                ? SiteComailDomain::query()->with('site:id,name,slug')->orderBy('domain')->get()
                : collect(),
        ]);
    }

    public function update(Request $request, MailServer $mailServer, SiteMailConfigurer $configurer): RedirectResponse
    {
        // Holds the Hostinger token every bound site's mailboxes go through.
        $this->authorize('ops.danger');

        $before = $this->auditSnapshot($mailServer);
        $validated = $this->validated($request, requireToken: false, server: $mailServer);
        $this->fillServer($mailServer, $validated);
        $mailServer->save();
        $this->audit($mailServer, $request, 'mail_server.updated', $before, $this->auditSnapshot($mailServer));

        if ($mailServer->provider === MailProvider::Mailcow) {
            // Enabling, disabling or moving CoMail changes what every enrolled CMS shows.
            $this->requeueComailSites();

            return back()->with('status', __('mail.flash.saved'));
        }

        $configurer->syncAssignedSites($mailServer);

        return back()->with('status', __('mail.flash.saved'));
    }

    public function destroy(Request $request, MailServer $mailServer, SiteMailConfigurer $configurer): RedirectResponse
    {
        $this->authorize('ops.danger');

        $sites = $mailServer->sites()->get();
        $before = $this->auditSnapshot($mailServer);

        foreach ($sites as $site) {
            $site->mailBindings()->delete();
            $site->mail_server_id = null;
            $site->hostinger_order_id = null;
            $site->mail_domain = null;
            $site->save();
        }

        $this->audit($mailServer, $request, 'mail_server.deleted', $before, null);
        $isComail = $mailServer->provider === MailProvider::Mailcow;
        $mailServer->delete();
        $configurer->disableSites($sites);
        if ($isComail) {
            $this->requeueComailSites();
        }

        return redirect()
            ->route('ops.mail-servers.index')
            ->with('status', __('mail.flash.deleted'));
    }

    public function test(Request $request, MailServer $mailServer, SiteMailOrderBinder $binder, SiteMailConfigurer $configurer): RedirectResponse
    {
        $this->authorize('ops.write');

        if (! $mailServer->hasToken()) {
            return back()->with('error', __('mail.errors.not_configured'));
        }

        if ($mailServer->provider === MailProvider::Mailcow) {
            return $this->testComail($request, $mailServer);
        }

        try {
            $orders = $binder->refreshCatalog($mailServer);
        } catch (HostingerMailException) {
            return back()->with('error', __('mail.errors.test_failed'));
        }

        $binder->bindAssignedSites($mailServer, $orders);
        $configurer->syncAssignedSites($mailServer);

        $this->audit($mailServer, $request, 'mail_server.tested', null, [
            'order_count' => count($orders),
        ]);

        return back()->with('status', __('mail.flash.tested', ['count' => count($orders)]));
    }

    /**
     * CoMail test = mailcow version call (the key is allowlisted to Plane's IP).
     */
    private function testComail(Request $request, MailServer $mailServer): RedirectResponse
    {
        try {
            $version = MailcowClient::forServer($mailServer)->version();
        } catch (MailcowException) {
            return back()->with('error', __('mail.errors.comail_test_failed'));
        }

        $version = mb_substr($version, 0, 40);
        $mailServer->forceFill([
            'last_probe_at' => now(),
            'last_probe_payload' => ['version' => $version],
        ])->save();

        $this->audit($mailServer, $request, 'mail_server.tested', null, ['version' => $version]);

        return back()->with('status', __('mail.flash.comail_tested', ['version' => $version !== '' ? $version : '?']));
    }

    private function requeueComailSites(): void
    {
        $configurer = app(ComailSiteConfigurer::class);
        Site::query()->whereHas('comailDomains')->orderBy('id')->each(function (Site $site) use ($configurer): void {
            if ($site->hasAgentSecret()) {
                $configurer->queue($site);
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $requireToken, ?MailServer $server = null): array
    {
        $provider = $server?->exists
            ? $server->provider->value
            : (string) $request->input('provider', MailProvider::Hostinger->value);
        $isComail = $provider === MailProvider::Mailcow->value;
        $tokenRules = [$requireToken ? 'required' : 'nullable', 'string', 'max:2000'];

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            // A saved server keeps its provider: its sites depend on it.
            'provider' => [$server?->exists ? 'sometimes' : 'required', 'string', Rule::in(array_map(
                static fn (MailProvider $case): string => $case->value,
                MailProvider::cases(),
            ))],
            'api_token' => $tokenRules,
            'base_url' => [$isComail ? 'required' : 'nullable', 'string', 'max:255', 'url:https'],
            'mail_hostname' => ['nullable', 'string', 'max:255', 'regex:/\A(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}\z/i'],
            'is_enabled' => ['sometimes', 'boolean'],
        ]);
        $validated['provider'] = $provider;

        return $validated;
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function fillServer(MailServer $server, array $validated): void
    {
        $server->name = $validated['name'];
        $provider = MailProvider::from((string) $validated['provider']);
        $server->provider = $provider;
        $server->is_enabled = $validated['is_enabled'] ?? $server->is_enabled ?? true;
        if (filled($validated['api_token'] ?? null)) {
            $server->api_token = $validated['api_token'];
        }

        if ($provider === MailProvider::Mailcow) {
            $server->base_url = rtrim(trim((string) $validated['base_url']), '/');
            $server->mail_hostname = filled($validated['mail_hostname'] ?? null)
                ? strtolower(trim((string) $validated['mail_hostname']))
                : null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function auditSnapshot(MailServer $server): array
    {
        return [
            'name' => $server->name,
            'provider' => $server->provider instanceof MailProvider ? $server->provider->value : $server->provider,
            'base_url' => $server->base_url,
            'mail_hostname' => $server->mail_hostname,
            'is_enabled' => $server->is_enabled,
            'has_token' => $server->hasToken(),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    private function audit(MailServer $server, Request $request, string $action, ?array $before, ?array $after): void
    {
        $server->auditLogs()->create([
            'actor_user_id' => $request->user()?->id,
            'action' => $action,
            'before' => $before,
            'after' => $after,
            'ip' => $request->ip(),
        ]);
    }
}
