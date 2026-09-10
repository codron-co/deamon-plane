<?php

namespace App\Http\Controllers\Ops;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Services\Sites\SiteAppHealthException;
use App\Services\Sites\SiteAppHealthFixer;
use App\Services\Sites\SiteAppHealthInspector;
use App\Services\Sites\SiteAppHealthReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class SiteAppHealthController extends Controller
{
    public function refresh(Request $request, Site $site, SiteAppHealthInspector $inspector): JsonResponse|RedirectResponse
    {
        $this->authorize('view', $site);

        $live = $request->user()?->can('update', $site) ?? false;
        $report = $inspector->inspect($site, live: $live && filled($site->coolify_app_uuid));

        return $this->respond($request, $site, $report->toView($site), __('sites.app_health.refreshed'), true);
    }

    public function fix(Request $request, Site $site, SiteAppHealthFixer $fixer): JsonResponse|RedirectResponse
    {
        $this->authorize('update', $site);

        $fix = trim((string) $request->input('fix', ''));

        try {
            $report = $fixer->fix($site, $fix, $request->user(), $request->ip());
        } catch (SiteAppHealthException|InvalidArgumentException $exception) {
            return $this->respond(
                $request,
                $site,
                SiteAppHealthReport::forDisplay($site)->toView($site),
                $exception->getMessage(),
                false,
            );
        }

        return $this->respond(
            $request,
            $site,
            $report->toView($site->fresh() ?? $site),
            __('sites.app_health.fixed'),
            true,
        );
    }

    /**
     * @param  array<string, mixed>  $health
     */
    private function respond(Request $request, Site $site, array $health, string $message, bool $ok): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json([
                'ok' => $ok,
                'type' => $ok ? 'status' : 'error',
                'message' => $message,
                'health' => $health,
            ], $ok ? 200 : 422);
        }

        return back()->with($ok ? 'status' : 'error', $message);
    }
}
