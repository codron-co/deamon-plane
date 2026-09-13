<?php

namespace App\Http\Controllers\Ops;

use App\Http\Controllers\Controller;
use App\Services\Fleet\FleetDashboardKpis;
use App\Support\Lists\FleetAttentionQuery;
use App\Support\Lists\ListFragment;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class FleetController extends Controller
{
    public function index(Request $request, FleetDashboardKpis $kpis): Response
    {
        $attention = FleetAttentionQuery::from($request, $kpis);

        // KPIs are the unfiltered snapshot and live outside the swapped
        // region. A keystroke must not recompute them (P0-2).
        $snapshot = ListFragment::wanted($request)
            ? ['failed_deploy_window_hours' => $attention->failedDeployWindowHours]
            : $kpis->snapshot();

        return ListFragment::respond($request, 'ops.fleet.index', 'ops.dashboard.attention', [
            'channels' => config('ops.channels'),
            'kpis' => $snapshot,
            'search' => $attention->search,
            'kind' => $attention->kind,
            'filtersActive' => $attention->filtersActive(),
            'activeFilters' => $attention->chips(),
            'totalAttention' => $attention->unfilteredTotal,
            'dockerfilePackSites' => $attention->dockerfilePackSites,
            'unhealthySites' => $attention->unhealthySites,
            'unhealthyTotal' => $attention->unhealthyTotal,
            'failedDeploys' => $attention->failedDeploys,
            'failedTotal' => $attention->failedTotal,
            'agentSecretSites' => $attention->agentSecretSites,
            'agentSecretTotal' => $attention->agentSecretTotal,
            'agentSecretCounts' => $attention->agentSecretCounts,
            'canWrite' => $request->user()?->canWriteOps() ?? false,
        ]);
    }
}
