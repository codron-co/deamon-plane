<?php

namespace App\Http\Controllers\Ops;

use App\Http\Controllers\Controller;
use App\Models\CoolifySetting;
use App\Models\Deployment;
use App\Models\Site;
use App\Services\Sites\DeploymentFailureText;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class DeploymentShowController extends Controller
{
    public function __invoke(Request $request, Site $site, Deployment $deployment): View
    {
        $this->authorize('view', $site);

        abort_unless($deployment->site_id === $site->id, 404);

        $deployment->load(['site.coolifyConnection', 'requestedBy']);
        $connection = $site->coolifyConnection;

        return view('ops.deployments.show', [
            'site' => $site,
            'deployment' => $deployment,
            'pasteable' => DeploymentFailureText::pasteable($deployment),
            'coolifyAppUrl' => ($connection ?? CoolifySetting::current())->applicationUiUrl($site->coolify_app_uuid),
        ]);
    }
}
