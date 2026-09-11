<?php

namespace App\Services\Ops;

use App\Enums\DeploymentStatus;
use App\Enums\DeploymentTrigger;
use App\Models\CoolifyConnection;
use App\Models\Deployment;
use App\Models\Site;
use App\Services\Coolify\CoolifyApiException;
use App\Services\Coolify\CoolifyApplicationService;
use App\Services\Coolify\CoolifyDeploymentSync;
use App\Services\Coolify\Dto\CoolifyDeployment;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class OpsCoolifyDeployQueue
{
    public function __construct(
        private readonly CoolifyDeploymentSync $sync,
    ) {}

    /**
     * Live Coolify queue (queued + in_progress) limited to Plane Deamon sites.
     *
     * @return list<array<string, mixed>>
     */
    public function widgetRows(): array
    {
        $sites = Site::query()
            ->whereNotNull('coolify_app_uuid')
            ->where('coolify_app_uuid', '!=', '')
            ->get(['id', 'name', 'slug', 'coolify_app_uuid', 'coolify_connection_id']);

        if ($sites->isEmpty()) {
            return [];
        }

        /** @var Collection<string, Site> $byUuid */
        $byUuid = $sites->keyBy(static fn (Site $site): string => (string) $site->coolify_app_uuid);
        /** @var Collection<string, Site> $byName */
        $byName = $sites->keyBy(static fn (Site $site): string => mb_strtolower(trim((string) $site->name)));

        $rows = [];
        $seen = [];

        foreach ($this->connectionsFor($sites) as $connection) {
            try {
                $remoteRows = CoolifyApplicationService::forConnection($connection)->listRunningDeployments();
            } catch (CoolifyApiException) {
                continue;
            }

            foreach ($remoteRows as $remote) {
                $site = $this->matchSite($remote, $byUuid, $byName);
                if ($site === null) {
                    continue;
                }

                $uuid = $remote->uuid !== '' ? $remote->uuid : (string) ($remote->raw['deployment_uuid'] ?? '');
                if ($uuid === '' || isset($seen[$uuid])) {
                    continue;
                }
                $seen[$uuid] = true;

                $local = Deployment::query()
                    ->where('coolify_deployment_uuid', $uuid)
                    ->first();

                if ($local instanceof Deployment) {
                    $this->sync->applyExisting($local, $remote);
                    $local->refresh();
                    $rows[] = $local->toWidget();

                    continue;
                }

                $rows[] = $this->remoteWidget($site, $remote, $uuid);
            }
        }

        return $rows;
    }

    /**
     * Pull Coolify status for open Plane deployments so ghost timers clear without waiting on queue delay.
     *
     * @param  Collection<int, Deployment>  $deployments
     */
    public function refreshOpen(Collection $deployments, int $staleAfterSeconds = 20): void
    {
        $cutoff = now()->subSeconds(max(1, $staleAfterSeconds));

        foreach ($deployments as $deployment) {
            if (! in_array($deployment->status, [DeploymentStatus::Queued, DeploymentStatus::InProgress], true)) {
                continue;
            }

            if (blank($deployment->coolify_deployment_uuid) || $deployment->site === null) {
                continue;
            }

            $started = $deployment->started_at ?? $deployment->created_at;
            if ($started !== null && $started->gt($cutoff)) {
                continue;
            }

            $lockKey = 'ops.deploy.refresh.'.$deployment->id;
            if (! Cache::add($lockKey, 1, now()->addSeconds($staleAfterSeconds))) {
                continue;
            }

            try {
                $remote = CoolifyApplicationService::forSite($deployment->site)
                    ->getDeployment((string) $deployment->coolify_deployment_uuid);
                $this->sync->applyExisting($deployment, $remote);
                $deployment->refresh();
            } catch (CoolifyApiException) {
                // Keep the last known status when Coolify is unreachable.
            }
        }
    }

    public function cancel(Deployment $deployment): Deployment
    {
        $uuid = trim((string) $deployment->coolify_deployment_uuid);
        if ($uuid === '' || $deployment->site === null) {
            abort(422, __('ops.jobs.cancel_unavailable'));
        }

        if (! in_array($deployment->status, [DeploymentStatus::Queued, DeploymentStatus::InProgress], true)) {
            abort(422, __('ops.jobs.cancel_unavailable'));
        }

        try {
            CoolifyApplicationService::forSite($deployment->site)->cancelDeployment($uuid);
        } catch (CoolifyApiException $exception) {
            abort(422, trim($exception->getMessage()) !== '' ? $exception->getMessage() : __('ops.jobs.cancel_failed'));
        }

        try {
            $remote = CoolifyApplicationService::forSite($deployment->site)->getDeployment($uuid);
            $this->sync->applyExisting($deployment, $remote);
        } catch (CoolifyApiException) {
            $deployment->forceFill([
                'status' => DeploymentStatus::Cancelled,
                'finished_at' => $deployment->finished_at ?? now(),
                'error_message' => $deployment->error_message ?: __('ops.jobs.status.cancelled'),
            ])->save();
        }

        return $deployment->fresh(['site']) ?? $deployment;
    }

    /**
     * Promote a queued Coolify deploy: cancel the queue row, then instant-start the app.
     */
    public function forceStart(Deployment $deployment): Deployment
    {
        $site = $deployment->site;
        $appUuid = trim((string) ($site?->coolify_app_uuid ?? ''));
        $deployUuid = trim((string) $deployment->coolify_deployment_uuid);

        if ($site === null || $appUuid === '') {
            abort(422, __('ops.jobs.force_start_unavailable'));
        }

        if ($deployment->status !== DeploymentStatus::Queued && $deployment->status !== DeploymentStatus::InProgress) {
            abort(422, __('ops.jobs.force_start_unavailable'));
        }

        // Only queued rows are force-started; in_progress already runs.
        if ($deployment->status !== DeploymentStatus::Queued) {
            abort(422, __('ops.jobs.force_start_only_queued'));
        }

        $coolify = CoolifyApplicationService::forSite($site);

        if ($deployUuid !== '') {
            try {
                $coolify->cancelDeployment($deployUuid);
            } catch (CoolifyApiException) {
                // Queue row may already have advanced; still try instant start.
            }

            $this->sync->applyExisting($deployment, CoolifyDeployment::fromArray([
                'uuid' => $deployUuid,
                'status' => 'cancelled_by_user',
            ]));
        }

        try {
            $result = $coolify->startApplication($appUuid, force: true, instantDeploy: true);
        } catch (CoolifyApiException $exception) {
            abort(422, trim($exception->getMessage()) !== '' ? $exception->getMessage() : __('ops.jobs.force_start_failed'));
        }

        $newUuid = $result->firstDeploymentUuid();
        if ($newUuid === null || $newUuid === '') {
            abort(422, __('ops.jobs.force_start_failed'));
        }

        $channel = $site->channel;
        $fresh = $site->deployments()->make([
            'channel' => $channel,
            'trigger' => DeploymentTrigger::Manual,
            'coolify_deployment_uuid' => $newUuid,
            'status' => DeploymentStatus::InProgress,
            'started_at' => now(),
            'requested_by' => $deployment->requested_by,
        ]);
        $fresh->save();

        try {
            $remote = $coolify->getDeployment($newUuid);
            $this->sync->applyExisting($fresh, $remote);
        } catch (CoolifyApiException) {
            // Widget will pick it up on the next poll.
        }

        return $fresh->fresh(['site']) ?? $fresh;
    }

    /**
     * Cancel a Coolify queue row that is not yet a Plane deployment row (matched by uuid).
     */
    public function cancelRemote(string $deploymentUuid): ?Deployment
    {
        $deploymentUuid = trim($deploymentUuid);
        if ($deploymentUuid === '') {
            abort(404);
        }

        $local = Deployment::query()->with('site')->where('coolify_deployment_uuid', $deploymentUuid)->first();
        if ($local instanceof Deployment) {
            return $this->cancel($local);
        }

        $site = $this->siteForRemoteUuid($deploymentUuid);
        if ($site === null) {
            abort(404);
        }

        try {
            CoolifyApplicationService::forSite($site)->cancelDeployment($deploymentUuid);
        } catch (CoolifyApiException $exception) {
            abort(422, trim($exception->getMessage()) !== '' ? $exception->getMessage() : __('ops.jobs.cancel_failed'));
        }

        $created = $site->deployments()->make([
            'channel' => $site->channel,
            'trigger' => DeploymentTrigger::Manual,
            'coolify_deployment_uuid' => $deploymentUuid,
            'status' => DeploymentStatus::Cancelled,
            'started_at' => now(),
            'finished_at' => now(),
        ]);
        $created->save();

        return $created->fresh(['site']) ?? $created;
    }

    public function forceStartRemote(string $deploymentUuid): Deployment
    {
        $deploymentUuid = trim($deploymentUuid);
        $local = Deployment::query()->with('site')->where('coolify_deployment_uuid', $deploymentUuid)->first();
        if ($local instanceof Deployment) {
            return $this->forceStart($local);
        }

        $site = $this->siteForRemoteUuid($deploymentUuid);
        if ($site === null) {
            abort(404);
        }

        $created = $site->deployments()->make([
            'channel' => $site->channel,
            'trigger' => DeploymentTrigger::Manual,
            'coolify_deployment_uuid' => $deploymentUuid,
            'status' => DeploymentStatus::Queued,
            'started_at' => now(),
        ]);
        $created->save();

        return $this->forceStart($created->fresh(['site']) ?? $created);
    }

    /**
     * @param  Collection<int, Site>  $sites
     * @return Collection<int, CoolifyConnection>
     */
    private function connectionsFor(Collection $sites): Collection
    {
        $ids = $sites->pluck('coolify_connection_id')->filter()->unique()->values();
        $connections = CoolifyConnection::query()
            ->where('is_enabled', true)
            ->when($ids->isNotEmpty(), fn ($query) => $query->whereIn('id', $ids))
            ->get();

        if ($connections->isEmpty()) {
            $default = CoolifyConnection::default();
            if ($default instanceof CoolifyConnection && $default->is_enabled) {
                return collect([$default]);
            }
        }

        return $connections;
    }

    /**
     * @param  Collection<string, Site>  $byUuid
     * @param  Collection<string, Site>  $byName
     */
    private function matchSite(CoolifyDeployment $remote, Collection $byUuid, Collection $byName): ?Site
    {
        $appUuid = trim((string) ($remote->applicationUuid ?? $remote->raw['application_uuid'] ?? ''));
        if ($appUuid !== '' && $byUuid->has($appUuid)) {
            return $byUuid->get($appUuid);
        }

        $name = mb_strtolower(trim((string) ($remote->raw['application_name'] ?? '')));
        if ($name !== '' && $byName->has($name)) {
            return $byName->get($name);
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function remoteWidget(Site $site, CoolifyDeployment $remote, string $uuid): array
    {
        $status = $this->sync->mapRemoteStatus($remote->status);
        $widgetStatus = match ($status) {
            DeploymentStatus::Queued => 'queued',
            DeploymentStatus::Failed => 'failed',
            DeploymentStatus::Cancelled => 'cancelled',
            DeploymentStatus::Finished => 'completed',
            default => 'running',
        };

        return [
            'id' => 'coolify-'.$uuid,
            'type' => 'coolify.deployment',
            'title' => $site->name,
            'status' => $widgetStatus,
            'progress' => in_array($widgetStatus, ['queued', 'running'], true) ? null : 100,
            'indeterminate' => in_array($widgetStatus, ['queued', 'running'], true),
            'message' => __('ops.jobs.deployment').' · '.($status->label()),
            'url' => route('ops.sites.show', $site),
            'deployment_id' => null,
            'coolify_deployment_uuid' => $uuid,
            'actions' => [
                'cancel' => in_array($widgetStatus, ['queued', 'running'], true),
                'force_start' => $widgetStatus === 'queued',
                'dismiss' => in_array($widgetStatus, ['completed', 'failed', 'cancelled'], true),
            ],
        ];
    }

    private function siteForRemoteUuid(string $deploymentUuid): ?Site
    {
        foreach (CoolifyConnection::query()->where('is_enabled', true)->get() as $connection) {
            try {
                $remote = CoolifyApplicationService::forConnection($connection)->getDeployment($deploymentUuid);
            } catch (CoolifyApiException) {
                continue;
            }

            $sites = Site::query()
                ->whereNotNull('coolify_app_uuid')
                ->get(['id', 'name', 'slug', 'coolify_app_uuid', 'coolify_connection_id', 'channel']);
            $byUuid = $sites->keyBy(static fn (Site $site): string => (string) $site->coolify_app_uuid);
            $byName = $sites->keyBy(static fn (Site $site): string => mb_strtolower(trim((string) $site->name)));

            return $this->matchSite($remote, $byUuid, $byName);
        }

        return null;
    }
}
