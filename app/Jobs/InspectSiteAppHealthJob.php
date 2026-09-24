<?php

namespace App\Jobs;

use App\Enums\OpsLane;
use App\Jobs\Concerns\OnOpsLane;
use App\Models\Site;
use App\Services\Sites\SiteAppHealthInspector;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class InspectSiteAppHealthJob implements ShouldBeUnique, ShouldQueue
{
    use OnOpsLane;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 45;

    public int $uniqueFor = 240;

    public function __construct(
        public readonly string $siteId,
    ) {
        $this->onLane(OpsLane::Health);
    }

    public function uniqueId(): string
    {
        return $this->siteId;
    }

    public function handle(SiteAppHealthInspector $inspector): void
    {
        $site = Site::query()->find($this->siteId);
        if ($site === null || blank($site->coolify_app_uuid)) {
            return;
        }

        // inspect() writes last_app_health_* and stamps app_has_issues
        // (ADR-10) on the same save. The job must not return after a Coolify
        // read without that column — the Sites `app=` filter is SQL on it.
        $inspector->inspect($site, live: true);
    }
}
