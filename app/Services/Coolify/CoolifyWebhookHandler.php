<?php

namespace App\Services\Coolify;

use App\Enums\Channel;
use App\Enums\DeploymentStatus;
use App\Enums\DeploymentTrigger;
use App\Models\Deployment;
use App\Models\Site;
use App\Services\Coolify\Dto\CoolifyDeployment;
use App\Services\Sites\SiteProvisioner;

class CoolifyWebhookHandler
{
    public function __construct(
        private readonly SiteProvisioner $provisioner,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok: true, updated: bool}
     */
    public function handle(array $payload): array
    {
        $remote = $this->toRemoteDeployment($payload);
        if ($remote->uuid === '' && $remote->applicationUuid === null) {
            return ['ok' => true, 'updated' => false];
        }

        $deployment = $this->findOrCreateDeployment($remote);
        if ($deployment === null) {
            return ['ok' => true, 'updated' => false];
        }

        $this->provisioner->applyRemoteDeployment($deployment, $remote);

        return ['ok' => true, 'updated' => true];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function toRemoteDeployment(array $payload): CoolifyDeployment
    {
        $nested = is_array($payload['deployment'] ?? null) ? $payload['deployment'] : [];
        $merged = array_merge($nested, $payload);

        $uuid = (string) ($merged['deployment_uuid'] ?? $merged['uuid'] ?? '');
        $applicationUuid = $merged['application_uuid']
            ?? $merged['application_id']
            ?? $merged['resource_uuid']
            ?? null;

        $status = isset($merged['status']) ? (string) $merged['status'] : $this->statusFromEvent($payload);
        $commit = $merged['commit'] ?? $merged['commit_sha'] ?? $merged['sha'] ?? null;

        return CoolifyDeployment::fromArray([
            'uuid' => $uuid,
            'status' => $status,
            'commit' => $commit,
            'application_uuid' => $applicationUuid,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function statusFromEvent(array $payload): string
    {
        $event = strtolower((string) ($payload['event'] ?? ''));

        return match ($event) {
            'deployment_success' => DeploymentStatus::Finished->value,
            'deployment_failed' => DeploymentStatus::Failed->value,
            default => match (true) {
                ($payload['success'] ?? null) === true => DeploymentStatus::Finished->value,
                ($payload['success'] ?? null) === false && str_contains($event, 'deployment') => DeploymentStatus::Failed->value,
                default => DeploymentStatus::InProgress->value,
            },
        };
    }

    private function findOrCreateDeployment(CoolifyDeployment $remote): ?Deployment
    {
        if ($remote->uuid !== '') {
            $existing = Deployment::query()
                ->where('coolify_deployment_uuid', $remote->uuid)
                ->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        $site = $this->findSite($remote);
        if ($site === null) {
            return null;
        }

        $open = $site->deployments()
            ->whereIn('status', [
                DeploymentStatus::Queued->value,
                DeploymentStatus::InProgress->value,
            ])
            ->latest('id')
            ->first();

        if ($open !== null) {
            if ($remote->uuid !== '' && blank($open->coolify_deployment_uuid)) {
                $open->coolify_deployment_uuid = $remote->uuid;
                $open->save();
            }

            return $open;
        }

        if ($remote->uuid === '') {
            return null;
        }

        $channel = $site->channel instanceof Channel ? $site->channel : Channel::from((string) $site->channel);

        return $site->deployments()->create([
            'channel' => $channel,
            'trigger' => DeploymentTrigger::Manual,
            'coolify_deployment_uuid' => $remote->uuid,
            'status' => DeploymentStatus::InProgress,
            'started_at' => now(),
        ]);
    }

    private function findSite(CoolifyDeployment $remote): ?Site
    {
        if ($remote->applicationUuid === null || $remote->applicationUuid === '') {
            return null;
        }

        return Site::query()
            ->where('coolify_app_uuid', $remote->applicationUuid)
            ->first();
    }
}
