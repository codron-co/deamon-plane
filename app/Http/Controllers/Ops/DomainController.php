<?php

namespace App\Http\Controllers\Ops;

use App\Http\Controllers\Controller;
use App\Http\Requests\Ops\StoreFleetDomainRequest;
use App\Http\Requests\Ops\UpdateFleetDomainRequest;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Services\Cloudflare\CloudflareHostname;
use App\Services\Sites\SiteDomainSync;
use App\Services\Sites\SiteLanding;
use App\Services\Sites\SiteProvisionException;
use App\Support\Lists\ListFragment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class DomainController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Site::class);

        $search = trim((string) $request->query('q', ''));
        $unbound = $request->boolean('unbound');

        $query = SiteDomain::query()->with('site')->orderBy('domain');

        if ($search !== '') {
            $query->where('domain', 'like', '%'.$search.'%');
        }
        if ($unbound) {
            $query->whereNull('verified_at')->where('is_temporary', false);
        }

        return ListFragment::respond($request, 'ops.domains.index', 'ops.domains._region', [
            'domains' => $query->paginate(50)->withQueryString(),
            'search' => $search,
            'unbound' => $unbound,
            'sites' => Site::query()->orderBy('name')->get(['id', 'name', 'primary_domain']),
            'canWrite' => $request->user()?->canWriteOps() ?? false,
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
            if (filled($fresh->coolify_app_uuid) && ! $fresh->isWaitingOnDns()) {
                $landing->syncCoolifyDomains($fresh);
                SiteDomain::query()->where('site_id', $fresh->id)->where('domain', $host)->update(['verified_at' => now()]);
            }
        } catch (SiteProvisionException $exception) {
            return redirect()->route('ops.domains')->with('error', $exception->getMessage());
        }

        return redirect()->route('ops.domains')->with('status', __('domains.flash.created_bound'));
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
            if (filled($fresh->coolify_app_uuid) && ! $fresh->isWaitingOnDns()) {
                $landing->syncCoolifyDomains($fresh);
            }
        } catch (SiteProvisionException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', __('domains.flash.assigned'));
    }

    public function bind(Request $request, SiteDomain $domain, SiteLanding $landing): RedirectResponse
    {
        $site = $domain->site;
        if (! $site instanceof Site) {
            return back()->with('error', __('domains.flash.needs_site'));
        }

        $this->authorize('update', $site);

        try {
            $landing->syncCoolifyDomains($site);
            $domain->verified_at = now();
            $domain->save();
        } catch (SiteProvisionException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', __('domains.flash.bound'));
    }
}
