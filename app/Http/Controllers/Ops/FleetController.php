<?php

namespace App\Http\Controllers\Ops;

use App\Http\Controllers\Controller;
use App\Services\Fleet\FleetDashboardKpis;
use Illuminate\Contracts\View\View;

class FleetController extends Controller
{
    public function index(FleetDashboardKpis $kpis): View
    {
        return view('ops.fleet.index', [
            'channels' => config('ops.channels'),
            'kpis' => $kpis->snapshot(),
            'dockerfilePackSites' => $kpis->dockerfilePackSites(),
        ]);
    }
}
