<?php

namespace App\Http\Controllers\Ops;

use App\Http\Controllers\Controller;
use App\Models\CloudflareSetting;
use App\Models\Site;
use App\Services\Cloudflare\CloudflareHostname;
use App\Services\Cloudflare\CloudflareZoneService;
use App\Services\Sites\SiteLanding;
use App\Services\Sites\SiteProvisionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SiteCloudflareController extends Controller
{
    public function store(Request $request, Site $site, CloudflareZoneService $zones, SiteLanding $landing): RedirectResponse|JsonResponse
    {
        $this->authorize('update', $site);

        $validated = $request->validate([
            'cloudflare_setting_id' => ['required', 'integer', Rule::exists('cloudflare_settings', 'id')->where('is_enabled', true)],
        ]);

        $settings = CloudflareSetting::query()->find($validated['cloudflare_setting_id']);
        if (! $settings instanceof CloudflareSetting || ! $settings->hasCredentials()) {
            return $this->failed($request, $site, __('cloudflare.errors.not_configured'));
        }

        $before = [
            'cloudflare_setting_id' => $site->cloudflare_setting_id,
            'cloudflare_zone_id' => $site->cloudflare_zone_id,
        ];

        $site->cloudflare_setting_id = $settings->id;
        $site->save();

        try {
            $result = $zones->ensureZoneAndDns($site, $settings);
        } catch (SiteProvisionException $exception) {
            return $this->failed($request, $site, $exception->getMessage(), $exception->getCode());
        }

        $site->refresh();
        $landing->afterCloudflare($site, $settings, $result['zone']);
        $site->refresh();

        $site->auditLogs()->create([
            'actor_user_id' => $request->user()?->id,
            'action' => 'site.cloudflare_ready',
            'before' => $before,
            'after' => [
                'slug' => $site->slug,
                'primary_domain' => $site->primary_domain,
                'cloudflare_setting_id' => $site->cloudflare_setting_id,
                'cloudflare_zone_id' => $site->cloudflare_zone_id,
                'cloudflare_nameservers' => $site->cloudflare_nameservers,
                'cloudflare_zone_status' => $site->cloudflare_zone_status,
            ],
            'ip' => $request->ip(),
        ]);

        $zone = $result['zone'];
        $ready = CloudflareHostname::zoneIsReady((string) ($zone['status'] ?? $site->cloudflare_zone_status));
        $message = $ready
            ? __('sites.flash.cloudflare_zone_ready')
            : __('sites.flash.cloudflare_zone_pending');

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'message' => $message,
                'type' => $ready ? 'status' : 'warning',
                'created' => $result['created'],
                'waiting' => $site->isWaitingOnDns(),
                'zone_id' => $site->cloudflare_zone_id,
                'zone_name' => (string) ($zone['name'] ?? ''),
                'zone_status' => (string) ($zone['status'] ?? $site->cloudflare_zone_status ?? ''),
                'nameservers' => is_array($site->cloudflare_nameservers) ? array_values($site->cloudflare_nameservers) : [],
            ]);
        }

        return redirect()
            ->route('ops.sites.show', $site)
            ->with('status', $message);
    }

    public function confirmDns(Request $request, Site $site, SiteLanding $landing): RedirectResponse|JsonResponse
    {
        $this->authorize('update', $site);

        try {
            $result = $landing->confirmDns($site, $request->user()?->id, $request->ip());
        } catch (SiteProvisionException $exception) {
            return $this->failed($request, $site, $exception->getMessage(), $exception->getCode());
        }

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'ready' => $result['ready'],
                'message' => $result['message'],
                'type' => $result['ready'] ? 'status' : 'warning',
                'zone_status' => $result['zone_status'],
                'nameservers' => $result['nameservers'],
            ]);
        }

        return redirect()
            ->route('ops.sites.show', $site)
            ->with($result['ready'] ? 'status' : 'error', $result['message']);
    }

    private function failed(Request $request, Site $site, string $message, int $status = 422): RedirectResponse|JsonResponse
    {
        if ($request->expectsJson()) {
            $http = $status >= 400 && $status < 600 ? $status : 422;

            return response()->json([
                'ok' => false,
                'message' => $message,
                'type' => 'error',
            ], $http);
        }

        return redirect()
            ->route('ops.sites.show', $site)
            ->with('error', $message);
    }
}
