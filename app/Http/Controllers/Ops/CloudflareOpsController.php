<?php

namespace App\Http\Controllers\Ops;

use App\Http\Controllers\Controller;
use App\Models\CloudflareDnsDefault;
use App\Models\CloudflareSetting;
use App\Services\Cloudflare\CloudflareAccounts;
use App\Services\Cloudflare\CloudflareApiException;
use App\Services\Cloudflare\CloudflareClient;
use App\Services\Cloudflare\CloudflareDnsRecord;
use App\Services\Cloudflare\CloudflareHostname;
use App\Services\Cloudflare\CloudflarePermissionProbe;
use App\Services\Cloudflare\CloudflareZoneService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CloudflareOpsController extends Controller
{
    public function index(): View
    {
        $accounts = CloudflareSetting::query()
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        return view('ops.cloudflare.index', [
            'accounts' => $accounts,
            'canWrite' => request()->user()?->can('ops.write') ?? false,
        ]);
    }

    public function create(): View
    {
        $this->authorize('ops.write');

        return view('ops.cloudflare.create', [
            'account' => new CloudflareSetting([
                'is_enabled' => true,
                'is_default' => CloudflareSetting::query()->doesntExist(),
            ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('ops.write');

        $validated = $this->validatedAccount($request, requireToken: true);

        $account = new CloudflareSetting;
        $this->fillAccount($account, $validated, $request);
        $account->save();

        if ($request->boolean('is_default') || CloudflareSetting::query()->count() === 1) {
            CloudflareAccounts::markAsDefault($account);
        }

        return redirect()
            ->route('ops.cloudflare.show', $account)
            ->with('status', __('cloudflare.flash.saved'));
    }

    public function show(CloudflareSetting $account): View
    {
        $zones = [];
        $zonesError = null;

        if ($account->hasCredentials()) {
            try {
                $zones = CloudflareClient::fromSettings($account)->listAllZones((string) $account->account_id);
            } catch (CloudflareApiException $exception) {
                $zonesError = $exception->getMessage();
            }
        }

        return view('ops.cloudflare.show', [
            'account' => $account,
            'zones' => $zones,
            'zonesError' => $zonesError,
            'canWrite' => request()->user()?->can('ops.write') ?? false,
            'hasToken' => $account->hasToken(),
        ]);
    }

    public function update(Request $request, CloudflareSetting $account): RedirectResponse
    {
        $this->authorize('ops.write');

        $validated = $this->validatedAccount($request, requireToken: false);
        $this->fillAccount($account, $validated, $request);
        $account->save();

        if ($request->boolean('is_default')) {
            CloudflareAccounts::markAsDefault($account);
        }

        return back()->with('status', __('cloudflare.flash.saved'));
    }

    public function destroy(CloudflareSetting $account): RedirectResponse
    {
        $this->authorize('ops.write');

        $wasDefault = (bool) $account->is_default;
        $account->delete();

        if ($wasDefault) {
            $next = CloudflareSetting::query()->where('is_enabled', true)->orderBy('id')->first();
            if ($next !== null) {
                CloudflareAccounts::markAsDefault($next);
            }
        }

        return redirect()
            ->route('ops.cloudflare.index')
            ->with('status', __('cloudflare.flash.disconnected'));
    }

    public function test(CloudflareSetting $account, CloudflarePermissionProbe $probe): RedirectResponse
    {
        $this->authorize('ops.write');

        if (! $account->hasCredentials()) {
            return back()->with('error', __('cloudflare.errors.not_configured'));
        }

        $result = $probe->probe($account);
        $account->last_probe_at = now();
        $account->last_probe_payload = $result->storedPayload();
        $account->save();

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

    public function makeDefault(CloudflareSetting $account): RedirectResponse
    {
        $this->authorize('ops.write');

        CloudflareAccounts::markAsDefault($account);

        return back()->with('status', __('cloudflare.flash.made_default'));
    }

    public function showDefaults(): View
    {
        CloudflareDnsDefault::seedBuiltin();

        return view('ops.cloudflare.defaults', [
            'records' => CloudflareDnsDefault::query()->orderBy('sort_order')->orderBy('id')->get(),
            'canWrite' => request()->user()?->can('ops.write') ?? false,
        ]);
    }

    public function storeDefault(Request $request): RedirectResponse
    {
        $this->authorize('ops.write');

        CloudflareDnsDefault::query()->create([
            ...$this->storedDefault(CloudflareDnsRecord::fromRequest($request)),
            'sort_order' => CloudflareDnsDefault::nextSortOrder(),
        ]);

        return back()->with('status', __('cloudflare.flash.default_created'));
    }

    public function updateDefault(Request $request, CloudflareDnsDefault $record): RedirectResponse
    {
        $this->authorize('ops.write');

        $record->fill($this->storedDefault(CloudflareDnsRecord::fromRequest($request)));
        $record->save();

        return back()->with('status', __('cloudflare.flash.default_updated'));
    }

    public function destroyDefault(CloudflareDnsDefault $record): RedirectResponse
    {
        $this->authorize('ops.write');

        $record->delete();

        return back()->with('status', __('cloudflare.flash.default_deleted'));
    }

    public function resetDefaults(): RedirectResponse
    {
        $this->authorize('ops.write');

        CloudflareDnsDefault::seedBuiltin(true);

        return back()->with('status', __('cloudflare.flash.defaults_reset'));
    }

    public function storeZone(Request $request, CloudflareSetting $account, CloudflareZoneService $zones): RedirectResponse
    {
        $this->authorize('ops.write');
        $this->assertReady($account);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'apply_defaults' => ['sometimes', 'boolean'],
        ]);

        try {
            $zone = $zones->createZone(
                $account,
                (string) $validated['name'],
                $request->boolean('apply_defaults', true),
            );
        } catch (CloudflareApiException $exception) {
            return $this->failed($exception);
        }

        return redirect()
            ->route('ops.cloudflare.zones.show', [$account, $zone['id']])
            ->with('status', __('cloudflare.flash.zone_created'));
    }

    public function showZone(CloudflareSetting $account, string $zone, CloudflareZoneService $zones): View
    {
        $this->assertReady($account);
        $zoneId = $this->cloudflareId($zone);
        $client = CloudflareClient::fromSettings($account);
        $zoneData = ['id' => $zoneId];
        $records = [];
        $recordsError = null;
        $nameservers = [];

        try {
            $zoneData = $zones->requireZoneOnAccount($client, $account, $zoneId);
            $nameservers = $zones->nameservers($zoneData);
            $zoneName = (string) ($zoneData['name'] ?? '');
            $records = array_map(
                static function (array $row) use ($zoneName): array {
                    $row['name'] = CloudflareDnsRecord::relative((string) ($row['name'] ?? ''), $zoneName);

                    return $row;
                },
                $client->listAllDnsRecords($zoneId),
            );
        } catch (CloudflareApiException $exception) {
            if ($exception->status === 404) {
                abort(404);
            }

            $recordsError = $exception->getMessage();
        }

        return view('ops.cloudflare.zone', [
            'account' => $account,
            'zone' => $zoneData,
            'records' => $records,
            'recordsError' => $recordsError,
            'nameservers' => $nameservers,
            'canWrite' => request()->user()?->can('ops.write') ?? false,
        ]);
    }

    public function applyZoneDefaults(CloudflareSetting $account, string $zone, CloudflareZoneService $zones): RedirectResponse
    {
        $this->authorize('ops.write');
        $this->assertReady($account);

        try {
            $zones->applyDefaults($account, $this->cloudflareId($zone));
        } catch (CloudflareApiException $exception) {
            return $this->failed($exception);
        }

        return back()->with('status', __('cloudflare.flash.defaults_applied'));
    }

    public function destroyZone(CloudflareSetting $account, string $zone, CloudflareZoneService $zones): RedirectResponse
    {
        $this->authorize('ops.write');
        $this->assertReady($account);

        try {
            $zones->deleteZone($account, $this->cloudflareId($zone));
        } catch (CloudflareApiException $exception) {
            return $this->failed($exception);
        }

        return redirect()
            ->route('ops.cloudflare.show', $account)
            ->with('status', __('cloudflare.flash.zone_deleted'));
    }

    public function storeDns(Request $request, CloudflareSetting $account, string $zone, CloudflareZoneService $zones): RedirectResponse
    {
        $this->authorize('ops.write');
        $this->assertReady($account);
        $zoneId = $this->cloudflareId($zone);

        try {
            $zoneData = $zones->requireZoneOnAccount(CloudflareClient::fromSettings($account), $account, $zoneId);
            $zones->createDns($account, $zoneId, CloudflareDnsRecord::fromRequest($request, (string) ($zoneData['name'] ?? '')));
        } catch (CloudflareApiException $exception) {
            return $this->failed($exception);
        }

        return back()->with('status', __('cloudflare.flash.dns_created'));
    }

    public function updateDns(Request $request, CloudflareSetting $account, string $zone, string $record, CloudflareZoneService $zones): RedirectResponse
    {
        $this->authorize('ops.write');
        $this->assertReady($account);
        $zoneId = $this->cloudflareId($zone);
        $recordId = $this->cloudflareId($record);

        try {
            $zoneData = $zones->requireZoneOnAccount(CloudflareClient::fromSettings($account), $account, $zoneId);
            $zones->updateDns($account, $zoneId, $recordId, CloudflareDnsRecord::fromRequest($request, (string) ($zoneData['name'] ?? '')));
        } catch (CloudflareApiException $exception) {
            return $this->failed($exception);
        }

        return back()->with('status', __('cloudflare.flash.dns_updated'));
    }

    public function destroyDns(CloudflareSetting $account, string $zone, string $record, CloudflareZoneService $zones): RedirectResponse
    {
        $this->authorize('ops.write');
        $this->assertReady($account);

        try {
            $zones->deleteDns($account, $this->cloudflareId($zone), $this->cloudflareId($record));
        } catch (CloudflareApiException $exception) {
            return $this->failed($exception);
        }

        return back()->with('status', __('cloudflare.flash.dns_deleted'));
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedAccount(Request $request, bool $requireToken): array
    {
        $wildcard = trim((string) $request->input('wildcard_domain', ''));
        $request->merge(['wildcard_domain' => $wildcard === '' ? null : $wildcard]);

        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'account_id' => ['required', 'string', 'size:32', 'regex:/^[a-fA-F0-9]{32}$/'],
            'api_token' => [$requireToken ? 'required' : 'nullable', 'string', 'max:2000'],
            'wildcard_domain' => ['nullable', 'string', 'max:255', 'regex:/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/'],
            'origin_ipv4' => ['nullable', 'ipv4'],
            'is_enabled' => ['sometimes', 'boolean'],
            'is_default' => ['sometimes', 'boolean'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function fillAccount(CloudflareSetting $account, array $validated, Request $request): void
    {
        $account->name = trim((string) $validated['name']);
        $account->account_id = strtolower(trim((string) $validated['account_id']));
        $account->is_enabled = $request->boolean('is_enabled', true);

        $wildcard = CloudflareHostname::normalize((string) ($validated['wildcard_domain'] ?? ''));
        $account->wildcard_domain = $wildcard !== '' ? $wildcard : null;

        if (filled($validated['origin_ipv4'] ?? null)) {
            $account->origin_ipv4 = trim((string) $validated['origin_ipv4']);
        }

        if (filled($validated['api_token'] ?? null)) {
            $account->api_token = $validated['api_token'];
        }
    }

    /**
     * @param  array{type: string, name: string, content: string, ttl: int, proxied: bool, priority?: int}  $record
     * @return array{type: string, name: string, content: string, ttl: int, priority: int|null}
     */
    private function storedDefault(array $record): array
    {
        return [
            'type' => $record['type'],
            'name' => $record['name'],
            'content' => $record['content'],
            'ttl' => $record['ttl'],
            'priority' => $record['priority'] ?? null,
        ];
    }

    private function assertReady(CloudflareSetting $account): void
    {
        if (! $account->hasCredentials()) {
            abort(404);
        }
    }

    private function cloudflareId(string $value): string
    {
        if (preg_match('/^[a-fA-F0-9]{32}$/', $value) !== 1) {
            abort(404);
        }

        return strtolower($value);
    }

    private function failed(CloudflareApiException $exception): RedirectResponse
    {
        if ($exception->status === 404) {
            abort(404);
        }

        return back()->with('error', $exception->getMessage());
    }
}
