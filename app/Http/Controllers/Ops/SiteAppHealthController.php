<?php

namespace App\Http\Controllers\Ops;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Ops\Concerns\QueuesOpsJob;
use App\Http\Requests\Ops\BulkAppHealthFixRequest;
use App\Models\Site;
use App\Services\Sites\SiteAppHealthException;
use App\Services\Sites\SiteAppHealthFixer;
use App\Services\Sites\SiteAppHealthInspector;
use App\Services\Sites\SiteAppHealthReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class SiteAppHealthController extends Controller
{
    use QueuesOpsJob;

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

    public function bulkFix(BulkAppHealthFixRequest $request, SiteAppHealthFixer $fixer): JsonResponse|RedirectResponse
    {
        $fix = (string) $request->validated('fix');
        $sites = $this->sitesFromBulk($request);

        foreach ($sites as $site) {
            $this->authorize('update', $site);
        }

        $targets = $sites->filter(function (Site $site) use ($fixer, $fix): bool {
            $needed = $fixer->neededFixes($site);

            return $fix === 'all' ? $needed !== [] : in_array($fix, $needed, true);
        })->values();

        if ($targets->isEmpty()) {
            return back()->with('error', __('sites.app_health.bulk_empty'));
        }

        if ($request->expectsJson()) {
            return $this->queueOpsJob($request, 'sites.bulk_app_health_fix', __('ops.jobs.bulk_app_health_fix'), [
                'site_ids' => $targets->pluck('id')->all(),
                'fix' => $fix,
                'ip' => $request->ip(),
            ]);
        }

        $result = $fixer->fixMany($targets, $fix, $request->user(), $request->ip());
        $message = __('sites.app_health.bulk_done', [
            'ok' => $result['ok'],
            'failed' => $result['failed'],
        ]);

        return back()->with($result['failed'] > 0 ? 'error' : 'status', $message);
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

    /**
     * @return Collection<int, Site>
     */
    private function sitesFromBulk(BulkAppHealthFixRequest $request): Collection
    {
        if ($request->boolean('all')) {
            return Site::query()
                ->with('latestDeployment')
                ->matchingListFilters(
                    trim((string) $request->input('filter_q', '')),
                    (string) $request->input('filter_channel', ''),
                    (string) $request->input('filter_status', ''),
                )
                ->orderBy('name')
                ->get();
        }

        $ids = $request->validated('site_ids') ?? [];

        return Site::query()->with('latestDeployment')->whereIn('id', $ids)->get();
    }
}
