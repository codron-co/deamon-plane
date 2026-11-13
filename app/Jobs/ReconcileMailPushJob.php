<?php

namespace App\Jobs;

use App\Enums\OpsLane;
use App\Enums\SiteStatus;
use App\Jobs\Concerns\OnOpsLane;
use App\Models\Site;
use App\Services\Mail\PlatformMailConfigurer;
use App\Services\Mail\SiteMailConfigurer;
use App\Services\Sites\SiteSettingsAfterDeploy;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Hourly: push the platform SMTP and the site's own SMTP to active sites that
 * never accepted them, or whose last push failed.
 *
 * Both used to be pushed on demand only (settings saved, mail server assigned,
 * provision start). A CMS that was not up at that moment stayed without mail
 * and nothing tried again: on 2026-10-27 four new sites could not send password
 * resets, invites, weekly reports or bulletins until "push to all sites" was
 * pressed by hand. Sites already configured are left alone; a settings change
 * still reaches everyone through DispatchPlatformMailPushJob.
 *
 * Pushes inline like ReconcileDeskronPushJob: a site that keeps failing records
 * its reason on the row instead of filling failed_jobs every hour.
 */
class ReconcileMailPushJob implements ShouldQueue
{
    use OnOpsLane;
    use Queueable;

    public int $tries = 1;

    /** One pass walks every pending site, each with its own agent timeout. */
    public int $timeout = 900;

    public function __construct()
    {
        $this->onLane(OpsLane::Long);
    }

    public function handle(PlatformMailConfigurer $platformMail, SiteMailConfigurer $siteMail): void
    {
        Site::query()
            ->where('status', SiteStatus::Active->value)
            ->where(function ($query): void {
                $query
                    ->whereNull('platform_mail_pushed_at')
                    ->orWhereColumn('platform_mail_push_failed_at', '>', 'platform_mail_pushed_at')
                    ->orWhere(function ($query): void {
                        $query
                            ->whereNotNull('mail_server_id')
                            ->where(function ($query): void {
                                $query
                                    ->whereNull('mail_configured_at')
                                    ->orWhereColumn('mail_configure_failed_at', '>', 'mail_configured_at');
                            });
                    });
            })
            ->orderBy('id')
            ->each(function (Site $site) use ($platformMail, $siteMail): void {
                try {
                    if (SiteSettingsAfterDeploy::needsPlatformMail($site)) {
                        $platformMail->sync($site);
                    }

                    if (SiteSettingsAfterDeploy::needsSiteMail($site)) {
                        $siteMail->sync($site, retryConnection: true);
                    }
                } catch (Throwable) {
                    // One site's agent error must not stop the pass; the row keeps the reason.
                }
            });
    }
}
