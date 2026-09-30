<?php

namespace App\Jobs;

use App\Enums\OpsLane;
use App\Jobs\Concerns\OnOpsLane;
use App\Models\SiteSearchIntegration;
use App\Services\SearchIntegrations\SiteSearchIntegrationsAgent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Chases the Arama & Analitik desired state the push has not landed yet: saved
 * but never pushed, changed after the last push, or failed after it (a CMS
 * that was down, or older than POST /search-integrations and answered 404).
 *
 * Pushes inline like ReconcileDeskronPushJob: a site on older CMS code would
 * fail every hour, and queueing a retrying job for it only fills failed_jobs.
 */
class ReconcileSiteSearchIntegrationsJob implements ShouldQueue
{
    use OnOpsLane;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct()
    {
        $this->onLane(OpsLane::Long);
    }

    public function handle(SiteSearchIntegrationsAgent $agent): void
    {
        SiteSearchIntegration::query()
            ->whereNotNull('changed_at')
            ->where(function ($query): void {
                $query->whereNull('pushed_at')
                    ->orWhereColumn('changed_at', '>', 'pushed_at')
                    ->orWhereColumn('push_failed_at', '>', 'pushed_at');
            })
            ->with('site')
            ->orderBy('id')
            ->each(function (SiteSearchIntegration $record) use ($agent): void {
                $site = $record->site;
                if ($site !== null && $site->hasAgentSecret()) {
                    $agent->push($site);
                }
            });
    }
}
