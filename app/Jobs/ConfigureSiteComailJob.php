<?php

namespace App\Jobs;

use App\Models\Site;
use App\Services\Comail\ComailSiteConfigurer;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Pushes the CoMail configure to the CMS off the request path (same pattern as
 * ConfigureSiteMailJob). Outcome on `sites.comail_configured_at` / `_failed_at`.
 */
class ConfigureSiteComailJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
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

    public function handle(ComailSiteConfigurer $configurer): void
    {
        $site = Site::query()->find($this->siteId);
        if ($site === null || ! $site->hasAgentSecret()) {
            return;
        }

        $result = $configurer->sync($site, retryConnection: true);
        if ($result->status !== 'failed') {
            return;
        }

        Log::warning('Queued site CoMail configure failed', [
            'site_id' => $site->id,
            'site_slug' => $site->slug,
            'http_status' => $result->httpStatus,
        ]);
    }
}
