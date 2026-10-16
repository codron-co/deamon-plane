<?php

namespace App\Jobs;

use App\Models\InstagramSetting;
use App\Models\Site;
use App\Services\Instagram\InstagramAgentClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Sites that upgraded to CMS 1.2.76+ after the last push report `instagram.oauth_configured`
 * false in health; push them the config so «Instagram ile bağlan» appears without a manual step.
 */
class ReconcileInstagramPushJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function handle(InstagramAgentClient $agent): void
    {
        if (! InstagramSetting::current()->isReady()) {
            return;
        }

        Site::query()->orderBy('id')->each(function (Site $site) use ($agent): void {
            $reported = data_get($site->last_health_payload, 'instagram.oauth_configured');

            if ($agent->supports($site) && $reported === false) {
                PushInstagramOAuthConfigJob::dispatch((string) $site->id);
            }
        });
    }
}
