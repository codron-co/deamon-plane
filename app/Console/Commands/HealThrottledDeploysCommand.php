<?php

namespace App\Console\Commands;

use App\Enums\DeploymentStatus;
use App\Models\Deployment;
use App\Services\Coolify\CoolifyApiException;
use App\Services\Coolify\CoolifyApplicationService;
use App\Services\Coolify\CoolifyDeploymentSync;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Repairs deployment rows that an earlier Plane build marked Failed because the
 * *status read* was throttled, not because the build failed. Re-reads each one
 * from Coolify through the paced client and writes the true status back.
 */
class HealThrottledDeploysCommand extends Command
{
    protected $signature = 'ops:heal-throttled-deploys
        {--dry-run : List affected deployments without writing}
        {--limit=200 : Maximum rows to inspect}';

    protected $description = 'Re-read deployments that were failed by a Coolify rate limit and restore their real status';

    /**
     * Markers left by the pre-fix poll job and by the current localized message.
     *
     * @var list<string>
     */
    private const MARKERS = [
        'Too Many Attempts',
        'istek sınırı',
        'rate limit',
    ];

    public function handle(CoolifyDeploymentSync $sync): int
    {
        $candidates = $this->candidates();

        if ($candidates->isEmpty()) {
            $this->info('No throttle-failed deployments found.');

            return self::SUCCESS;
        }

        $this->info($candidates->count().' deployment(s) look like throttle failures.');

        $healed = 0;
        $unchanged = 0;

        foreach ($candidates as $deployment) {
            $site = $deployment->site;
            $uuid = trim((string) $deployment->coolify_deployment_uuid);

            if ($site === null || $uuid === '') {
                continue;
            }

            $label = $site->name.' #'.$deployment->id;

            if ($this->option('dry-run')) {
                $this->line('  would re-read '.$label);

                continue;
            }

            try {
                $remote = CoolifyApplicationService::forSite($site)->getDeployment($uuid);
            } catch (CoolifyApiException $exception) {
                $this->warn('  '.$label.': '.$exception->getMessage());

                continue;
            }

            // Clear the fabricated failure so writeRemoteState can move the row.
            $deployment->status = DeploymentStatus::InProgress;
            $deployment->error_message = null;
            $deployment->finished_at = null;
            $deployment->save();

            $sync->applyExisting($deployment, $remote);

            $fresh = $deployment->fresh();
            if ($fresh !== null && $fresh->status !== DeploymentStatus::Failed) {
                $healed++;
                $this->line('  '.$label.' → '.$fresh->status->value);
            } else {
                $unchanged++;
                $this->line('  '.$label.' → still failed (real failure)');
            }
        }

        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }

        $this->info(sprintf('Healed %d, left %d as real failures.', $healed, $unchanged));

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, Deployment>
     */
    private function candidates()
    {
        return Deployment::query()
            ->with('site')
            ->where('status', DeploymentStatus::Failed)
            ->whereNotNull('coolify_deployment_uuid')
            ->where(function ($query): void {
                foreach (self::MARKERS as $marker) {
                    $query->orWhere('error_message', 'like', '%'.$marker.'%');
                }
            })
            ->orderByDesc('id')
            ->limit(max(1, (int) $this->option('limit')))
            ->get();
    }
}
