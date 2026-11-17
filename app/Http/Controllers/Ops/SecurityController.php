<?php

namespace App\Http\Controllers\Ops;

use App\Http\Controllers\Controller;
use App\Models\FleetAllowlistEntry;
use App\Models\FleetIpBlock;
use App\Models\Site;
use App\Services\Security\FleetSecurityOverview;
use App\Services\Security\SecurityCorrelator;
use App\Services\Security\SecurityEventQuery;
use App\Support\Lists\ListFragment;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Fleet security (Güvenlik): overview, cross-site correlation, one site's view,
 * the event stream and the blocks / allowlist page. Reads are open to every ops
 * role; the writes live in SecurityResponseController.
 */
class SecurityController extends Controller
{
    public function index(FleetSecurityOverview $overview): View
    {
        $this->authorize('viewAny', Site::class);

        return view('ops.security.index', ['overview' => $overview->build()]);
    }

    public function correlation(Request $request, SecurityCorrelator $correlator): View
    {
        $this->authorize('viewAny', Site::class);

        $period = in_array($request->query('period'), ['24h', '7d', '30d'], true) ? (string) $request->query('period') : '24h';
        $since = now()->subDays(SecurityEventQuery::PERIODS[$period]);

        return view('ops.security.correlation', [
            'cards' => $correlator->crossSite($since, null, 50),
            'period' => $period,
        ]);
    }

    public function events(Request $request): Response
    {
        $this->authorize('viewAny', Site::class);

        $query = SecurityEventQuery::fromRequest($request);
        $events = $query->paginate();

        return ListFragment::respond($request, 'ops.security.events', 'ops.security._events_region', [
            'events' => $events,
            'query' => $query,
            'filtersActive' => $query->active(),
            'sites' => Site::query()->orderBy('name')->get(['id', 'name']),
            'rules' => $query->rules(),
            'periods' => array_keys(SecurityEventQuery::PERIODS),
            'severities' => array_keys(SecurityEventQuery::SEVERITIES),
            'blockedIps' => FleetIpBlock::query()->active()->whereIn('ip', $events->pluck('ip')->unique())->pluck('ip')->flip(),
        ]);
    }

    public function site(Site $site, FleetSecurityOverview $overview, Request $request): View
    {
        $this->authorize('view', $site);

        $query = SecurityEventQuery::fromArray(['site' => $site->id, 'period' => '7d']);

        return view('ops.security.site', [
            'site' => $site,
            'detail' => $overview->site($site),
            'recent' => $query->builder()->limit(25)->get(),
        ]);
    }

    public function blocks(): View
    {
        $this->authorize('viewAny', Site::class);

        return view('ops.security.blocks', [
            'blocks' => FleetIpBlock::query()->active()->with('creator:id,name')->latest('id')->limit(200)->get(),
            'recentReleased' => FleetIpBlock::query()->whereNotNull('released_at')->latest('released_at')->limit(10)->get(),
            'allowlist' => FleetAllowlistEntry::query()->active()->with('creator:id,name')->latest('id')->limit(200)->get(),
            'canWrite' => (bool) request()->user()?->canWriteOps(),
            'canRemoveAllow' => (bool) request()->user()?->canRunDangerousOps(),
        ]);
    }
}
