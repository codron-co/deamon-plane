<?php

namespace App\Console\Commands;

use App\Models\SiteSecurityEvent;
use Illuminate\Console\Command;

/**
 * Fleet security events hold IP addresses (personal data under KVKK): keep them
 * `ops.security.retention_days` (90 by default) and no longer. Deletes in small
 * chunks so the nightly pass never holds a long lock.
 */
class PruneSecurityEventsCommand extends Command
{
    protected $signature = 'security:prune {--days= : Keep this many days (default ops.security.retention_days, at least 7)}';

    protected $description = 'Delete fleet security events older than the retention window';

    public function handle(): int
    {
        $days = $this->option('days') !== null
            ? max(7, (int) $this->option('days'))
            : SiteSecurityEvent::retentionDays();
        $cutoff = now()->subDays($days);
        $deleted = 0;

        do {
            $batch = SiteSecurityEvent::query()->where('occurred_at', '<', $cutoff)->limit(5000)->delete();
            $deleted += $batch;
        } while ($batch === 5000);

        $this->info("Pruned {$deleted} security events older than {$days} days.");

        return self::SUCCESS;
    }
}
