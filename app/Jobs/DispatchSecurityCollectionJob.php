<?php

namespace App\Jobs;

use App\Enums\OpsLane;
use App\Enums\SiteStatus;
use App\Jobs\Concerns\OnOpsLane;
use App\Models\Site;
use App\Models\SiteSecurityCursor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Plans one fleet security sweep. The shared host's CPU is capped, so nothing
 * here fires at once: each site's job is delayed `spacing_seconds` after the
 * previous one, the test site goes first, the least recently polled sites next,
 * and a site that keeps failing or does not know the endpoint is asked rarely.
 */
class DispatchSecurityCollectionJob implements ShouldQueue
{
    use OnOpsLane;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct()
    {
        $this->onLane(OpsLane::Health);
    }

    public function handle(): void
    {
        $sites = $this->due();
        $spacing = max(1, (int) config('ops.security.spacing_seconds', 4));

        foreach ($sites as $index => $site) {
            CollectSiteSecurityJob::dispatch($site->id)->delay(now()->addSeconds($index * $spacing));
        }
    }

    /**
     * @return list<Site>
     */
    public function due(): array
    {
        $cursors = SiteSecurityCursor::query()->get()->keyBy('site_id');
        $limit = max(1, (int) config('ops.security.max_sites_per_run', 60));
        $retryHours = max(1, (int) config('ops.security.unsupported_retry_hours', 24));
        $backoffAfter = max(1, (int) config('ops.security.backoff_after_failures', 3));

        $due = Site::query()
            ->where('status', SiteStatus::Active->value)
            ->whereNotNull('agent_secret_encrypted')
            ->orderBy('id')
            ->get()
            ->filter(function (Site $site) use ($cursors, $retryHours, $backoffAfter): bool {
                $cursor = $cursors->get($site->id);
                if ($cursor === null || $cursor->last_polled_at === null) {
                    return true;
                }

                if ($cursor->supported === false) {
                    return $cursor->last_polled_at->lte(now()->subHours($retryHours));
                }

                // The health poll already told us the module is off (CMS 1.2.x with the block).
                $reported = $site->last_health_payload['security']['module_enabled'] ?? null;
                if ($reported === false && $cursor->last_polled_at->gt(now()->subHour())) {
                    return false;
                }

                if ($cursor->consecutive_failures >= $backoffAfter) {
                    return $cursor->last_polled_at->lte(now()->subHour());
                }

                return true;
            })
            ->sortBy(function (Site $site) use ($cursors): string {
                $cursor = $cursors->get($site->id);
                $polled = $cursor?->last_polled_at?->timestamp ?? 0;

                // The test site always leads; the rest by who waited longest.
                return ($site->slug === 'test' ? '0' : '1').str_pad((string) $polled, 12, '0', STR_PAD_LEFT);
            })
            ->take($limit)
            ->values()
            ->all();

        return $due;
    }
}
