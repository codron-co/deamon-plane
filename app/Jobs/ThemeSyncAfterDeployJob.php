<?php

namespace App\Jobs;

use App\Models\Deployment;
use App\Models\Site;
use App\Models\SiteThemeInstallation;
use App\Services\Themes\ThemeRolloutException;
use App\Services\Themes\ThemeRolloutService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ThemeSyncAfterDeployJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public int $uniqueFor = 120;

    public function __construct(
        public readonly string $siteId,
    ) {}

    public function uniqueId(): string
    {
        return 'theme-sync-after-deploy-'.$this->siteId;
    }

    public function handle(ThemeRolloutService $rollout): void
    {
        $site = Site::query()->find($this->siteId);
        if ($site === null) {
            return;
        }

        $installations = SiteThemeInstallation::query()
            ->where('site_id', $site->id)
            ->where('pending_sync_after_deploy', true)
            ->get();

        if ($installations->isEmpty()) {
            return;
        }

        // Another Coolify build may still be open for this site; keep the flag for a later finish.
        if ($this->siteHasOpenDeployment($site->id)) {
            return;
        }

        foreach ($installations as $installation) {
            try {
                $rollout->syncNow($installation, null, null);
            } catch (ThemeRolloutException $exception) {
                Log::warning('Deferred theme sync after deploy failed', [
                    'site_id' => $site->id,
                    'installation_id' => $installation->id,
                    'message' => $exception->getMessage(),
                ]);
            }
        }
    }

    /**
     * Failed or cancelled deploys drop the deferred sync; operators must Sync again explicitly.
     */
    public static function clearPendingForSite(string $siteId): void
    {
        SiteThemeInstallation::query()
            ->where('site_id', $siteId)
            ->where('pending_sync_after_deploy', true)
            ->update(['pending_sync_after_deploy' => false]);
    }

    private function siteHasOpenDeployment(string $siteId): bool
    {
        return Deployment::query()
            ->where('site_id', $siteId)
            ->whereNull('finished_at')
            ->exists();
    }
}
