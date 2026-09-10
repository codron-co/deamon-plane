<?php

namespace App\Jobs;

use App\Models\Site;
use App\Services\Sites\SiteAppHealthInspector;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class InspectSiteAppHealthJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 45;

    public int $uniqueFor = 240;

    public function __construct(
        public readonly string $siteId,
    ) {}

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

        $inspector->inspect($site, live: true);
    }
}
