<?php

namespace App\Http\Controllers\Ops;

use App\Http\Controllers\Controller;
use App\Services\Mail\PlatformMailUnsubscribe;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

final class PlatformMailUnsubscribeController extends Controller
{
    public function show(Request $request, PlatformMailUnsubscribe $unsubscribe): View
    {
        abort_unless($unsubscribe->apply($request->fullUrl()), 403);

        return view('ops.platform-mail.unsubscribed');
    }
}
