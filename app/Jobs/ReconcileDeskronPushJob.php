<?php

namespace App\Jobs;

use App\Models\DeskronSetting;
use App\Models\Site;
use App\Services\Deskron\DeskronConfigurer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Chases the sites the DeskRon push has not reached yet.
 *
 * The key is set once here and pushed to every CMS, but a push only happens on
 * demand — when the setting is saved, or when a site is provisioned. A CMS that
 * was not yet running the code with POST /deskron/configure answered 405 and
 * stayed unconfigured, and nothing ever tried again. Those installs kept
 * working only because their containers still carried the old DESKRON_* env,
 * so the breakage surfaced on their next deploy: support came back with
 * "DeskRon yapılandırılmamış", long after the push had failed.
 *
 * Retrying on a schedule removes that ordering trap. Deploy a CMS whenever you
 * like; the next pass configures it. Sites already configured are left alone —
 * a key change still pushes to everyone through DispatchDeskronPushJob.
 *
 * This sweep pushes inline rather than dispatching PushDeskronJob, which
 * retries four times and then throws. A site that is simply on older code
 * answers 405 every hour, and queueing that would bury failed_jobs under
 * hundreds of entries a day for a condition only a deploy resolves. Here a
 * failure just records its reason on the site and the sweep moves on.
 */
class ReconcileDeskronPushJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** One pass walks every unconfigured site, each with its own agent timeout. */
    public int $timeout = 900;

    public function handle(DeskronConfigurer $configurer): void
    {
        if (! DeskronSetting::current()->isReady()) {
            return;
        }

        Site::query()
            ->where(function ($query): void {
                $query
                    ->whereNull('deskron_pushed_at')
                    ->orWhereColumn('deskron_push_failed_at', '>', 'deskron_pushed_at');
            })
            ->orderBy('id')
            ->each(function (Site $site) use ($configurer): void {
                if ($site->hasAgentSecret()) {
                    $configurer->sync($site);
                }
            });
    }
}
