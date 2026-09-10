<?php

namespace App\Http\Controllers\Ops;

use App\Http\Controllers\Controller;
use App\Http\Requests\Ops\BulkSiteIdsRequest;
use App\Http\Requests\Ops\PinSiteRequest;
use App\Models\Site;
use App\Services\Coolify\CoolifyApiException;
use App\Services\Coolify\CoolifySiteSync;
use App\Services\Sites\ComposePackException;
use App\Services\Sites\ComposePackMigrator;
use App\Services\Sites\CoolifyDeploySettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class SiteCoolifyOpsController extends Controller
{
    public function migrateCompose(Request $request, Site $site, ComposePackMigrator $migrator): RedirectResponse
    {
        $this->authorize('update', $site);

        try {
            $migrator->migrate($site, $request->user(), $request->ip());
        } catch (ComposePackException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', __('site_ops.pack.done', ['name' => $site->name]));
    }

    public function bulkMigrateCompose(BulkSiteIdsRequest $request, ComposePackMigrator $migrator): RedirectResponse
    {
        $sites = $this->sitesFromBulk($request);

        foreach ($sites as $site) {
            $this->authorize('update', $site);
        }

        if ($sites->isEmpty()) {
            return back()->with('error', __('site_ops.bulk.empty'));
        }

        $result = $migrator->migrateMany($sites, $request->user(), $request->ip());

        return back()->with('status', $this->bulkFlash($result, __('site_ops.pack.bulk')));
    }

    public function autoDeploy(Request $request, Site $site, CoolifyDeploySettings $settings): RedirectResponse
    {
        $this->authorize('update', $site);
        $enabled = $request->boolean('enabled');

        try {
            $settings->setAutoDeploy($site, $enabled, $request->user(), $request->ip());
        } catch (ComposePackException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', $enabled
            ? __('site_ops.auto_deploy.on', ['name' => $site->name])
            : __('site_ops.auto_deploy.off', ['name' => $site->name]));
    }

    public function bulkAutoDeploy(BulkSiteIdsRequest $request, CoolifyDeploySettings $settings): RedirectResponse
    {
        $sites = $this->sitesFromBulk($request);

        foreach ($sites as $site) {
            $this->authorize('update', $site);
        }

        if ($sites->isEmpty()) {
            return back()->with('error', __('site_ops.bulk.empty'));
        }

        $enabled = $request->boolean('enabled');
        $result = $settings->setAutoDeployMany($sites, $enabled, $request->user(), $request->ip());

        return back()->with('status', $this->bulkFlash(
            $result,
            $enabled ? __('site_ops.auto_deploy.bulk_on') : __('site_ops.auto_deploy.bulk_off'),
        ));
    }

    public function pin(PinSiteRequest $request, Site $site, CoolifyDeploySettings $settings): RedirectResponse
    {
        $this->authorize('update', $site);

        try {
            $settings->pin($site, (string) $request->validated('ref'), $request->user(), $request->ip());
        } catch (ComposePackException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', __('site_ops.pin.done', ['name' => $site->name]));
    }

    public function sync(Request $request, Site $site, CoolifySiteSync $sync): RedirectResponse
    {
        $this->authorize('update', $site);

        try {
            $result = $sync->sync($site);
        } catch (CoolifyApiException $exception) {
            return redirect()
                ->route('ops.sites.show', $site)
                ->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('ops.sites.show', $site)
            ->with('status', __('sites.flash.synced', [
                'deployments' => $result['deployments'],
            ]));
    }

    public function redirectGetSync(Site $site): RedirectResponse
    {
        $this->authorize('view', $site);

        return redirect()
            ->route('ops.sites.show', $site)
            ->with('status', __('sites.flash.sync_get'));
    }

    public function followHead(Request $request, Site $site, CoolifyDeploySettings $settings): RedirectResponse
    {
        $this->authorize('update', $site);

        try {
            $settings->followHead($site, $request->user(), $request->ip());
        } catch (ComposePackException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', __('site_ops.pin.follow_done', ['name' => $site->name]));
    }

    /**
     * @return Collection<int, Site>
     */
    private function sitesFromBulk(BulkSiteIdsRequest $request): Collection
    {
        if ($request->boolean('all_dockerfile')) {
            return Site::query()
                ->withDockerfileBuildPackWarning()
                ->whereNotNull('coolify_app_uuid')
                ->where('coolify_app_uuid', '!=', '')
                ->get();
        }

        $ids = $request->validated('site_ids') ?? [];

        return Site::query()->whereIn('id', $ids)->get();
    }

    /**
     * @param  array{ok: int, failed: int, errors: list<string>}  $result
     */
    private function bulkFlash(array $result, string $prefix): string
    {
        $message = $prefix.' '.$result['ok'].' ok';
        if ($result['failed'] > 0) {
            $message .= ', '.$result['failed'].' failed';
            if ($result['errors'] !== []) {
                $message .= ': '.implode(' ', array_slice($result['errors'], 0, 3));
            }
        }

        return $message;
    }
}
