<?php

namespace App\Services\Security;

use App\Models\FleetIpBlock;
use App\Models\Site;
use App\Models\SiteSecurityEvent;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Cross-site correlation: the same address showing up (as a warning or worse) on
 * two or more sites inside the window. One scanner walking the fleet is a very
 * different thing from one site's noise, so these lead the screens.
 */
class SecurityCorrelator
{
    /**
     * @return Collection<int, array{ip: string, sites_count: int, events: int, critical: int, first_seen: string, last_seen: string, rules: list<string>, sites: list<array{id: string, slug: string, name: string, events: int}>, blocked: bool}>
     */
    public function crossSite(?CarbonInterface $since = null, ?int $minSites = null, int $limit = 20): Collection
    {
        $since ??= now()->subDay();
        $minSites ??= max(2, (int) config('ops.security.cross_site_min_sites', 2));

        $ips = SiteSecurityEvent::query()
            ->where('severity', '>=', SiteSecurityEvent::SEVERITY_WARNING)
            ->where('occurred_at', '>=', $since)
            ->groupBy('ip')
            ->havingRaw('COUNT(DISTINCT site_id) >= ?', [$minSites])
            ->selectRaw('ip, COUNT(DISTINCT site_id) as sites_count, SUM(count) as events, MAX(occurred_at) as last_seen')
            ->orderByDesc('sites_count')
            ->orderByDesc('last_seen')
            ->limit($limit)
            ->get();

        if ($ips->isEmpty()) {
            return collect();
        }

        $rows = SiteSecurityEvent::query()
            ->whereIn('ip', $ips->pluck('ip'))
            ->where('severity', '>=', SiteSecurityEvent::SEVERITY_WARNING)
            ->where('occurred_at', '>=', $since)
            ->get(['site_id', 'ip', 'rule', 'severity', 'count', 'occurred_at']);

        $sites = Site::query()->whereIn('id', $rows->pluck('site_id')->unique())->get(['id', 'slug', 'name'])->keyBy('id');
        $blocked = FleetIpBlock::query()->active()->whereIn('ip', $ips->pluck('ip'))->pluck('ip')->flip();

        return $ips->map(function ($row) use ($rows, $sites, $blocked): array {
            $mine = $rows->where('ip', $row->ip);
            $first = $mine->min('occurred_at');

            return [
                'ip' => (string) $row->ip,
                'sites_count' => (int) $row->sites_count,
                'events' => (int) $row->events,
                'critical' => (int) $mine->where('severity', SiteSecurityEvent::SEVERITY_CRITICAL)->sum('count'),
                'first_seen' => $first?->toIso8601String() ?? '',
                'last_seen' => $mine->max('occurred_at')?->toIso8601String() ?? '',
                'rules' => $mine->pluck('rule')->unique()->values()->take(8)->all(),
                'sites' => $mine->groupBy('site_id')->map(fn (Collection $group, string $siteId): array => [
                    'id' => $siteId,
                    'slug' => (string) ($sites[$siteId]->slug ?? ''),
                    'name' => (string) ($sites[$siteId]->name ?? $siteId),
                    'events' => (int) $group->sum('count'),
                ])->sortByDesc('events')->values()->all(),
                'blocked' => isset($blocked[$row->ip]),
            ];
        })->values();
    }
}
