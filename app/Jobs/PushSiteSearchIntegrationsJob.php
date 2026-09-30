<?php

namespace App\Jobs;

use App\Models\Site;
use App\Services\SearchIntegrations\SiteSearchIntegrationsAgent;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;

/**
 * Pushes one site's Arama & Analitik desired state after an operator save.
 * A CMS mid-deploy answers 502 or times out for a few minutes, so retry;
 * whatever is still failing after that is picked up by the hourly reconcile.
 */
class PushSiteSearchIntegrationsJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public int $timeout = 60;

    public int $uniqueFor = 120;

    public function __construct(public readonly string $siteId) {}

    public function uniqueId(): string
    {
        return $this->siteId;
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 180, 600];
    }

    public function handle(SiteSearchIntegrationsAgent $agent): void
    {
        $site = Site::query()->find($this->siteId);
        if ($site === null || ! $site->hasAgentSecret() || ! $site->searchIntegration()->exists()) {
            return;
        }

        if (! $agent->push($site, retryConnection: true)) {
            $error = (string) $site->searchIntegration()->value('push_error');

            // A CMS 422 is a payload the site will reject every time; retrying only buries failed_jobs.
            if (str_starts_with($error, 'validation_failed') || str_starts_with($error, 'unsupported_field')) {
                return;
            }

            throw new RuntimeException('Search integrations push failed for site '.$site->id.'.');
        }
    }
}
