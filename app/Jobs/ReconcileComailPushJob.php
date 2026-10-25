<?php

namespace App\Jobs;

use App\Enums\OpsLane;
use App\Jobs\Concerns\OnOpsLane;
use App\Models\MailServer;
use App\Models\Site;
use App\Services\Comail\ComailSiteConfigurer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Hourly: re-push the CoMail configure to sites with a CoMail domain whose last
 * push failed or never happened (a CMS on older code, a container that was
 * down). Inline like ReconcileDeskronPushJob, so a site that keeps answering
 * 404 until its next deploy records the reason instead of filling failed_jobs.
 */
class ReconcileComailPushJob implements ShouldQueue
{
    use OnOpsLane;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct()
    {
        $this->onLane(OpsLane::Long);
    }

    public function handle(ComailSiteConfigurer $configurer): void
    {
        if (! MailServer::comail() instanceof MailServer) {
            return;
        }

        Site::query()
            ->whereHas('comailDomains')
            ->where(function ($query): void {
                $query
                    ->whereNull('comail_configured_at')
                    ->orWhereColumn('comail_configure_failed_at', '>', 'comail_configured_at');
            })
            ->orderBy('id')
            ->each(function (Site $site) use ($configurer): void {
                if ($site->hasAgentSecret()) {
                    $configurer->sync($site);
                }
            });
    }
}
