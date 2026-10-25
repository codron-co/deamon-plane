<?php

namespace App\Jobs;

use App\Models\Site;
use App\Services\Comail\ComailDomainProvisioner;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Re-applies a site's package limits to all its mailcow domains (package change
 * or package edit). The limits there are only a ceiling; Plane enforces them.
 */
class ApplyComailPackageJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public int $uniqueFor = 120;

    public function __construct(public readonly string $siteId) {}

    public function uniqueId(): string
    {
        return $this->siteId;
    }

    public function handle(ComailDomainProvisioner $provisioner): void
    {
        $site = Site::query()->find($this->siteId);
        if ($site instanceof Site) {
            $provisioner->applyLimits($site);
        }
    }
}
