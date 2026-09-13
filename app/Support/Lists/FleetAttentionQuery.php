<?php

namespace App\Support\Lists;

use App\Models\Deployment;
use App\Models\Site;
use App\Services\Fleet\FleetDashboardKpis;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Filters the four fleet attention cards. `/` is not a second Sites list —
 * KPIs stay the unfiltered snapshot. Search and kind only hide cards the
 * operator is not looking at, and they run before the attention cap so a
 * site in the +N overflow is still findable.
 */
final class FleetAttentionQuery
{
    public const KINDS = ['unhealthy', 'failed', 'agent', 'dockerfile'];

    /**
     * @param  Collection<int, Site>  $unhealthySites
     * @param  Collection<int, Deployment>  $failedDeploys
     * @param  Collection<int, Site>  $agentSecretSites
     * @param  Collection<int, Site>  $dockerfilePackSites
     * @param  array{missing: int, unverified: int, ok: int}  $agentSecretCounts
     */
    public function __construct(
        public readonly string $search,
        public readonly string $kind,
        public readonly Collection $unhealthySites,
        public readonly int $unhealthyTotal,
        public readonly Collection $failedDeploys,
        public readonly int $failedTotal,
        public readonly Collection $agentSecretSites,
        public readonly int $agentSecretTotal,
        public readonly array $agentSecretCounts,
        public readonly Collection $dockerfilePackSites,
        public readonly int $unfilteredTotal,
        public readonly int $failedDeployWindowHours,
    ) {}

    public static function from(Request $request, FleetDashboardKpis $kpis): self
    {
        $search = trim((string) $request->query('q', ''));
        $kind = (string) $request->query('kind', '');
        $kind = in_array($kind, self::KINDS, true) ? $kind : '';
        $limit = $kpis->attentionLimit();

        $unhealthyAll = $kpis->unhealthySites(0);
        $failedAll = $kpis->recentFailedDeploys(0);
        $agentAll = $kpis->agentSecretAttentionSites(0);
        $dockerfileAll = $kpis->dockerfilePackSites();

        $unfilteredTotal = $unhealthyAll->count()
            + $failedAll->count()
            + $agentAll->count()
            + $dockerfileAll->count();

        $unhealthy = self::filterSites($unhealthyAll, $search);
        $failed = self::filterDeploys($failedAll, $search);
        $agent = self::filterSites($agentAll, $search);
        $dockerfile = self::filterSites($dockerfileAll, $search);

        if ($kind === 'unhealthy') {
            $failed = collect();
            $agent = collect();
            $dockerfile = collect();
        } elseif ($kind === 'failed') {
            $unhealthy = collect();
            $agent = collect();
            $dockerfile = collect();
        } elseif ($kind === 'agent') {
            $unhealthy = collect();
            $failed = collect();
            $dockerfile = collect();
        } elseif ($kind === 'dockerfile') {
            $unhealthy = collect();
            $failed = collect();
            $agent = collect();
        }

        return new self(
            $search,
            $kind,
            $unhealthy->take($limit)->values(),
            $unhealthy->count(),
            $failed->take($limit)->values(),
            $failed->count(),
            $agent->take($limit)->values(),
            $agent->count(),
            self::agentChipCounts($agent, $kpis->agentSecretCounts(), $search !== '' || $kind !== ''),
            $dockerfile->values(),
            $unfilteredTotal,
            $kpis->failedDeployWindowHours(),
        );
    }

    public function filtersActive(): bool
    {
        return $this->search !== '' || $this->kind !== '';
    }

    public function isEmpty(): bool
    {
        return $this->unhealthySites->isEmpty()
            && $this->failedDeploys->isEmpty()
            && $this->agentSecretSites->isEmpty()
            && $this->dockerfilePackSites->isEmpty();
    }

    /**
     * @return list<array{key: string, label: string, value: string, url: string}>
     */
    public function chips(): array
    {
        $applied = array_filter([
            'q' => $this->search,
            'kind' => $this->kind,
        ], static fn (string $value): bool => $value !== '');

        $labels = [
            'q' => __('fleet.filter_search'),
            'kind' => __('fleet.filter_kind'),
        ];
        $displayed = [
            'q' => $this->search,
            'kind' => $this->kind !== '' ? (string) __('fleet.kinds.'.$this->kind) : '',
        ];

        $chips = [];
        foreach ($applied as $key => $value) {
            $chips[] = [
                'key' => $key,
                'label' => $labels[$key],
                'value' => $displayed[$key],
                'url' => route('ops.fleet', array_diff_key($applied, [$key => true])),
            ];
        }

        return $chips;
    }

    /**
     * @param  Collection<int, Site>  $sites
     * @return Collection<int, Site>
     */
    private static function filterSites(Collection $sites, string $search): Collection
    {
        if ($search === '') {
            return $sites->values();
        }

        $needle = Str::lower($search);

        return $sites
            ->filter(static fn (Site $site): bool => str_contains(self::siteHaystack($site), $needle))
            ->values();
    }

    /**
     * @param  Collection<int, Deployment>  $deploys
     * @return Collection<int, Deployment>
     */
    private static function filterDeploys(Collection $deploys, string $search): Collection
    {
        if ($search === '') {
            return $deploys->values();
        }

        $needle = Str::lower($search);

        return $deploys
            ->filter(function (Deployment $deployment) use ($needle): bool {
                $site = $deployment->site;
                $parts = [
                    (string) $deployment->error_message,
                    (string) $deployment->channel?->value,
                ];
                if ($site instanceof Site) {
                    $parts[] = self::siteHaystack($site);
                }

                return str_contains(Str::lower(implode(' ', $parts)), $needle);
            })
            ->values();
    }

    private static function siteHaystack(Site $site): string
    {
        return Str::lower(implode(' ', [
            (string) $site->name,
            (string) $site->primary_domain,
            (string) $site->slug,
        ]));
    }

    /**
     * Unfiltered cards keep the fleet-wide yok / doğrulanmamış / tamam chip.
     * A search or kind filter would lie if it kept those numbers, so the chip
     * then reports only what the filtered list still contains.
     *
     * @param  Collection<int, Site>  $agent
     * @param  array{missing: int, unverified: int, ok: int}  $fleet
     * @return array{missing: int, unverified: int, ok: int}
     */
    private static function agentChipCounts(Collection $agent, array $fleet, bool $filtered): array
    {
        if (! $filtered) {
            return $fleet;
        }

        $missing = 0;
        $unverified = 0;
        foreach ($agent as $site) {
            if ($site->agentSecretFleetState() === 'missing') {
                $missing++;
            } else {
                $unverified++;
            }
        }

        return [
            'missing' => $missing,
            'unverified' => $unverified,
            'ok' => 0,
        ];
    }
}
