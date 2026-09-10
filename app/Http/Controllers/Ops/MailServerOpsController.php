<?php

namespace App\Http\Controllers\Ops;

use App\Enums\MailProvider;
use App\Http\Controllers\Controller;
use App\Models\MailServer;
use App\Services\Hostinger\HostingerMailClient;
use App\Services\Hostinger\HostingerMailException;
use App\Services\Mail\SiteMailConfigurer;
use App\Services\Mail\SiteMailOrderBinder;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MailServerOpsController extends Controller
{
    public function index(): View
    {
        return view('ops.mail-servers.index', [
            'servers' => MailServer::query()->withCount('sites')->orderBy('name')->get(),
            'canWrite' => request()->user()?->can('ops.write') ?? false,
        ]);
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
            'hasToken' => $mailServer->hasToken(),
            'sites' => $mailServer->sites()->orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, MailServer $mailServer, SiteMailConfigurer $configurer): RedirectResponse
    {
        $this->authorize('ops.write');

        $before = $this->auditSnapshot($mailServer);
        $validated = $this->validated($request, requireToken: false);
        $this->fillServer($mailServer, $validated);
        $mailServer->save();
        $this->audit($mailServer, $request, 'mail_server.updated', $before, $this->auditSnapshot($mailServer));

        $configurer->syncAssignedSites($mailServer);

        return back()->with('status', __('mail.flash.saved'));
    }

    public function destroy(Request $request, MailServer $mailServer, SiteMailConfigurer $configurer): RedirectResponse
    {
        $this->authorize('ops.write');

        $sites = $mailServer->sites()->get();
        $before = $this->auditSnapshot($mailServer);

        foreach ($sites as $site) {
            $site->mail_server_id = null;
            $site->hostinger_order_id = null;
            $site->mail_domain = null;
            $site->save();
        }

        $this->audit($mailServer, $request, 'mail_server.deleted', $before, null);
        $mailServer->delete();
        $configurer->disableSites($sites);

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

        try {
            $client = HostingerMailClient::fromServer($mailServer);
            $orders = $client->listOrders();
        } catch (HostingerMailException) {
            return back()->with('error', __('mail.errors.test_failed'));
        }

        $mailServer->last_probe_at = now();
        $mailServer->last_probe_payload = [
            'ok' => true,
            'order_count' => count($orders),
            'orders' => $orders,
        ];
        $mailServer->save();

        $binder->bindAssignedSites($mailServer, $orders);
        $configurer->syncAssignedSites($mailServer);

        $this->audit($mailServer, $request, 'mail_server.tested', null, [
            'order_count' => count($orders),
        ]);

        return back()->with('status', __('mail.flash.tested', ['count' => count($orders)]));
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $requireToken): array
    {
        $tokenRules = [$requireToken ? 'required' : 'nullable', 'string', 'max:2000'];

        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'provider' => ['required', 'string', Rule::in([MailProvider::Hostinger->value])],
            'api_token' => $tokenRules,
            'is_enabled' => ['sometimes', 'boolean'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function fillServer(MailServer $server, array $validated): void
    {
        $server->name = $validated['name'];
        $server->provider = MailProvider::Hostinger;
        $server->is_enabled = $validated['is_enabled'] ?? $server->is_enabled ?? true;
        if (filled($validated['api_token'] ?? null)) {
            $server->api_token = $validated['api_token'];
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
