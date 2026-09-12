<?php

namespace App\Http\Controllers\Ops;

use App\Enums\Channel;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Ops\Concerns\QueuesOpsJob;
use App\Http\Requests\Ops\BulkPinSiteRequest;
use App\Http\Requests\Ops\BulkSiteIdsRequest;
use App\Http\Requests\Ops\PinSiteRequest;
use App\Models\Site;
use App\Services\Coolify\CoolifyApiException;
use App\Services\Coolify\CoolifySiteSync;
use App\Services\Ops\BulkResultSummary;
use App\Services\Sites\ChannelSwitcher;
use App\Services\Sites\ChannelSwitchException;
use App\Services\Sites\ComposePackException;
use App\Services\Sites\ComposePackMigrator;
use App\Services\Sites\CoolifyDeploySettings;
use App\Services\Sites\SiteLifecycle;
use App\Services\Sites\SiteLiveProbe;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class SiteCoolifyOpsController extends Controller
{
    use QueuesOpsJob;

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

    public function bulkMigrateCompose(BulkSiteIdsRequest $request, ComposePackMigrator $migrator): RedirectResponse|JsonResponse
    {
        $sites = $this->sitesFromBulk($request);
        if ($request->boolean('all') || $request->boolean('all_dockerfile')) {
            $sites = $sites->filter(fn (Site $site): bool => $site->hasDockerfileBuildPackWarning());
        }

        foreach ($sites as $site) {
            $this->authorize('update', $site);
        }

        if ($sites->isEmpty()) {
            return back()->with('error', __('site_ops.bulk.empty'));
        }

        if ($request->expectsJson()) {
            return $this->queueOpsJob($request, 'sites.bulk_compose', __('ops.jobs.bulk_compose'), [
                'site_ids' => $sites->pluck('id')->all(),
                'ip' => $request->ip(),
            ]);
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

    public function bulkAutoDeploy(BulkSiteIdsRequest $request, CoolifyDeploySettings $settings): RedirectResponse|JsonResponse
    {
        $sites = $this->sitesFromBulk($request);

        foreach ($sites as $site) {
            $this->authorize('update', $site);
        }

        if ($sites->isEmpty()) {
            return back()->with('error', __('site_ops.bulk.empty'));
        }

        if ($request->expectsJson()) {
            $payload = [
                'site_ids' => $sites->pluck('id')->all(),
                'ip' => $request->ip(),
            ];
            if ($request->exists('enabled')) {
                $payload['enabled'] = $request->boolean('enabled');
            }

            return $this->queueOpsJob($request, 'sites.bulk_auto_deploy', __('ops.jobs.bulk_auto_deploy'), $payload);
        }

        $enabled = $request->exists('enabled')
            ? $request->boolean('enabled')
            : $settings->toggleEnabledFor($sites);
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

    public function sync(Request $request, Site $site, CoolifySiteSync $sync): RedirectResponse|JsonResponse
    {
        $this->authorize('update', $site);

        if ($request->expectsJson()) {
            return $this->queueOpsJob($request, 'sites.coolify_sync', __('ops.jobs.coolify_sync'), [
                'site_ids' => [$site->id],
                'subject' => $site->name,
            ]);
        }

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

    public function bulkSync(BulkSiteIdsRequest $request, CoolifySiteSync $sync): RedirectResponse|JsonResponse
    {
        $sites = $this->sitesFromBulk($request)
            ->filter(fn (Site $site): bool => filled($site->coolify_app_uuid));

        foreach ($sites as $site) {
            $this->authorize('update', $site);
        }

        if ($sites->isEmpty()) {
            return back()->with('error', __('site_ops.bulk.empty'));
        }

        if ($request->expectsJson()) {
            return $this->queueOpsJob($request, 'sites.coolify_sync', __('ops.jobs.coolify_sync'), [
                'site_ids' => $sites->pluck('id')->all(),
            ]);
        }

        $ok = 0;
        $failed = 0;
        $deployments = 0;
        $errors = [];

        foreach ($sites as $site) {
            try {
                $result = $sync->sync($site);
                $ok++;
                $deployments += (int) ($result['deployments'] ?? 0);
            } catch (CoolifyApiException $exception) {
                $failed++;
                $errors[] = $site->name.': '.$exception->getMessage();
            }
        }

        return back()->with('status', $this->bulkFlash([
            'ok' => $ok,
            'failed' => $failed,
            'errors' => $errors,
        ], __('sites.flash.bulk_synced', ['deployments' => $deployments])));
    }

    public function redirectGetBulkSync(): RedirectResponse
    {
        return redirect()
            ->route('ops.sites')
            ->with('status', __('sites.flash.sync_get'));
    }

    public function liveSyncOne(Request $request, Site $site, SiteLiveProbe $probe): RedirectResponse|JsonResponse
    {
        $this->authorize('update', $site);

        if (blank($site->primary_domain)) {
            return back()->with('error', __('sites.flash.live_sync_missing_domain'));
        }

        if ($request->expectsJson()) {
            return $this->queueOpsJob($request, 'sites.live_sync', __('ops.jobs.live_sync'), [
                'site_ids' => [$site->id],
                'subject' => $site->name,
            ]);
        }

        $result = $probe->probeMany(collect([$site]));

        return redirect()
            ->route('ops.sites.show', $site)
            ->with('status', __('sites.flash.live_synced', [
                'ok' => $result['ok'],
                'failed' => $result['failed'],
            ]));
    }

    public function redirectGetLiveSyncOne(Site $site): RedirectResponse
    {
        $this->authorize('view', $site);

        return redirect()
            ->route('ops.sites.show', $site)
            ->with('status', __('sites.flash.live_sync_get'));
    }

    public function bulkPurge(BulkSiteIdsRequest $request, SiteLifecycle $lifecycle): RedirectResponse
    {
        $sites = $this->sitesFromBulk($request);

        foreach ($sites as $site) {
            $this->authorize('forceDelete', $site);
        }

        if ($sites->isEmpty()) {
            return back()->with('error', __('site_ops.bulk.empty'));
        }

        $result = $lifecycle->purgeMany($sites, $request->user(), $request->ip());

        return back()->with('status', $this->bulkFlash($result, __('sites.flash.bulk_purged')));
    }

    public function liveSync(BulkSiteIdsRequest $request, SiteLiveProbe $probe): RedirectResponse|JsonResponse
    {
        $sites = $this->sitesFromBulk($request)
            ->filter(fn (Site $site): bool => filled($site->primary_domain));

        foreach ($sites as $site) {
            $this->authorize('update', $site);
        }

        if ($sites->isEmpty()) {
            return back()->with('error', __('site_ops.bulk.empty'));
        }

        if ($request->expectsJson()) {
            return $this->queueOpsJob($request, 'sites.live_sync', __('ops.jobs.live_sync'), [
                'site_ids' => $sites->pluck('id')->all(),
            ]);
        }

        $result = $probe->probeMany($sites);

        return back()->with('status', __('sites.flash.live_synced', [
            'ok' => $result['ok'],
            'failed' => $result['failed'],
        ]));
    }

    public function redirectGetLiveSync(): RedirectResponse
    {
        return redirect()
            ->route('ops.sites')
            ->with('status', __('sites.flash.live_sync_get'));
    }

    public function redirectGetSync(Site $site): RedirectResponse
    {
        $this->authorize('view', $site);

        return redirect()
            ->route('ops.sites.show', $site)
            ->with('status', __('sites.flash.sync_get'));
    }

    public function bulkChannel(BulkSiteIdsRequest $request, ChannelSwitcher $switcher): RedirectResponse|JsonResponse
    {
        $targetValue = (string) $request->validated('channel', '');
        if ($targetValue === '' || Channel::tryFrom($targetValue) === null) {
            return back()->with('error', __('site_ops.bulk.empty'));
        }

        $target = Channel::from($targetValue);
        $sites = $this->sitesFromBulk($request);

        foreach ($sites as $site) {
            $this->authorize('update', $site);
        }

        if ($sites->isEmpty()) {
            return back()->with('error', __('site_ops.bulk.empty'));
        }

        if ($request->expectsJson()) {
            return $this->queueOpsJob($request, 'sites.bulk_channel', __('ops.jobs.bulk_channel'), [
                'site_ids' => $sites->pluck('id')->all(),
                'channel' => $target->value,
                'confirmed' => $request->boolean('confirmed'),
                'force' => $request->boolean('force'),
                'ip' => $request->ip(),
            ]);
        }

        $ok = 0;
        $failed = 0;
        $skipped = 0;
        $errors = [];
        $confirmed = $request->boolean('confirmed');
        $force = $request->boolean('force');

        foreach ($sites as $site) {
            $current = $site->channel instanceof Channel ? $site->channel : Channel::tryFrom((string) $site->channel);
            if ($current === $target) {
                $skipped++;

                continue;
            }

            try {
                $switcher->start($site, $target, $request->user(), $request->ip(), $confirmed, $force);
                $ok++;
            } catch (ChannelSwitchException $exception) {
                $failed++;
                $errors[] = $site->name.': '.$exception->getMessage();
            }
        }

        if ($ok === 0 && $failed === 0) {
            return back()->with('error', __('site_ops.bulk.empty'));
        }

        return back()->with('status', $this->bulkTriggerFlash([
            'ok' => $ok,
            'failed' => $failed,
            'errors' => $errors,
        ], __('sites.flash.bulk_channel', ['channel' => $target->value, 'skipped' => $skipped])));
    }

    public function redirectGetBulkChannel(): RedirectResponse
    {
        return redirect()
            ->route('ops.sites')
            ->with('status', __('sites.flash.channel_get'));
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

    public function deploy(Request $request, Site $site, CoolifyDeploySettings $settings): RedirectResponse
    {
        $this->authorize('update', $site);

        try {
            $settings->redeploy($site, $request->user(), $request->ip());
        } catch (ComposePackException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', __('site_ops.redeploy.done', ['name' => $site->name]));
    }

    public function bulkDeploy(BulkSiteIdsRequest $request, CoolifyDeploySettings $settings): RedirectResponse|JsonResponse
    {
        return $this->runBulkDeploy(
            $request,
            'sites.bulk_deploy',
            __('ops.jobs.bulk_deploy'),
            fn (Collection $sites) => $settings->redeployMany($sites, $request->user(), $request->ip()),
            __('site_ops.redeploy.bulk'),
        );
    }

    public function bulkFollowHead(BulkSiteIdsRequest $request, CoolifyDeploySettings $settings): RedirectResponse|JsonResponse
    {
        return $this->runBulkDeploy(
            $request,
            'sites.bulk_follow_head',
            __('ops.jobs.bulk_follow_head'),
            fn (Collection $sites) => $settings->followHeadMany($sites, $request->user(), $request->ip()),
            __('site_ops.pin.bulk_follow'),
        );
    }

    public function bulkPin(BulkPinSiteRequest $request, CoolifyDeploySettings $settings): RedirectResponse|JsonResponse
    {
        $ref = (string) $request->validated('ref');

        return $this->runBulkDeploy(
            $request,
            'sites.bulk_pin',
            __('ops.jobs.bulk_pin'),
            fn (Collection $sites) => $settings->pinMany($sites, $ref, $request->user(), $request->ip()),
            __('site_ops.pin.bulk'),
            ['ref' => $ref],
        );
    }

    /**
     * @param  callable(Collection<int, Site>): array{ok: int, failed: int, skipped: int, errors: list<string>}  $run
     * @param  array<string, mixed>  $extraPayload
     */
    private function runBulkDeploy(
        BulkSiteIdsRequest $request,
        string $jobType,
        string $jobTitle,
        callable $run,
        string $flashPrefix,
        array $extraPayload = [],
    ): RedirectResponse|JsonResponse {
        $sites = $this->sitesFromBulk($request)
            ->filter(fn (Site $site): bool => filled($site->coolify_app_uuid))
            ->values();

        foreach ($sites as $site) {
            $this->authorize('update', $site);
        }

        if ($sites->isEmpty()) {
            return back()->with('error', __('site_ops.bulk.empty'));
        }

        if ($request->expectsJson()) {
            return $this->queueOpsJob($request, $jobType, $jobTitle, array_merge([
                'site_ids' => $sites->pluck('id')->all(),
                'ip' => $request->ip(),
            ], $extraPayload));
        }

        return back()->with('status', $this->bulkTriggerFlash($run($sites), $flashPrefix));
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

        $ids = $request->validated('site_ids') ?? [];

        return Site::query()->whereIn('id', $ids)->get();
    }

    /**
     * @param  array{ok: int, failed: int, skipped?: int, errors: list<string>}  $result
     */
    private function bulkFlash(array $result, string $prefix): string
    {
        return BulkResultSummary::format($prefix, $result, errorLimit: 3);
    }

    /**
     * Sweeps that hand the build to Coolify: success means "triggered", not "done".
     *
     * @param  array{ok: int, failed: int, skipped?: int, errors: list<string>}  $result
     */
    private function bulkTriggerFlash(array $result, string $prefix): string
    {
        return BulkResultSummary::formatTriggered($prefix, $result, errorLimit: 3);
    }
}
