<?php

namespace App\Http\Controllers\Ops;

use App\Enums\CmsPublishStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Ops\Concerns\QueuesOpsJob;
use App\Http\Requests\Ops\BulkSitePublishStatusRequest;
use App\Http\Requests\Ops\SitePublishStatusRequest;
use App\Models\Site;
use App\Services\Ops\BulkResultSummary;
use App\Services\Ops\PacedFanout;
use App\Services\Sites\SitePublishException;
use App\Services\Sites\SitePublishStateUpdater;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;

class SitePublishStatusController extends Controller
{
    use QueuesOpsJob;

    public function update(SitePublishStatusRequest $request, Site $site, SitePublishStateUpdater $updater): RedirectResponse
    {
        $this->authorize('update', $site);

        $target = CmsPublishStatus::from((string) $request->validated('publish_status'));

        try {
            $applied = $updater->apply($site, $target, $request->user(), $request->ip());
        } catch (SitePublishException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', $applied === CmsPublishStatus::Published
            ? __('sites.publish.flash.published', ['name' => $site->name])
            : __('sites.publish.flash.unpublished', ['name' => $site->name]));
    }

    public function bulkUpdate(
        BulkSitePublishStatusRequest $request,
        SitePublishStateUpdater $updater,
        PacedFanout $fanout,
    ): RedirectResponse|JsonResponse {
        $target = CmsPublishStatus::from((string) $request->validated('publish_status'));
        $sites = $this->sitesFromBulk($request);

        foreach ($sites as $site) {
            $this->authorize('update', $site);
        }

        // No secret means no signed write path at all; say so instead of
        // reporting a row that was never attempted as a failure.
        $withoutSecret = $sites->reject(fn (Site $site): bool => $site->canChangePublishStatus());
        $sites = $sites->filter(fn (Site $site): bool => $site->canChangePublishStatus())->values();

        if ($sites->isEmpty()) {
            return back()->with('error', $withoutSecret->isEmpty()
                ? __('site_ops.bulk.empty')
                : __('sites.publish.bulk_needs_secret', ['count' => $withoutSecret->count()]));
        }

        if ($request->expectsJson()) {
            return $this->queueOpsJob(
                $request,
                'sites.bulk_publish_status',
                $target === CmsPublishStatus::Published
                    ? __('ops.jobs.bulk_publish')
                    : __('ops.jobs.bulk_unpublish'),
                [
                    'site_ids' => $sites->pluck('id')->all(),
                    'publish_status' => $target->value,
                    'ip' => $request->ip(),
                ],
            );
        }

        $result = $fanout->run(
            $sites,
            fn (Site $site) => $updater->apply($site, $target, $request->user(), $request->ip()),
        );

        $prefix = $target === CmsPublishStatus::Published
            ? __('sites.publish.flash.bulk_published')
            : __('sites.publish.flash.bulk_unpublished');

        return back()->with('status', BulkResultSummary::format($prefix, $result, errorLimit: 3));
    }

    /**
     * @return Collection<int, Site>
     */
    private function sitesFromBulk(BulkSitePublishStatusRequest $request): Collection
    {
        if ($request->boolean('all')) {
            return Site::query()
                ->matchingListFilters(
                    trim((string) $request->input('filter_q', '')),
                    (string) $request->input('filter_channel', ''),
                    (string) $request->input('filter_status', ''),
                    (string) $request->input('filter_publish', ''),
                )
                ->orderBy('name')
                ->get();
        }

        return Site::query()
            ->whereIn('id', $request->validated('site_ids') ?? [])
            ->orderBy('name')
            ->get();
    }
}
