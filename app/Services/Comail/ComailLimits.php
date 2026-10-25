<?php

namespace App\Services\Comail;

use App\Models\MailPackage;
use App\Models\Site;

/**
 * Package limits. Plane enforces them on every create/edit across all of a
 * site's CoMail domains; the mailcow domain limits are only a safety ceiling.
 */
class ComailLimits
{
    public function package(Site $site): MailPackage
    {
        return MailPackage::forSite($site);
    }

    /**
     * mailcow domain attributes for a package (quotas in MB).
     *
     * @return array{mailboxes: int, quota: int, maxquota: int, defquota: int, aliases: int}
     */
    public function domainAttributes(MailPackage $package): array
    {
        $storage = max(1, (int) $package->storage_mb);
        $max = max(1, min((int) $package->mailbox_max_quota_mb, $storage));

        return [
            'mailboxes' => max(0, (int) $package->mailbox_limit),
            'quota' => $storage,
            'maxquota' => $max,
            'defquota' => min(1024, $max),
            'aliases' => max(0, (int) $package->alias_limit),
        ];
    }

    /**
     * @return array{mailboxes: int, aliases: int, domains: int, storage_allocated_mb: int, storage_used_bytes: int}
     */
    public function usage(ComailDirectory $directory): array
    {
        $allocated = 0;
        $used = 0;
        foreach ($directory->mailboxes() as $mailbox) {
            $allocated += ComailDirectory::bytesToMb($mailbox['quota'] ?? 0);
            $used += (int) ($mailbox['quota_used'] ?? 0);
        }

        return [
            'mailboxes' => count($directory->mailboxes()),
            'aliases' => count($directory->aliases()),
            'domains' => $directory->site->comailDomains()->count(),
            'storage_allocated_mb' => $allocated,
            'storage_used_bytes' => $used,
        ];
    }

    public function assertCanAddDomain(Site $site): void
    {
        $limit = $this->package($site)->domain_limit;
        if ($limit !== null && $site->comailDomains()->count() >= $limit) {
            throw new ComailException('limit_domains', "Paketiniz en fazla {$limit} alan adına izin veriyor.");
        }
    }

    /**
     * Quota for a new mailbox: the requested one, else min(remaining storage, per-mailbox max).
     */
    public function quotaForNewMailbox(ComailDirectory $directory, ?int $requestedMb): int
    {
        $package = $this->package($directory->site);
        $usage = $this->usage($directory);

        if ($usage['mailboxes'] >= $package->mailbox_limit) {
            throw new ComailException(
                'limit_mailboxes',
                "Paketiniz en fazla {$package->mailbox_limit} posta kutusuna izin veriyor.",
            );
        }

        $remaining = $package->storage_mb - $usage['storage_allocated_mb'];
        $quota = $requestedMb ?? min($remaining, $package->mailbox_max_quota_mb);

        $this->assertQuotaFits($package, $quota, $remaining);

        return $quota;
    }

    public function assertQuotaChange(ComailDirectory $directory, array $mailbox, int $newQuotaMb): void
    {
        $package = $this->package($directory->site);
        $usage = $this->usage($directory);
        $current = ComailDirectory::bytesToMb($mailbox['quota'] ?? 0);
        $remaining = $package->storage_mb - ($usage['storage_allocated_mb'] - $current);

        $this->assertQuotaFits($package, $newQuotaMb, $remaining);
    }

    public function assertCanAddAlias(ComailDirectory $directory): void
    {
        $package = $this->package($directory->site);
        if (count($directory->aliases()) >= $package->alias_limit) {
            throw new ComailException(
                'limit_aliases',
                "Paketiniz en fazla {$package->alias_limit} yönlendirmeye izin veriyor.",
            );
        }
    }

    private function assertQuotaFits(MailPackage $package, int $quotaMb, int $remainingMb): void
    {
        if ($remainingMb < 1) {
            throw new ComailException('limit_storage', 'Paketinizin depolama alanının tamamı posta kutularına dağıtılmış.');
        }

        if ($quotaMb < 1) {
            throw ComailException::validation('Kota en az 1 MB olmalı.');
        }

        if ($quotaMb > $package->mailbox_max_quota_mb) {
            throw new ComailException(
                'limit_storage',
                "Bir posta kutusu en fazla {$package->mailbox_max_quota_mb} MB olabilir.",
            );
        }

        if ($quotaMb > $remainingMb) {
            throw new ComailException(
                'limit_storage',
                "Paketinizde {$remainingMb} MB boş alan kaldı; daha küçük bir kota seçin.",
            );
        }
    }
}
