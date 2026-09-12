<?php

namespace App\Services\Ops;

use App\Enums\DeploymentStatus;
use App\Enums\DeploymentTrigger;
use App\Models\CoolifyConnection;
use App\Models\Deployment;
use App\Models\Site;
use App\Models\User;
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
                'error_message' => $deployment->error_message ?: __('ops.deploy_failure.cancelled'),
            ])->save();
        }

        return $deployment->fresh(['site']) ?? $deployment;
    }

    /**
     * Promote a queued Coolify deploy: drop it out of the Coolify queue, then
     * instant-start the app.
     *
     * The operator pressed Force on **one** row, so Plane keeps one row: the
     * same deployment is repointed at the instant-started Coolify deployment
     * and goes queued -> running. Force must never leave an "iptal" row behind,
     * because cancelling the queue slot is our mechanics, not the outcome the
     * operator asked for.
     */
    public function forceStart(Deployment $deployment, ?User $actor = null): Deployment
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
        }

        try {
            $result = $coolify->startApplication($appUuid, force: true, instantDeploy: true);
        } catch (CoolifyApiException $exception) {
            $this->failForceStart($deployment);

            abort(422, trim($exception->getMessage()) !== '' ? $exception->getMessage() : __('ops.jobs.force_start_failed'));
        }

        $newUuid = $result->firstDeploymentUuid();
        if ($newUuid === null || $newUuid === '') {
            $this->failForceStart($deployment);

            abort(422, __('ops.jobs.force_start_failed'));
        }

        $live = $this->adopt($deployment, $newUuid);

        $live->forceFill([
            'trigger' => DeploymentTrigger::Manual,
            'coolify_deployment_uuid' => $newUuid,
            'status' => DeploymentStatus::InProgress,
            'started_at' => now(),
            'finished_at' => null,
            'error_message' => null,
            'requested_by' => $live->requested_by ?? $actor?->id,
        ])->save();

        $site->auditLogs()->create([
            'actor_user_id' => $actor?->id,
            'action' => 'site.deploy_force_started',
            'before' => ['coolify_deployment_uuid' => $deployUuid !== '' ? $deployUuid : null, 'status' => DeploymentStatus::Queued->value],
            'after' => ['coolify_deployment_uuid' => $newUuid, 'status' => DeploymentStatus::InProgress->value],
            'ip' => null,
        ]);

        try {
            $remote = $coolify->getDeployment($newUuid);
            $this->sync->applyExisting($live, $remote);
        } catch (CoolifyApiException) {
            // Widget will pick it up on the next poll.
        }

        return $live->fresh(['site']) ?? $live;
    }

    /**
     * A webhook can have already written a row for the instant-started deploy.
     * Then that row is the live one and the forced queue row is retired with
     * copy that says what happened instead of a bare "cancelled".
     */
    private function adopt(Deployment $deployment, string $newUuid): Deployment
    {
        $existing = Deployment::query()
            ->with('site')
            ->where('coolify_deployment_uuid', $newUuid)
            ->whereKeyNot($deployment->getKey())
            ->first();

        if (! $existing instanceof Deployment) {
            return $deployment;
        }

        $deployment->forceFill([
            'status' => DeploymentStatus::Cancelled,
            'finished_at' => $deployment->finished_at ?? now(),
            'error_message' => __('ops.jobs.force_start_replaced'),
        ])->save();

        return $existing;
    }

    /**
     * The queue slot is already gone, so the row cannot stay "kuyrukta".
     * Say that force start stopped half-way rather than blaming Coolify.
     */
    private function failForceStart(Deployment $deployment): void
    {
        $deployment->forceFill([
            'status' => DeploymentStatus::Cancelled,
            'finished_at' => now(),
            'error_message' => __('ops.jobs.force_start_aborted'),
        ])->save();
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

    public function forceStartRemote(string $deploymentUuid, ?User $actor = null): Deployment
    {
        $deploymentUuid = trim($deploymentUuid);
        $local = Deployment::query()->with('site')->where('coolify_deployment_uuid', $deploymentUuid)->first();
        if ($local instanceof Deployment) {
            return $this->forceStart($local, $actor);
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

        // Coolify started this one, not Plane, so the row says where it came from
        // instead of inventing a Plane trigger.
        $kind = __('ops.jobs.deployment');
        $detail = __('ops.jobs.source_coolify');

        return [
            'id' => 'coolify-'.$uuid,
            'type' => 'coolify.deployment',
            'kind_label' => $kind,
            'subject' => $site->name,
            'title' => $kind.' · '.$site->name,
            'status' => $widgetStatus,
            'status_label' => $status->label(),
            'detail' => $detail,
            'progress' => in_array($widgetStatus, ['queued', 'running'], true) ? null : 100,
            // Queued is waiting, not progressing: the widget must not animate it.
            'indeterminate' => $widgetStatus === 'running',
            'message' => $detail.' · '.$status->label(),
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
