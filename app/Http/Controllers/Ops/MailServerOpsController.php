<?php

namespace App\Http\Controllers\Ops;

use App\Enums\MailProvider;
use App\Http\Controllers\Controller;
use App\Models\MailServer;
use App\Services\Hostinger\HostingerMailClient;
use App\Services\Hostinger\HostingerMailException;
use App\Services\Mail\SiteMailConfigurer;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MailServerOpsController extends Controller
{
    public function index(): View
    {
        return view('ops.mail-servers.index', [
            'servers' => MailServer::query()->orderBy('name')->get(),
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
            $site->save();
        }

        $this->audit($mailServer, $request, 'mail_server.deleted', $before, null);
        $mailServer->delete();
        $configurer->disableSites($sites);

        return redirect()
            ->route('ops.mail-servers.index')
            ->with('status', __('mail.flash.deleted'));
    }

    public function test(Request $request, MailServer $mailServer): RedirectResponse
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

        $selected = $mailServer->hostinger_order_id;
        $matched = null;
        foreach ($orders as $order) {
            if ($selected !== null && $selected !== '' && hash_equals($order['id'], $selected)) {
                $matched = $order;
                break;
            }
        }

        if ($matched === null && count($orders) === 1) {
            $matched = $orders[0];
            $mailServer->hostinger_order_id = $matched['id'];
        }

        if ($matched !== null && filled($matched['domain'])) {
            $mailServer->mail_domain = $matched['domain'];
        }

        $mailServer->last_probe_at = now();
        $mailServer->last_probe_payload = [
            'ok' => true,
            'order_count' => count($orders),
            'orders' => $orders,
        ];
        $mailServer->save();

        $this->audit($mailServer, $request, 'mail_server.tested', null, [
            'order_count' => count($orders),
            'mail_domain' => $mailServer->mail_domain,
        ]);

        return back()->with('status', __('mail.flash.tested', ['count' => count($orders)]));
    }

    public function selectOrder(Request $request, MailServer $mailServer, SiteMailConfigurer $configurer): RedirectResponse
    {
        $this->authorize('ops.write');

        $orderId = trim((string) $request->input('hostinger_order_id'));
        $matched = null;
        foreach ($mailServer->probedOrders() as $order) {
            if (hash_equals($order['id'], $orderId)) {
                $matched = $order;
                break;
            }
        }

        if ($matched === null) {
            if (! $mailServer->hasToken()) {
                throw ValidationException::withMessages([
                    'hostinger_order_id' => __('mail.errors.order_unknown'),
                ]);
            }

            try {
                $matched = HostingerMailClient::fromServer($mailServer)->findOrder($orderId);
            } catch (HostingerMailException) {
                $matched = null;
            }
        }

        if ($matched === null) {
            throw ValidationException::withMessages([
                'hostinger_order_id' => __('mail.errors.order_unknown'),
            ]);
        }

        $mailServer->hostinger_order_id = $matched['id'];
        $mailServer->mail_domain = $matched['domain'];
        $mailServer->save();
        $configurer->syncAssignedSites($mailServer);

        return back()->with('status', __('mail.flash.order_selected'));
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
            'hostinger_order_id' => ['nullable', 'string', 'max:64'],
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
        if (filled($validated['hostinger_order_id'] ?? null)) {
            $server->hostinger_order_id = $validated['hostinger_order_id'];
        }
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
            'hostinger_order_id' => $server->hostinger_order_id,
            'mail_domain' => $server->mail_domain,
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
