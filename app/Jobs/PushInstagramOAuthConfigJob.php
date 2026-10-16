<?php

namespace App\Jobs;

use App\Models\Site;
use App\Services\Instagram\InstagramAgentClient;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;

class PushInstagramOAuthConfigJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

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

    public function handle(InstagramAgentClient $agent): void
    {
        $site = Site::query()->find($this->siteId);
        if ($site === null || ! $agent->supports($site)) {
            return;
        }

        if (! $agent->pushOAuthConfig($site)) {
            throw new RuntimeException('Instagram oauth-config push failed for site '.$site->id.'.');
        }
    }
}
