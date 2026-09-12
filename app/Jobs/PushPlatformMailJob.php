<?php

namespace App\Jobs;

use App\Models\Site;
use App\Services\Mail\PlatformMailConfigurer;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class PushPlatformMailJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public int $uniqueFor = 120;

    public function __construct(public readonly string $siteId) {}

    public function uniqueId(): string
    {
        return $this->siteId;
    }

    public function handle(PlatformMailConfigurer $configurer): void
    {
        $site = Site::query()->find($this->siteId);
        if ($site === null || ! $site->hasAgentSecret()) {
            return;
        }
        $configurer->sync($site);
    }
}
