<?php

namespace App\Services\Coolify;

use App\Enums\Channel;
use App\Enums\DeploymentStatus;
use App\Enums\DeploymentTrigger;
use App\Models\Deployment;
use App\Models\Site;
use App\Services\Coolify\Dto\CoolifyDeployment;
use App\Services\Sites\DeploymentFailureText;

class CoolifyDeploymentSync
{
    public function sync(Site $site, CoolifyApplicationService $coolify, int $take = 25): int
    {
        $uuid = trim((string) $site->coolify_app_uuid);
        if ($uuid === '') {
            return 0;
        }

        $written = 0;
        foreach ($coolify->listAppDeployments($uuid, 0, $take) as $remote) {
            if ($this->upsert($site, $remote)) {
                $written++;
            }
        }

        return $written;
    }

    public function upsert(Site $site, CoolifyDeployment $remote): bool
    {
        if ($remote->uuid === '') {
            return false;
        }

        $deployment = Deployment::query()
            ->where('coolify_deployment_uuid', $remote->uuid)
            ->first();

        if ($deployment instanceof Deployment && $deployment->site_id !== $site->id) {
            return false;
        }

        $mapped = $this->mapRemoteStatus($remote->status);
        $effective = $mapped === DeploymentStatus::Queued
            ? DeploymentStatus::InProgress
            : $mapped;

        if ($deployment instanceof Deployment && $this->shouldKeepTerminal($deployment, $effective)) {
            return false;
        }

        if (! $deployment instanceof Deployment) {
            $channel = $site->channel instanceof Channel ? $site->channel : Channel::from((string) $site->channel);
            $deployment = $site->deployments()->make([
                'channel' => $channel,
                'trigger' => DeploymentTrigger::Manual,
                'coolify_deployment_uuid' => $remote->uuid,
                'status' => $effective,
                'started_at' => $remote->startedAt() ?? now(),
            ]);
        }

        $deployment->status = $effective;
        if (filled($remote->commit)) {
            $deployment->commit_sha = $remote->commit;
        }

        $started = $remote->startedAt();
        if ($started !== null && $deployment->started_at === null) {
            $deployment->started_at = $started;
        }

        if (in_array($effective, [DeploymentStatus::Finished, DeploymentStatus::Failed, DeploymentStatus::Cancelled], true)) {
            $deployment->finished_at = $remote->finishedAt() ?? $deployment->finished_at ?? now();
        }

        if (in_array($effective, [DeploymentStatus::Failed, DeploymentStatus::Cancelled], true)) {
            $detail = DeploymentFailureText::fromRemote($site, $remote, 'Coolify deployment '.$effective->value.'.');
            if (filled($remote->message) || filled($remote->logsExcerpt)) {
                $deployment->error_message = $detail['error_message'];
                if (filled($detail['log_excerpt'])) {
                    $deployment->log_excerpt = $detail['log_excerpt'];
                }
            }
        }

        if (! $deployment->isDirty()) {
            return false;
        }

        $deployment->save();

        return true;
    }

    private function mapRemoteStatus(?string $status): DeploymentStatus
    {
        $normalized = strtolower(str_replace(['-', ' '], '_', trim((string) $status)));

        return match ($normalized) {
            'finished', 'success', 'successful', 'done' => DeploymentStatus::Finished,
            'failed', 'error', 'exited' => DeploymentStatus::Failed,
            'cancelled', 'canceled', 'cancelled_by_user', 'canceled_by_user' => DeploymentStatus::Cancelled,
            'queued', 'pending' => DeploymentStatus::Queued,
            default => DeploymentStatus::InProgress,
        };
    }

    private function shouldKeepTerminal(Deployment $deployment, DeploymentStatus $incoming): bool
    {
        return $deployment->finished_at !== null
            && in_array($deployment->status, [
                DeploymentStatus::Finished,
                DeploymentStatus::Failed,
                DeploymentStatus::Cancelled,
            ], true)
            && in_array($incoming, [
                DeploymentStatus::Queued,
                DeploymentStatus::InProgress,
            ], true);
    }
}
