<?php

namespace App\Http\Controllers\Ops;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\SiteThemeInstallation;
use App\Models\Theme;
use App\Services\Agent\ControlPlaneAgentContract;
use App\Services\Themes\ThemeRolloutException;
use App\Services\Themes\ThemeRolloutService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SiteThemeController extends Controller
{
    public function assign(Request $request, Site $site, ThemeRolloutService $rollout): RedirectResponse
    {
        $this->authorize('assign', Theme::class);
        $this->authorize('update', $site);

        $validated = $request->validate([
            'theme_id' => ['required', 'exists:themes,theme_id'],
            'ref' => ['nullable', 'string', 'max:120'],
            'activate' => ['sometimes', 'boolean'],
            'sync' => ['sometimes', 'boolean'],
            'confirmed' => ['sometimes', 'boolean'],
        ]);

        $theme = Theme::query()->where('theme_id', $validated['theme_id'])->firstOrFail();

        try {
            $rollout->assign($site, $theme, $request->user(), $request->ip(), [
                'ref' => $validated['ref'] ?? null,
                'activate' => $request->boolean('activate'),
                'sync' => $request->boolean('sync'),
                'confirmed' => $request->boolean('confirmed'),
            ]);
        } catch (ThemeRolloutException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', __('sites.theme_flash.assign_started'));
    }

    public function update(Request $request, Site $site, SiteThemeInstallation $installation, ThemeRolloutService $rollout): RedirectResponse
    {
        $this->assertInstallation($site, $installation);
        $this->authorize('assign', Theme::class);

        $rollout->updateToLatest($installation, $request->user(), $request->ip());

        return back()->with('status', __('sites.theme_flash.update_queued'));
    }

    public function sync(Request $request, Site $site, SiteThemeInstallation $installation, ThemeRolloutService $rollout): RedirectResponse
    {
        $this->assertInstallation($site, $installation);
        $this->authorize('assign', Theme::class);

        $validated = $request->validate([
            'mode' => ['sometimes', Rule::in([ControlPlaneAgentContract::SYNC_MODE_MERGE, ControlPlaneAgentContract::SYNC_MODE_OVERWRITE])],
            'confirmed' => ['sometimes', 'boolean'],
        ]);

        $mode = $validated['mode'] ?? ControlPlaneAgentContract::SYNC_MODE_MERGE;
        $overwrite = $mode === ControlPlaneAgentContract::SYNC_MODE_OVERWRITE;

        if ($overwrite && ! $request->boolean('confirmed')) {
            return back()->with('error', __('sites.theme_flash.sync_overwrite_needs_confirm'));
        }

        // An older CMS rejects overwrite with an English validation error; say what to do instead.
        if ($overwrite && ! $site->supportsEditSafeThemeSync()) {
            return back()->with('error', __('sites.theme_flash.sync_overwrite_needs_cms', [
                'version' => ControlPlaneAgentContract::THEME_SYNC_EDIT_SAFE_VERSION,
                'reported' => $site->reportedDeamonVersion() ?? __('ops.unknown'),
            ]));
        }

        try {
            $outcome = $rollout->syncNow($installation, $request->user(), $request->ip(), $mode);
        } catch (ThemeRolloutException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', match (true) {
            $outcome === 'deferred' => __('sites.theme_flash.sync_deferred'),
            $overwrite => __('sites.theme_flash.sync_overwrite_requested'),
            default => __('sites.theme_flash.sync_requested'),
        });
    }

    public function rollbackSync(Request $request, Site $site, SiteThemeInstallation $installation, ThemeRolloutService $rollout): RedirectResponse
    {
        $this->assertInstallation($site, $installation);
        $this->authorize('assign', Theme::class);

        if (! $request->boolean('confirmed')) {
            return back()->with('error', __('sites.theme_flash.rollback_needs_confirm'));
        }

        try {
            $rollout->rollbackLastSync($installation, $request->user(), $request->ip());
        } catch (ThemeRolloutException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', __('sites.theme_flash.sync_rollback_done'));
    }

    public function rollbackFiles(Request $request, Site $site, SiteThemeInstallation $installation, ThemeRolloutService $rollout): RedirectResponse
    {
        $this->assertInstallation($site, $installation);
        $this->authorize('assign', Theme::class);

        if (! $request->boolean('confirmed')) {
            return back()->with('error', __('sites.theme_flash.rollback_needs_confirm'));
        }

        try {
            $rollout->rollbackThemeFiles($installation, $request->user(), $request->ip());
        } catch (ThemeRolloutException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', __('sites.theme_flash.files_rollback_done'));
    }

    public function activate(Request $request, Site $site, SiteThemeInstallation $installation, ThemeRolloutService $rollout): RedirectResponse
    {
        $this->assertInstallation($site, $installation);
        $this->authorize('assign', Theme::class);

        $request->validate([
            'confirmed' => ['sometimes', 'boolean'],
        ]);

        try {
            $rollout->activate($installation, $request->user(), $request->ip(), $request->boolean('confirmed'));
        } catch (ThemeRolloutException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', __('sites.theme_flash.activated'));
    }

    public function autoUpdate(Request $request, Site $site, SiteThemeInstallation $installation, ThemeRolloutService $rollout): RedirectResponse
    {
        $this->assertInstallation($site, $installation);
        $this->authorize('assign', Theme::class);

        $request->validate([
            'auto_update' => ['required', 'boolean'],
        ]);

        $rollout->setAutoUpdate($installation, $request->boolean('auto_update'), $request->user(), $request->ip());

        return back()->with('status', $request->boolean('auto_update')
            ? __('sites.theme_flash.auto_update_on')
            : __('sites.theme_flash.auto_update_off'));
    }

    private function assertInstallation(Site $site, SiteThemeInstallation $installation): void
    {
        abort_unless($installation->site_id === $site->id, 404);
    }
}
