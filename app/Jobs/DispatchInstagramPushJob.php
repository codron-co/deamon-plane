<?php

namespace App\Jobs;

use App\Models\InstagramSetting;
use App\Models\Site;
use App\Services\Instagram\InstagramAgentClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Pushes the Instagram OAuth config to every site running CMS 1.2.76+. Older sites are
 * skipped and picked up by the hourly reconcile once they report the new version.
 */
class DispatchInstagramPushJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function handle(InstagramAgentClient $agent): void
    {
        Site::query()->orderBy('id')->each(function (Site $site) use ($agent): void {
            if ($agent->supports($site)) {
                PushInstagramOAuthConfigJob::dispatch((string) $site->id);
            }
        });

        $settings = InstagramSetting::current();
        if ($settings->exists) {
            $settings->last_pushed_at = now();
            $settings->save();
        }
    }
}
