<?php

namespace App\Jobs;

use App\Enums\Channel;
use App\Services\Coolify\EnvCatalog\CoolifyEnvCatalogException;
use App\Services\Coolify\EnvCatalog\CoolifyEnvCatalogSync;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Refresh one channel's Coolify env catalog from the CMS repo (webhook push / schedule / Settings).
 */
final class SyncCoolifyEnvCatalogJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly string $channel,
    ) {}

    public function handle(CoolifyEnvCatalogSync $sync): void
    {
        $channel = Channel::tryFrom($this->channel);
        if ($channel === null || ! $channel->isAllowed()) {
            return;
        }

        try {
            $sync->sync($channel);
        } catch (CoolifyEnvCatalogException) {
            // Recorded on coolify_env_catalog_sources.last_error; Settings shows it. No retry storm.
        }
    }
}
