<?php

namespace App\Services\Sites\Diagnosis;

use App\Enums\DeploymentStatus;
use App\Models\Deployment;
use App\Models\Site;
use App\Services\Coolify\CoolifyApplicationService;
use App\Services\Ops\AutomationGuard;
use App\Services\Sites\DeploymentFailureText;
use App\Services\Sites\SiteAppHealthFixer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Turns a failed deployment row into a diagnosis: classify the Coolify output, pull
 * the container log tail when the symptom lives there, apply the one safe fix the
 * code allows (at most once per failure streak) and store the result on the row.
 *
 * Runs from DiagnoseDeploymentJob after every failure and from the "Refresh
 * diagnosis" button. Never deletes volumes, rotates secrets or moves commits.
 */
final class DeploymentDiagnoser
{
    public function __construct(
        private readonly DeploymentFailureClassifier $classifier,
    ) {}

    public function diagnose(Deployment $deployment, bool $allowAutoFix = true, bool $refetchLogs = false): DeploymentDiagnosis
    {
        $deployment->loadMissing('site');
        $site = $deployment->site;
        $existing = $deployment->diagnosis();

        $priorLogs = $refetchLogs ? null : $existing?->containerLogs;
        $diagnosis = $this->classifier->classify($deployment->error_message, $deployment->log_excerpt, $priorLogs);

        if ($priorLogs !== null) {
            $diagnosis = $diagnosis->withContainerLogs($priorLogs, null);
        } elseif ($diagnosis->needsContainerLogs && $site instanceof Site) {
            [$logs, $error] = $this->fetchContainerLogs($site);
            if ($logs !== null) {
                $diagnosis = $this->classifier
                    ->classify($deployment->error_message, $deployment->log_excerpt, $logs)
                    ->withContainerLogs($logs, null);
            } else {
                $diagnosis = $diagnosis->withContainerLogs(null, $error);
            }
        }

        if ($existing?->autoFix !== null) {
            // A refresh re-reads logs; it never re-runs the fix.
            $diagnosis = $diagnosis->withAutoFix(
                $existing->autoFix['fix'],
                $existing->autoFix['status'],
                $existing->autoFix['reason'],
            );
        } elseif ($diagnosis->autoFixKey !== null) {
            $diagnosis = $this->applyAutoFix($deployment, $site, $diagnosis, $allowAutoFix);
        }

        $diagnosis = $diagnosis->stamped();

        $deployment->diagnosis = $diagnosis->toArray();
        $deployment->saveQuietly();

        return $diagnosis;
    }

    /**
     * @return array{0: string|null, 1: string|null} [redacted log tail, error key]
     */
    private function fetchContainerLogs(Site $site): array
    {
        $uuid = trim((string) $site->coolify_app_uuid);
        if ($uuid === '') {
            return [null, 'no_app'];
        }

        $site->loadMissing('coolifyConnection');
        if ($site->coolifyConnection !== null && ! $site->coolifyConnection->hasToken()) {
            return [null, 'no_token'];
        }

        try {
            $raw = CoolifyApplicationService::forSite($site)->getApplicationLogs(
                $uuid,
                max(20, (int) config('ops.diagnosis.log_lines', 200)),
            );
        } catch (Throwable $exception) {
            Log::info('Deployment diagnosis: container logs unavailable', [
                'site_id' => $site->id,
                'error' => $exception->getMessage(),
            ]);

            return [null, 'coolify_error'];
        }

        $raw = trim($raw);
        if ($raw === '') {
            return [null, 'empty'];
        }

        $max = max(1024, (int) config('ops.diagnosis.container_logs_bytes', 8000));
        if (strlen($raw) > $max) {
            $raw = '[…]'.substr($raw, -$max);
        }

        return [DeploymentFailureText::redact($site, $raw), null];
    }

    private function applyAutoFix(Deployment $deployment, ?Site $site, DeploymentDiagnosis $diagnosis, bool $allow): DeploymentDiagnosis
    {
        $fix = (string) $diagnosis->autoFixKey;

        $guard = app(AutomationGuard::class);

        if (! $allow || ! $guard->enabled(AutomationGuard::DEPLOY_AUTO_FIX)) {
            return $diagnosis->withAutoFix($fix, DeploymentDiagnosis::AUTO_SKIPPED, 'disabled');
        }

        if (! in_array($fix, DeploymentFailureClassifier::AUTO_SAFE, true)) {
            return $diagnosis->withAutoFix($fix, DeploymentDiagnosis::AUTO_SKIPPED, 'not_safe');
        }

        if (! $site instanceof Site || blank($site->coolify_app_uuid)) {
            return $diagnosis->withAutoFix($fix, DeploymentDiagnosis::AUTO_SKIPPED, 'no_app');
        }

        if ($this->hasNewerDeployment($deployment)) {
            return $diagnosis->withAutoFix($fix, DeploymentDiagnosis::AUTO_SKIPPED, 'newer_deployment');
        }

        if ($this->recentlyAutoFixed($deployment, $fix)) {
            return $diagnosis->withAutoFix($fix, DeploymentDiagnosis::AUTO_SKIPPED, 'repeated');
        }

        // A redeploy of a site that follows the branch head builds whatever the
        // head is now, which may be a newer commit than the one that failed. Only
        // a pinned site is rebuilt at the same commit without a human.
        if ($fix === 'redeploy' && ! $site->hasPinnedCommit()) {
            return $diagnosis->withAutoFix($fix, DeploymentDiagnosis::AUTO_SKIPPED, 'follows_head');
        }

        $denied = $guard->deny(AutomationGuard::DEPLOY_AUTO_FIX, $site, (string) $diagnosis->code);
        if ($denied !== null) {
            return $diagnosis->withAutoFix($fix, DeploymentDiagnosis::AUTO_SKIPPED, $denied);
        }

        try {
            app(SiteAppHealthFixer::class)->fix($site, $fix);
        } catch (Throwable $exception) {
            Log::warning('Deployment diagnosis: automatic fix failed', [
                'site_id' => $site->id,
                'deployment_id' => $deployment->id,
                'fix' => $fix,
                'error' => $exception->getMessage(),
            ]);

            return $diagnosis->withAutoFix($fix, DeploymentDiagnosis::AUTO_FAILED, 'error');
        }

        $site->auditLogs()->create([
            'actor_user_id' => null,
            'action' => 'site.deploy_auto_fix',
            'after' => ['deployment_id' => $deployment->id, 'code' => $diagnosis->code, 'fix' => $fix],
            'ip' => null,
        ]);

        return $diagnosis->withAutoFix($fix, DeploymentDiagnosis::AUTO_APPLIED);
    }

    private function hasNewerDeployment(Deployment $deployment): bool
    {
        return Deployment::query()
            ->where('site_id', $deployment->site_id)
            ->where('id', '>', $deployment->id)
            ->exists();
    }

    /**
     * The same automatic fix already ran for an earlier failure of this site inside
     * the repeat window and the site failed again: running it a third time is a loop.
     */
    private function recentlyAutoFixed(Deployment $deployment, string $fix): bool
    {
        $hours = max(1, (int) config('ops.diagnosis.repeat_window_hours', 24));
        $since = CarbonImmutable::now()->subHours($hours);

        $previous = Deployment::query()
            ->where('site_id', $deployment->site_id)
            ->where('id', '<', $deployment->id)
            ->where('status', DeploymentStatus::Failed)
            ->whereNotNull('diagnosis')
            ->where(static function ($query) use ($since): void {
                $query->where('finished_at', '>=', $since)
                    ->orWhere('created_at', '>=', $since);
            })
            ->orderByDesc('id')
            ->limit(5)
            ->get();

        foreach ($previous as $row) {
            $auto = $row->diagnosis()?->autoFix;
            if ($auto !== null && $auto['fix'] === $fix && $auto['status'] === DeploymentDiagnosis::AUTO_APPLIED) {
                return true;
            }
        }

        return false;
    }
}
