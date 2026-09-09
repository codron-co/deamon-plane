<?php

namespace App\Jobs;

use App\Models\Site;
use App\Services\Agent\SiteHealthChecker;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class CheckSiteHealthJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public int $uniqueFor = 240;

    public function __construct(
        public readonly string $siteId,
    ) {}

    public function uniqueId(): string
    {
        return $this->siteId;
    }

    public function handle(SiteHealthChecker $checker): void
    {
        $site = Site::query()->find($this->siteId);
        if ($site === null) {
            return;
        }

        $checker->check($site);
    }
}
