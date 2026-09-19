<?php

namespace App\Http\Controllers\Ops;

use App\Enums\DeploymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Deployment;
use App\Models\Site;
use App\Services\Sites\Diagnosis\DeploymentDiagnoser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * "Refresh diagnosis": re-read the container log from Coolify and classify again.
 * Never re-runs the automatic fix — that happened once, when the row failed.
 */
class DeploymentDiagnosisController extends Controller
{
    public function __invoke(Request $request, Site $site, Deployment $deployment, DeploymentDiagnoser $diagnoser): RedirectResponse
    {
        $this->authorize('update', $site);

        abort_unless($deployment->site_id === $site->id, 404);

        if ($deployment->status !== DeploymentStatus::Failed) {
            return redirect()
                ->route('ops.sites.deployments.show', [$site, $deployment])
                ->with('error', __('sites.deployments.no_error'));
        }

        $diagnoser->diagnose($deployment, allowAutoFix: false, refetchLogs: true);

        return redirect()
            ->route('ops.sites.deployments.show', [$site, $deployment])
            ->with('status', __('deploy_diagnosis.refreshed'));
    }
}
