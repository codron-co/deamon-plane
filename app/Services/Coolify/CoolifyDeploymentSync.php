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

        $effective = $this->mapRemoteStatus($remote->status);

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

        return $this->writeRemoteState($deployment, $site, $remote, $effective);
    }

    /**
     * Update an existing deployment from Coolify without changing site status.
     * Used for Manual / ThemeRollout polls so cancel/fail does not flip Active sites to Error.
     */
    public function applyExisting(Deployment $deployment, CoolifyDeployment $remote): bool
    {
        $site = $deployment->site;
        if ($site === null) {
            return true;
        }

        $effective = $this->mapRemoteStatus($remote->status);

        if ($this->shouldKeepTerminal($deployment, $effective)) {
            return true;
        }

        $this->writeRemoteState($deployment, $site, $remote, $effective);

        return in_array($effective, [
            DeploymentStatus::Finished,
            DeploymentStatus::Failed,
            DeploymentStatus::Cancelled,
        ], true);
    }

    public function failWithoutSiteChange(Deployment $deployment, string $message, ?string $logExcerpt = null): void
    {
        $deployment->status = DeploymentStatus::Failed;
        $deployment->error_message = $message;
        if ($logExcerpt !== null) {
            $deployment->log_excerpt = $logExcerpt;
        }
        $deployment->finished_at = $deployment->finished_at ?? now();
        $deployment->save();
    }

    public function mapRemoteStatus(?string $status): DeploymentStatus
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

    private function writeRemoteState(
        Deployment $deployment,
        Site $site,
        CoolifyDeployment $remote,
        DeploymentStatus $effective,
    ): bool {
        $deployment->status = $effective;
        if (filled($remote->commit)) {
            $deployment->commit_sha = $remote->commit;
        }

        $started = $remote->startedAt();
        if ($started !== null) {
            // Prefer Coolify's clock for both ends so duration is not Plane-now vs UTC skew.
            $deployment->started_at = $started;
        }

        if (in_array($effective, [DeploymentStatus::Finished, DeploymentStatus::Failed, DeploymentStatus::Cancelled], true)) {
            $finished = $remote->finishedAt() ?? $deployment->finished_at ?? now();
            if ($deployment->started_at !== null && $finished->lt($deployment->started_at)) {
                $finished = $deployment->started_at;
            }
            $deployment->finished_at = $finished;
        }

        if (in_array($effective, [DeploymentStatus::Failed, DeploymentStatus::Cancelled], true)) {
            $detail = DeploymentFailureText::fromRemote($site, $remote, 'Coolify deployment '.$effective->value.'.');
            if (filled($remote->message) || filled($remote->logsExcerpt)) {
                $deployment->error_message = $detail['error_message'];
                if (filled($detail['log_excerpt'])) {
                    $deployment->log_excerpt = $detail['log_excerpt'];
                }
            } elseif (blank($deployment->error_message)) {
                $deployment->error_message = $detail['error_message'];
            }
        }

        if (! $deployment->isDirty()) {
            return false;
        }

        $becameFailed = $effective === DeploymentStatus::Failed
            && $deployment->isDirty('status');

        $deployment->save();

        if ($becameFailed) {
            try {
                app(\App\Services\Mail\PlatformOpsMailer::class)->send(
                    $site,
                    \App\Services\Mail\PlatformNotificationCatalog::DEPLOY_FAILED,
                    'Deploy başarısız',
                    sprintf(
                        "%s deploy failed.\n%s\nPlane: %s",
                        $site->name,
                        (string) ($deployment->error_message ?: 'Coolify deployment failed.'),
                        route('ops.sites.show', $site),
                    ),
                );
            } catch (\Throwable) {
                // Ops mail must not break deploy sync.
            }
        }

        return true;
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
