<?php

namespace App\Http\Controllers\Ops;

use App\Http\Controllers\Controller;
use App\Models\CloudflareSetting;
use App\Services\Cloudflare\CloudflarePermissionProbe;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CloudflareSettingsController extends Controller
{
    public function show(): View
    {
        $settings = CloudflareSetting::current();

        return view('ops.cloudflare.show', [
            'settings' => $settings,
            'canWrite' => request()->user()?->can('ops.write') ?? false,
            'hasToken' => $settings->hasToken(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $this->authorize('ops.write');

        $settings = CloudflareSetting::current();

        $validated = $request->validate([
            'account_id' => ['required', 'string', 'size:32', 'regex:/^[a-fA-F0-9]{32}$/'],
            'api_token' => [$settings->hasToken() ? 'nullable' : 'required', 'string', 'max:2000'],
            'origin_ipv4' => ['required', 'ipv4'],
            'mail_template_enabled' => ['sometimes', 'boolean'],
        ]);

        if (! $settings->exists) {
            $settings = new CloudflareSetting;
        }

        $settings->account_id = strtolower(trim((string) $validated['account_id']));
        $settings->origin_ipv4 = trim((string) $validated['origin_ipv4']);
        $settings->mail_template_enabled = $request->boolean('mail_template_enabled');
        $settings->proxied = false;

        if (filled($validated['api_token'] ?? null)) {
            $settings->api_token = $validated['api_token'];
        }

        $settings->save();

        return redirect()
            ->route('ops.cloudflare.show')
            ->with('status', __('cloudflare.flash.saved'));
    }

    public function test(CloudflarePermissionProbe $probe): RedirectResponse
    {
        $this->authorize('ops.write');

        $settings = CloudflareSetting::current();
        if (! $settings->hasCredentials()) {
            return back()->with('error', __('cloudflare.errors.not_configured'));
        }

        $result = $probe->probe($settings);
        $settings->last_probe_at = now();
        $settings->last_probe_payload = $result->storedPayload();
        $settings->save();

        if ($result->accountIdInvalid) {
            return back()->with('error', __('cloudflare.flash.account_invalid'));
        }

        if (! $result->tokenValid) {
            return back()->with('error', __('cloudflare.flash.token_invalid'));
        }

        if ($result->missingLabels !== []) {
            return back()->with('error', __('cloudflare.flash.partial', [
                'missing' => implode(', ', $result->missingLabels),
            ]));
        }

        if ($result->dnsUnverified) {
            return back()->with('status', __('cloudflare.flash.ok_dns_unverified'));
        }

        return back()->with('status', __('cloudflare.flash.ok'));
    }
}
