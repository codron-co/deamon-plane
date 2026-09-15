<?php

namespace App\Jobs;

use App\Models\Site;
use App\Services\Deskron\DeskronConfigurer;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;

class PushDeskronJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    /** A freshly provisioned CMS may still be booting; retry for a few minutes. */
    public int $tries = 4;

    public int $timeout = 30;

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

    public function handle(DeskronConfigurer $configurer): void
    {
        $site = Site::query()->find($this->siteId);
        if ($site === null || ! $site->hasAgentSecret()) {
            return;
        }

        if (! $configurer->sync($site)) {
            throw new RuntimeException('DeskRon push failed for site '.$site->id.'.');
        }
    }
}
