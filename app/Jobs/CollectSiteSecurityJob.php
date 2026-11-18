<?php

namespace App\Jobs;

use App\Enums\OpsLane;
use App\Jobs\Concerns\OnOpsLane;
use App\Models\Site;
use App\Services\Security\SecurityCollector;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Collects one site's security summary. Never fails the queue: a site that is
 * down or too old is recorded on its cursor row and tried again next run.
 */
class CollectSiteSecurityJob implements ShouldBeUnique, ShouldQueue
{
    use OnOpsLane;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public int $uniqueFor = 300;

    public function __construct(public readonly string $siteId)
    {
        $this->onLane(OpsLane::Health);
    }

    public function uniqueId(): string
    {
        return $this->siteId;
    }

    public function handle(SecurityCollector $collector): void
    {
        $site = Site::query()->find($this->siteId);
        if ($site === null) {
            return;
        }

        try {
            $collector->collect($site);
        } catch (Throwable $exception) {
            Log::warning('Site security collection crashed', [
                'site_id' => $this->siteId,
                'message' => mb_substr($exception->getMessage(), 0, 300),
            ]);
        }
    }
}
