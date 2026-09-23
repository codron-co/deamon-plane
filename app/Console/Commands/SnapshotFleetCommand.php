<?php

namespace App\Console\Commands;

use App\Services\Sites\SiteListSummary;
use Illuminate\Console\Command;

/**
 * Stores today's Sites summary counts so the list tiles can show the change
 * since the previous day. Re-running the same day overwrites that day's row.
 */
class SnapshotFleetCommand extends Command
{
    protected $signature = 'ops:snapshot-fleet';

    protected $description = 'Store today\'s fleet summary counts for the Sites list trend';

    public function handle(SiteListSummary $summary): int
    {
        $snapshot = $summary->snapshot();

        $this->info(sprintf(
            'Fleet snapshot %s: total %d, unhealthy %d, failed deploys %d, app issues %d, git themes %d.',
            $snapshot->snapshot_date,
            $snapshot->total,
            $snapshot->unhealthy,
            $snapshot->failed_deploys,
            $snapshot->app_issues,
            $snapshot->git_themes,
        ));

        return self::SUCCESS;
    }
}
