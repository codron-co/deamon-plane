<?php

namespace App\Http\Controllers\Ops;

use App\Http\Controllers\Controller;
use App\Http\Requests\Ops\StoreSiteDomainRequest;
use App\Models\Site;
use App\Services\Sites\SiteDomainSync;
use App\Services\Sites\SiteLanding;
use App\Services\Sites\SiteProvisionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SiteDomainController extends Controller
{
    public function store(
        StoreSiteDomainRequest $request,
        Site $site,
        SiteDomainSync $sync,
        SiteLanding $landing,
    ): RedirectResponse|JsonResponse {
        $host = (string) $request->validated('domain');
        $outcome = SiteLanding::BIND_NO_APP;

        try {
            $sync->addAlias($site, $host);
            $site->refresh();
            $landing->applyAliasDns($site);
            $outcome = $landing->bindAndRedeploy($site, $request->user(), $request->ip());
        } catch (SiteProvisionException $exception) {
            return $this->failed($request, $site, $exception->getMessage(), $exception->getCode());
        }

        $site->refresh();
        $site->auditLogs()->create([
            'actor_user_id' => $request->user()?->id,
            'action' => 'site.domain_added',
            'after' => [
                'domain' => $host,
                'hosts' => $site->operatorHosts(),
                'redeploy' => $outcome,
            ],
            'ip' => $request->ip(),
        ]);

        $message = __('sites.flash.domain_added').SiteLanding::bindFlashSuffix($outcome);

        // A host on another apex got its own Cloudflare zone; hand the NS to the customer.
        $row = $site->domains()->where('domain', $host)->first();
        if ($row !== null && $row->zonePending()) {
            $ns = is_array($row->cloudflare_nameservers) ? $row->cloudflare_nameservers : [];
            $message .= ' '.__('sites.flash.domain_zone_pending', [
                'host' => $host,
                'ns' => implode(', ', array_filter($ns, is_string(...))),
            ]);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'message' => $message,
                'type' => 'status',
                'hosts' => $site->operatorHosts(),
                'redeploy' => $outcome,
            ]);
        }

        return redirect()
            ->route('ops.sites.show', $site)
            ->with('status', $message);
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
