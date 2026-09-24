<?php

namespace App\Console\Commands;

use App\Enums\DeploymentStatus;
use App\Enums\SiteStatus;
use App\Enums\ThemeInstallationStatus;
use App\Jobs\PollDeploymentJob;
use App\Models\AuditLog;
use App\Models\Deployment;
use App\Models\OpsBackgroundJob;
use App\Models\Site;
use App\Models\SiteThemeInstallation;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Finds work that stopped half-way (a killed worker, a lost webhook, a timeout)
 * and puts it in a state the operator and the deploy gate can move on from
 * (audit P09). It only reads Coolify and fixes Plane's own rows; it never
 * deploys, restarts or deletes anything remote.
 */
class WatchdogCommand extends Command
{
    /** Repairs per run, so a bad night does not turn into a storm. */
    public const MAX_REPAIRS = 25;

    /** A deployment row with no news for this long is asked about again. */
    public const DEPLOYMENT_REREAD_MINUTES = 15;

    /** An open deployment this old is closed as failed; it no longer holds the host. */
    public const DEPLOYMENT_GIVE_UP_HOURS = 6;

    public const BACKGROUND_JOB_STALL_MINUTES = 20;

    public const BACKGROUND_JOB_QUEUED_HOURS = 2;

    public const SITE_STALL_HOURS = 2;

    public const THEME_STALL_MINUTES = 30;

    protected $signature = 'ops:watchdog';

    protected $description = 'Repair jobs, deployments, sites and theme installs left mid-way';

    private int $repairs = 0;

    public function handle(): int
    {
        $this->closeFailedDeploymentsWithoutFinish();
        $this->giveUpOnAncientDeployments();
        $this->rereadQuietDeployments();
        $this->failStalledBackgroundJobs();
        $this->recoverStuckSites();
        $this->failStalledThemeInstalls();

        if ($this->repairs > 0) {
            Log::info('ops.watchdog', ['repairs' => $this->repairs]);
        }
        $this->info('Watchdog repairs: '.$this->repairs);

        return self::SUCCESS;
    }

    /** A failed/cancelled row without finished_at used to lock the deploy gate forever. */
    private function closeFailedDeploymentsWithoutFinish(): void
    {
        $rows = Deployment::query()
            ->whereNull('finished_at')
            ->whereIn('status', [DeploymentStatus::Failed->value, DeploymentStatus::Cancelled->value])
            ->limit($this->budget())
            ->get();

        foreach ($rows as $row) {
            $row->forceFill(['finished_at' => $row->updated_at ?? now()])->saveQuietly();
            $this->repaired('watchdog.deployment_closed', $row->site ?? $row, ['deployment_id' => $row->id, 'status' => $row->status->value]);
        }
    }

    private function giveUpOnAncientDeployments(): void
    {
        $rows = Deployment::query()
            ->whereNull('finished_at')
            ->whereIn('status', [DeploymentStatus::Queued->value, DeploymentStatus::InProgress->value])
            ->where('created_at', '<', now()->subHours(self::DEPLOYMENT_GIVE_UP_HOURS))
            ->limit($this->budget())
            ->get();

        foreach ($rows as $row) {
            $row->forceFill([
                'status' => DeploymentStatus::Failed,
                'finished_at' => now(),
                'error_message' => __('ops.watchdog.deployment_gave_up', ['hours' => self::DEPLOYMENT_GIVE_UP_HOURS]),
            ])->save();
            $this->repaired('watchdog.deployment_gave_up', $row->site ?? $row, ['deployment_id' => $row->id]);
        }
    }

    /** Lost webhook or a poll that died: read the row from Coolify again (read only). */
    private function rereadQuietDeployments(): void
    {
        $rows = Deployment::query()
            ->whereNull('finished_at')
            ->whereIn('status', [DeploymentStatus::Queued->value, DeploymentStatus::InProgress->value])
            ->whereNotNull('coolify_deployment_uuid')
            ->where('updated_at', '<', now()->subMinutes(self::DEPLOYMENT_REREAD_MINUTES))
            ->orderBy('updated_at')
            ->limit($this->budget())
            ->get();

        foreach ($rows as $row) {
            // One re-read per row per half hour; the poll job takes it from there.
            if (! Cache::add('watchdog:reread:'.$row->id, true, now()->addMinutes(30))) {
                continue;
            }

            PollDeploymentJob::dispatch($row->id);
            $this->repairs++;
        }
    }

    private function failStalledBackgroundJobs(): void
    {
        $rows = OpsBackgroundJob::query()
            ->where(function ($query): void {
                $query->where(function ($running): void {
                    $running->where('status', 'running')
                        ->where('updated_at', '<', now()->subMinutes(self::BACKGROUND_JOB_STALL_MINUTES));
                })->orWhere(function ($queued): void {
                    $queued->where('status', 'queued')
                        ->where('created_at', '<', now()->subHours(self::BACKGROUND_JOB_QUEUED_HOURS));
                });
            })
            ->limit($this->budget())
            ->get();

        foreach ($rows as $row) {
            $row->markFailed(__('ops.jobs.stopped'));
            $this->repaired('watchdog.background_job_failed', $row, ['ops_job_id' => $row->id, 'type' => $row->type]);
        }
    }

    /** Provisioning / deploying with nothing open behind it: nothing will ever finish it. */
    private function recoverStuckSites(): void
    {
        $sites = Site::query()
            ->whereIn('status', [SiteStatus::Provisioning->value, SiteStatus::Deploying->value])
            ->where('updated_at', '<', now()->subHours(self::SITE_STALL_HOURS))
            ->whereDoesntHave('deployments', function ($query): void {
                $query->whereNull('finished_at')
                    ->whereIn('status', [DeploymentStatus::Queued->value, DeploymentStatus::InProgress->value]);
            })
            ->limit($this->budget())
            ->get();

        foreach ($sites as $site) {
            if (! $site->canTransitionTo(SiteStatus::Error)) {
                continue;
            }

            $from = $site->status->value;
            $site->transitionTo(SiteStatus::Error);
            $site->save();
            $this->repaired('watchdog.site_stuck', $site, ['from' => $from]);
        }
    }

    private function failStalledThemeInstalls(): void
    {
        $rows = SiteThemeInstallation::query()
            ->whereIn('status', [ThemeInstallationStatus::Installing->value, ThemeInstallationStatus::Updating->value])
            ->where('updated_at', '<', now()->subMinutes(self::THEME_STALL_MINUTES))
            ->limit($this->budget())
            ->get();

        foreach ($rows as $row) {
            $row->status = ThemeInstallationStatus::Error;
            $row->last_error = __('sites.errors.job_stopped');
            $row->save();
            $this->repaired('watchdog.theme_install_stalled', $row->site ?? $row, ['installation_id' => $row->id]);
        }
    }

    private function budget(): int
    {
        return max(0, self::MAX_REPAIRS - $this->repairs);
    }

    /**
     * @param  array<string, mixed>  $after
     */
    private function repaired(string $action, Model $subject, array $after): void
    {
        $this->repairs++;

        AuditLog::query()->create([
            'actor_user_id' => null,
            'action' => $action,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => (string) $subject->getKey(),
            'after' => $after + ['source' => 'watchdog'],
            'ip' => null,
        ]);
    }
}
