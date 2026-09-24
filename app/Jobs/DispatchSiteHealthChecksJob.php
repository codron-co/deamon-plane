<?php

namespace App\Jobs;

use App\Enums\SiteStatus;
use App\Models\Site;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DispatchSiteHealthChecksJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function handle(): void
    {
        // Stopped, draft and provisioning sites have no running CMS to ask; polling
        // them only produced false "down" alerts.
        Site::query()
            ->whereIn('status', [SiteStatus::Active->value, SiteStatus::Error->value, SiteStatus::Deploying->value])
            ->orderBy('id')
            ->get(['id', 'coolify_app_uuid'])
            ->each(static function (Site $site): void {
                CheckSiteHealthJob::dispatch($site->id);
                if (filled($site->coolify_app_uuid)) {
                    InspectSiteAppHealthJob::dispatch($site->id);
                }
            });
    }
}
