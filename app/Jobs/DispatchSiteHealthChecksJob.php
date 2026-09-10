<?php

namespace App\Jobs;

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
        Site::query()
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
