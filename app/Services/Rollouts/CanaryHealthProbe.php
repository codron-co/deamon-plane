<?php

namespace App\Services\Rollouts;

use App\Enums\SiteStatus;
use App\Models\Site;
use App\Services\Agent\SiteHealthChecker;
use App\Services\Sites\SiteLiveProbe;

/**
 * "Is this canary healthy after its build?" through the paths Plane already has:
 * a fresh signed agent health poll (the same one the Sites list and the fleet
 * unhealthy card read), or — for a site without an agent secret — the public
 * homepage probe Live Sync uses.
 */
class CanaryHealthProbe
{
    public function __construct(
        private readonly SiteHealthChecker $checker,
        private readonly SiteLiveProbe $live,
    ) {}

    public function passes(Site $site): bool
    {
        if ($site->hasAgentSecret()) {
            $result = $this->checker->check($site);
            $fresh = $site->fresh();

            return $result->ok && $fresh !== null && $fresh->status !== SiteStatus::Error;
        }

        $this->live->probeMany([$site]);
        $status = (int) ($site->fresh()->last_live_http_status ?? 0);

        return $status >= 200 && $status < 400;
    }
}
