<?php

namespace App\Http\Controllers\Ops;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\SiteThemeInstallation;
use App\Models\Theme;
use App\Services\Themes\ThemeRolloutException;
use App\Services\Themes\ThemeRolloutService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

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

        return back()->with('status', 'Theme assign started via the CMS theme agent. Plane does not copy ZIP or PHP onto Coolify volumes.');
    }

    public function update(Request $request, Site $site, SiteThemeInstallation $installation, ThemeRolloutService $rollout): RedirectResponse
    {
        $this->assertInstallation($site, $installation);
        $this->authorize('assign', Theme::class);

        $rollout->updateToLatest($installation, $request->user(), $request->ip());

        return back()->with('status', 'Update to latest queued for the CMS theme agent.');
    }

    public function sync(Request $request, Site $site, SiteThemeInstallation $installation, ThemeRolloutService $rollout): RedirectResponse
    {
        $this->assertInstallation($site, $installation);
        $this->authorize('assign', Theme::class);

        try {
            $rollout->syncNow($installation, $request->user(), $request->ip());
        } catch (ThemeRolloutException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', 'Theme sync requested on the CMS instance.');
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

        return back()->with('status', 'Theme activated on the CMS instance.');
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
            ? 'Auto-update enabled for this installation. GitHub push will fan out an agent update.'
            : 'Auto-update disabled (the v1 default).');
    }

    private function assertInstallation(Site $site, SiteThemeInstallation $installation): void
    {
        abort_unless($installation->site_id === $site->id, 404);
    }
}
