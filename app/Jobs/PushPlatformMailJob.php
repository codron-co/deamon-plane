<?php

namespace App\Jobs;

use App\Models\Site;
use App\Services\Mail\PlatformMailConfigurer;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class PushPlatformMailJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
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

        $result = $configurer->sync($site);
        if ($result->status !== 'failed') {
            return;
        }

        Log::warning('Queued platform mail sync failed', [
            'site_id' => $site->id,
            'site_slug' => $site->slug,
            'http_status' => $result->httpStatus,
        ]);

        throw new RuntimeException('Platform mail sync failed for site '.$site->id.'.');
    }
}
