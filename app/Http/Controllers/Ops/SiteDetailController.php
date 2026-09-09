<?php

namespace App\Http\Controllers\Ops;

use App\Http\Controllers\Controller;
use App\Models\Site;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class SiteDetailController extends Controller
{
    public function __invoke(Request $request, Site $site): View
    {
        $this->authorize('view', $site);

        $site->load([
            'activeThemeInstallation.theme',
            'coolifyConnection',
        ]);

        return view('ops.sites.show', [
            'site' => $site,
            'canEdit' => $request->user()?->can('update', $site) ?? false,
        ]);
    }
}
