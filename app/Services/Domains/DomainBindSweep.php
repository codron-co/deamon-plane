<?php

namespace App\Services\Domains;

use App\Models\Site;
use App\Models\SiteDomain;
use App\Services\Ops\BulkResultSummary;
use App\Services\Ops\PacedFanout;
use App\Services\Sites\SiteLanding;
use Illuminate\Support\Collection;

/**
 * Binds selected fleet hosts on Coolify. The Coolify write is per site (the
 * proxy payload is the whole host list), so two aliases of one site cost one
 * PATCH. Already-bound hosts are a no-op and never touch Coolify.
 */
class DomainBindSweep
{
    public function __construct(
        private readonly SiteLanding $landing,
        private readonly PacedFanout $fanout,
    ) {}

    /**
     * @param  Collection<int, SiteDomain>  $domains
     * @param  null|callable(Site, int, int): void  $onProgress
     * @return array{ok: int, failed: int, skipped: int, waiting: int, unready: int, errors: list<string>, waiting_sites: list<string>, rate_limited: bool, deploy_busy: bool}
     */
    public function run(Collection $domains, ?callable $onProgress = null): array
    {
        $already = $domains->filter(fn (SiteDomain $row): bool => $row->verified_at !== null);
        $unready = $domains->filter(function (SiteDomain $row): bool {
            return $row->verified_at === null
                && (! $row->site instanceof Site || blank($row->site->coolify_app_uuid));
        });
        $todo = $domains->filter(function (SiteDomain $row): bool {
            return $row->verified_at === null
                && $row->site instanceof Site
                && filled($row->site->coolify_app_uuid);
        });

        /** @var Collection<string, Collection<int, SiteDomain>> $bySite */
        $bySite = $todo->groupBy(fn (SiteDomain $row): string => (string) $row->site_id);
        $sites = $todo
            ->map(fn (SiteDomain $row): ?Site => $row->site instanceof Site ? $row->site : null)
            ->filter()
            ->unique(fn (Site $site): string => (string) $site->id)
            ->values();

        $okSiteIds = [];
        $fanout = $sites->isEmpty()
            ? ['errors' => [], 'waiting_sites' => [], 'rate_limited' => false]
            : $this->fanout->run(
                $sites,
                function (Site $site) use ($bySite, &$okSiteIds): void {
                    $this->landing->syncCoolifyDomains($site);
                    foreach ($bySite[(string) $site->id] ?? [] as $domain) {
                        $domain->verified_at = now();
                        $domain->save();
                    }
                    $okSiteIds[] = $site->id;
                },
                $onProgress,
            );

        $ok = $already->count();
        $failed = 0;
        $skipped = 0;
        $waiting = 0;
        $waitingNames = array_fill_keys($fanout['waiting_sites'] ?? [], true);
        $errors = $fanout['errors'] ?? [];

        foreach ($sites as $site) {
            $n = $bySite[(string) $site->id]->count();
            if (in_array($site->id, $okSiteIds, true)) {
                $ok += $n;

                continue;
            }

            $error = $this->errorFor($errors, $site->name);
            if (isset($waitingNames[$site->name])) {
                $waiting += $n;
            } elseif ($error !== null && $this->isThrottleError($error)) {
                $skipped += $n;
            } else {
                $failed += $n;
            }
        }

        return [
            'ok' => $ok,
            'failed' => $failed,
            'skipped' => $skipped,
            'waiting' => $waiting,
            'unready' => $unready->count(),
            'errors' => $errors,
            'waiting_sites' => $fanout['waiting_sites'] ?? [],
            'rate_limited' => $skipped > 0,
            'deploy_busy' => $waiting > 0,
        ];
    }

    /**
     * @param  array{ok?: int, failed?: int, skipped?: int, waiting?: int, unready?: int, errors?: list<string>}  $result
     */
    public function summarize(array $result): string
    {
        $text = BulkResultSummary::format(__('domains.flash.bulk_bound'), $result);
        if ((int) ($result['unready'] ?? 0) > 0) {
            $text .= ' '.__('domains.flash.unready', ['count' => $result['unready']]);
        }

        return $text;
    }

    /**
     * @param  list<string>  $errors
     */
    private function errorFor(array $errors, string $name): ?string
    {
        $prefix = $name.':';
        foreach ($errors as $error) {
            if (str_starts_with($error, $prefix)) {
                return $error;
            }
        }

        return null;
    }

    private function isThrottleError(string $error): bool
    {
        return str_contains($error, (string) __('coolify.errors.rate_limited'))
            || str_contains($error, 'Too Many Attempts')
            || str_contains($error, '429');
    }
}
