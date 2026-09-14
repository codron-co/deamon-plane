<?php

namespace App\Console\Commands;

use App\Enums\Channel;
use App\Services\Coolify\EnvCatalog\CoolifyEnvCatalogException;
use App\Services\Coolify\EnvCatalog\CoolifyEnvCatalogSync;
use Illuminate\Console\Command;

class SyncCoolifyEnvCatalogCommand extends Command
{
    protected $signature = 'ops:sync-env-catalog {--channel= : Only this git channel (main | beta | alpha)}';

    protected $description = 'Sync Coolify env catalogs from the CMS .env.production.example per git channel';

    public function handle(CoolifyEnvCatalogSync $sync): int
    {
        $only = trim((string) $this->option('channel'));
        $channels = $only !== ''
            ? [Channel::tryFrom($only)]
            : array_filter(Channel::cases(), static fn (Channel $channel): bool => $channel->isAllowed());

        $failed = 0;
        foreach ($channels as $channel) {
            if (! $channel instanceof Channel) {
                $this->error('Unknown channel: '.$only);

                return self::INVALID;
            }

            try {
                $source = $sync->sync($channel);
                $this->info(sprintf(
                    '%s: %d rows from %s@%s',
                    $channel->value,
                    (int) $source->row_count,
                    (string) $source->repo_full_name,
                    (string) ($source->shortSha() ?? $channel->value),
                ));
            } catch (CoolifyEnvCatalogException $exception) {
                $failed++;
                $this->error($channel->value.': '.$exception->getMessage());
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
