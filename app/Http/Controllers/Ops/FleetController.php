<?php

namespace App\Http\Controllers\Ops;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;

class FleetController extends Controller
{
    public function index(): View
    {
        return view('ops.fleet.index', [
            'channels' => config('ops.channels'),
        ]);
    }
}
