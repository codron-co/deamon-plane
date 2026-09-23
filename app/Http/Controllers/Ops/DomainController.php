<?php

namespace App\Http\Controllers\Ops;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Ops\Concerns\QueuesOpsJob;
use App\Http\Requests\Ops\BulkDomainIdsRequest;
use App\Http\Requests\Ops\StoreFleetDomainRequest;
use App\Http\Requests\Ops\UpdateFleetDomainRequest;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Services\Cloudflare\CloudflareHostname;
use App\Services\Domains\DomainBindSweep;
use App\Services\Domains\DomainClearSweep;
use App\Services\Sites\SiteDomainSync;
use App\Services\Sites\SiteLanding;
use App\Services\Sites\SiteProvisionException;
use App\Support\Lists\ListFragment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;

class DomainController extends Controller
{
    use QueuesOpsJob;

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Site::class);

        $search = trim((string) $request->query('q', ''));
        $unbound = $request->boolean('unbound');

        $query = SiteDomain::query()
            ->with('site')
            ->matchingListFilters($search, $unbound)
            ->orderBy('domain');

        $domains = $query->paginate(50)->withQueryString();
        $activeFilters = $this->activeListFilters($search, $unbound);
        $unboundInFilter = $unbound
            ? $domains->total()
            : SiteDomain::query()->matchingListFilters($search, true)->count();

        return ListFragment::respond($request, 'ops.domains.index', 'ops.domains._region', [
            'domains' => $domains,
            'search' => $search,
            'unbound' => $unbound,
            'unboundInFilter' => $unboundInFilter,
            'sites' => Site::query()->orderBy('name')->get(['id', 'name', 'primary_domain']),
            'canWrite' => $request->user()?->canWriteOps() ?? false,
            'filtersActive' => $activeFilters !== [],
            'activeFilters' => $activeFilters,
            'totalDomains' => $domains->total() > 0 ? $domains->total() : SiteDomain::query()->count(),
            // The tiles sit outside the swapped region; a keystroke must not recount them.
            'summary' => ListFragment::wanted($request) ? null : $this->registrySummary(),
        ]);
    }

    public function store(StoreFleetDomainRequest $request, SiteDomainSync $sync, SiteLanding $landing): RedirectResponse
    {
        $host = CloudflareHostname::normalize((string) $request->validated('domain'));
        $site = Site::query()->findOrFail($request->validated('site_id'));
        $this->authorize('update', $site);

        if (blank($site->primary_domain)) {
            $sync->sync($site, $host, []);
        } else {
            $sync->addAlias($site, $host);
        }

        try {
            $fresh = $site->fresh() ?? $site;
            $landing->applyAliasDns($fresh);
            $outcome = $landing->bindAndRedeploy($fresh, $request->user(), $request->ip());
        } catch (SiteProvisionException $exception) {
            return redirect()->route('ops.domains')->with('error', $exception->getMessage());
        }

        return redirect()->route('ops.domains')->with('status', __('domains.flash.created_bound').SiteLanding::bindFlashSuffix($outcome));
    }

    public function update(UpdateFleetDomainRequest $request, SiteDomain $domain, SiteDomainSync $sync, SiteLanding $landing): RedirectResponse
    {
        if ($domain->is_primary) {
            return back()->with('error', __('domains.flash.primary_locked'));
        }

        $siteId = $request->validated('site_id');
        if (blank($siteId)) {
            return back()->with('error', __('domains.flash.needs_site'));
        }

        $site = Site::query()->findOrFail($siteId);
        $this->authorize('update', $site);

        if ((string) $domain->site_id === (string) $site->id) {
            return back()->with('status', __('domains.flash.assigned'));
        }

        if (blank($site->primary_domain)) {
            $host = $domain->domain;
            $domain->delete();
            $sync->sync($site, $host, []);
        } else {
            $host = $domain->domain;
            $domain->delete();
            $sync->addAlias($site, $host);
        }

        try {
            $fresh = $site->fresh() ?? $site;
            $landing->applyAliasDns($fresh);
            $outcome = $landing->bindAndRedeploy($fresh, $request->user(), $request->ip());
        } catch (SiteProvisionException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', __('domains.flash.assigned').SiteLanding::bindFlashSuffix($outcome));
    }

    public function bind(Request $request, SiteDomain $domain, SiteLanding $landing): RedirectResponse
    {
        $site = $domain->site;
        if (! $site instanceof Site) {
            return back()->with('error', __('domains.flash.needs_site'));
        }

        $this->authorize('update', $site);

        try {
            $outcome = $landing->bindAndRedeploy($site, $request->user(), $request->ip());
        } catch (SiteProvisionException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', __('domains.flash.bound').SiteLanding::bindFlashSuffix($outcome));
    }

    public function bindBulk(BulkDomainIdsRequest $request, DomainBindSweep $sweep): RedirectResponse|JsonResponse
    {
        $domains = $this->domainsFromBulk($request);

        foreach ($domains as $domain) {
            if ($domain->site instanceof Site) {
                $this->authorize('update', $domain->site);
            }
        }

        if ($domains->isEmpty()) {
            return back()->with('error', __('domains.bulk.empty'));
        }

        if ($request->expectsJson()) {
            return $this->queueOpsJob($request, 'domains.bulk_bind', __('ops.jobs.bulk_bind'), [
                'domain_ids' => $domains->pluck('id')->all(),
                'ip' => $request->ip(),
            ]);
        }

        return back()->with('status', $sweep->summarize($sweep->run($domains)));
    }

    public function clearBulk(BulkDomainIdsRequest $request, DomainClearSweep $sweep): RedirectResponse|JsonResponse
    {
        $domains = $this->domainsFromBulk($request);

        foreach ($domains as $domain) {
            if ($domain->site instanceof Site) {
                $this->authorize('update', $domain->site);
            }
        }

        if ($domains->isEmpty()) {
            return $this->clearBulkEmpty($request);
        }

        $result = $sweep->run($domains);
        if ((int) ($result['ok'] ?? 0) === 0) {
            return $this->clearBulkEmpty($request);
        }

        $summary = $sweep->summarize($result);

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'message' => $summary,
                'type' => 'status',
                'refresh_list' => true,
            ]);
        }

        return back()->with('status', $summary);
    }

    private function clearBulkEmpty(BulkDomainIdsRequest $request): RedirectResponse|JsonResponse
    {
        $message = __('domains.bulk.empty_clear');

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => false,
                'message' => $message,
                'type' => 'error',
            ], 422);
        }

        return back()->with('error', $message);
    }

    /**
     * @return Collection<int, SiteDomain>
     */
    private function domainsFromBulk(BulkDomainIdsRequest $request): Collection
    {
        if ($request->boolean('all')) {
            return SiteDomain::query()
                ->with('site')
                ->matchingListFilters(
                    trim((string) $request->input('filter_q', '')),
                    $request->boolean('filter_unbound'),
                )
                ->orderBy('domain')
                ->get();
        }

        $ids = $request->validated('domain_ids') ?? [];

        return SiteDomain::query()->with('site')->whereIn('id', $ids)->orderBy('domain')->get();
    }

    /**
     * Registry-wide counts behind the Domains tiles, in one aggregate query.
     * `unbound` uses the list filter's own rule (not verified, not temporary).
     *
     * @return array{total: int, bound: int, unbound: int, temporary: int}
     */
    private function registrySummary(): array
    {
        $row = SiteDomain::query()
            ->toBase()
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN verified_at IS NOT NULL THEN 1 ELSE 0 END) as bound')
            ->selectRaw('SUM(CASE WHEN verified_at IS NULL AND is_temporary = ? THEN 1 ELSE 0 END) as unbound', [false])
            ->selectRaw('SUM(CASE WHEN is_temporary = ? THEN 1 ELSE 0 END) as temporary', [true])
            ->first();

        return [
            'total' => (int) ($row->total ?? 0),
            'bound' => (int) ($row->bound ?? 0),
            'unbound' => (int) ($row->unbound ?? 0),
            'temporary' => (int) ($row->temporary ?? 0),
        ];
    }

    /**
     * @return list<array{key: string, label: string, value: string, url: string}>
     */
    private function activeListFilters(string $search, bool $unbound): array
    {
        $applied = [];
        if ($search !== '') {
            $applied['q'] = $search;
        }
        if ($unbound) {
            $applied['unbound'] = '1';
        }

        $labels = [
            'q' => __('domains.filter_search'),
            'unbound' => __('domains.filter_unbound'),
        ];
        $displayed = [
            'q' => $search,
            'unbound' => __('domains.coolify.unbound'),
        ];

        $chips = [];
        foreach ($applied as $key => $value) {
            $chips[] = [
                'key' => $key,
                'label' => $labels[$key],
                'value' => $displayed[$key],
                'url' => route('ops.domains', array_diff_key($applied, [$key => null])),
            ];
        }

        return $chips;
    }
}
