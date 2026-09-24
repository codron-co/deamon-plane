<?php

namespace App\Http\Controllers\Ops;

use App\Enums\DeployGate;
use App\Http\Controllers\Controller;
use App\Models\CiBranchHead;
use App\Models\FleetRollout;
use App\Models\Site;
use App\Services\Coolify\EnvCatalog\DeamonRepo;
use App\Services\Rollouts\FleetRolloutException;
use App\Services\Rollouts\FleetRolloutService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * CI-gated CMS rollouts (docs/modules/ci-gated-rollout.md). Every ops role reads
 * them; stopping one is an ordinary operator action (`ops.write`), while pushing
 * a halted rollout on to the whole channel overrides a failed canary and is
 * Super Admin only (`ops.danger`).
 */
class FleetRolloutController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Site::class);

        $repo = DeamonRepo::fullName();

        return view('ops.rollouts.index', [
            'rollouts' => FleetRollout::query()->orderByDesc('id')->paginate(25),
            'heads' => $repo === null
                ? collect()
                : CiBranchHead::query()
                    ->where('repo_full_name', CiBranchHead::normalizeRepo($repo))
                    ->orderBy('branch')
                    ->get(),
            'gatedSites' => Site::query()->where('deploy_gate', DeployGate::Ci->value)->count(),
            'canarySites' => Site::query()->where('deploy_gate', DeployGate::Ci->value)->where('deploy_canary', true)->count(),
            'canWrite' => $request->user()?->can('ops.write') ?? false,
            'canDanger' => $request->user()?->can('ops.danger') ?? false,
        ]);
    }

    public function show(Request $request, FleetRollout $rollout): View
    {
        $this->authorize('viewAny', Site::class);

        $ids = [];
        foreach (['canary', 'fanout'] as $stage) {
            foreach ((array) ($rollout->stage($stage)['site_ids'] ?? []) as $id) {
                $ids[] = (string) $id;
            }
        }

        return view('ops.rollouts.show', [
            'rollout' => $rollout,
            'sites' => Site::withTrashed()->whereIn('id', array_unique($ids))->get()->keyBy(fn (Site $site): string => (string) $site->id),
            'canWrite' => $request->user()?->can('ops.write') ?? false,
            'canDanger' => $request->user()?->can('ops.danger') ?? false,
        ]);
    }

    public function halt(Request $request, FleetRollout $rollout, FleetRolloutService $service): RedirectResponse
    {
        $this->authorize('ops.write');

        try {
            $service->halt($rollout, $request->user(), $request->ip());
        } catch (FleetRolloutException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', __('rollouts.flash.halted', ['sha' => $rollout->shortSha()]));
    }

    public function resume(Request $request, FleetRollout $rollout, FleetRolloutService $service): RedirectResponse
    {
        $this->authorize('ops.danger');

        try {
            $service->resume($rollout, $request->user(), $request->ip());
        } catch (FleetRolloutException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', __('rollouts.flash.resumed', ['sha' => $rollout->shortSha()]));
    }
}
