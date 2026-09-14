<?php

namespace App\Jobs;

use App\Models\Site;
use App\Services\Mail\SiteMailConfigurer;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Pushes the site's Hostinger mail binding to the CMS off the request path. The
 * CMS installs its mail module (migrations) on the first configure, which can
 * outlast the agent timeout; the operator's save must not depend on it. The
 * outcome lands on `sites.mail_configured_at` / `mail_configure_failed_at`.
 */
class ConfigureSiteMailJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public int $uniqueFor = 120;

    public function __construct(public readonly string $siteId) {}

    public function uniqueId(): string
    {
        return $this->siteId;
    }

    public function handle(SiteMailConfigurer $configurer): void
    {
        $site = Site::query()->find($this->siteId);
        if ($site === null || ! $site->hasAgentSecret()) {
            return;
        }

        $result = $configurer->sync($site, retryConnection: true);
        if ($result->status !== 'failed') {
            return;
        }

        Log::warning('Queued site mail configure failed', [
            'site_id' => $site->id,
            'site_slug' => $site->slug,
            'http_status' => $result->httpStatus,
        ]);
    }
}
