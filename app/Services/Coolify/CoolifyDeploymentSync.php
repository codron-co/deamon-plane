<?php

namespace App\Services\Coolify;

use App\Enums\Channel;
use App\Enums\DeploymentStatus;
use App\Enums\DeploymentTrigger;
use App\Jobs\ThemeSyncAfterDeployJob;
use App\Models\Deployment;
use App\Models\Site;
use App\Models\SiteThemeInstallation;
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

        ThemeSyncAfterDeployJob::clearPendingForSite((string) $deployment->site_id);
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

        if ($effective === DeploymentStatus::Finished) {
            // Poll timeout / earlier failure text must not stick on a successful Coolify row.
            $deployment->error_message = null;
        } elseif (in_array($effective, [DeploymentStatus::Failed, DeploymentStatus::Cancelled], true)) {
            // The fallback is stored on the row and shown in the ops widget, so it
            // follows the panel locale instead of shipping raw English.
            $fallback = $effective === DeploymentStatus::Cancelled
                ? (string) __('ops.deploy_failure.cancelled')
                : (string) __('ops.deploy_failure.failed');
            $detail = DeploymentFailureText::fromRemote($site, $remote, $fallback);
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
        $becameCancelled = $effective === DeploymentStatus::Cancelled
            && $deployment->isDirty('status');
        $becameFinished = $effective === DeploymentStatus::Finished
            && $deployment->isDirty('status');

        $deployment->save();

        if ($becameFailed || $becameCancelled) {
            ThemeSyncAfterDeployJob::clearPendingForSite((string) $site->id);
        }

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

        if ($becameFinished) {
            $this->dispatchDeferredThemeSync($site);
        }

        if ($becameFinished || $effective === DeploymentStatus::Finished) {
            $this->recoverSiteIfLatestFinished($site);
        }

        try {
            app(\App\Services\Sites\SiteAppHealthInspector::class)->refreshLocalCached($site->fresh() ?? $site);
        } catch (\Throwable) {
            // App health cache refresh must not break deploy sync.
        }

        return true;
    }

    /**
     * Poll timeout can leave sites.status=error even after Coolify finishes.
     * Recover only when the newest deployment (by started_at/id) is finished.
     * Public so CoolifySiteSync can run recovery when deployment rows were already Finished (no dirty write).
     */
    public function recoverSiteIfLatestFinished(Site $site): void
    {
        $site->refresh();
        if ($site->status !== \App\Enums\SiteStatus::Error) {
            return;
        }

        $latest = app(\App\Services\Sites\SiteAppHealthInspector::class)->latestDeployment($site);
        if ($latest === null || $latest->status !== DeploymentStatus::Finished) {
            return;
        }

        if ($site->canTransitionTo(\App\Enums\SiteStatus::Active)) {
            $site->transitionTo(\App\Enums\SiteStatus::Active);
            $site->save();
        }
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

    private function dispatchDeferredThemeSync(Site $site): void
    {
        $pending = SiteThemeInstallation::query()
            ->where('site_id', $site->id)
            ->where('pending_sync_after_deploy', true)
            ->exists();

        if (! $pending) {
            return;
        }

        ThemeSyncAfterDeployJob::dispatch($site->id);
    }
}
