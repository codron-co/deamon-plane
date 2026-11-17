<?php

namespace App\Services\Security;

use App\Enums\SiteStatus;
use App\Models\FleetIpBlock;
use App\Models\Site;
use App\Models\SiteSecurityCursor;
use App\Models\SiteSecurityEvent;
use Illuminate\Support\Collection;

/**
 * Numbers for the fleet security overview and get_fleet_security_summary: event
 * volume and criticals over 24 h / 7 d, the most targeted sites, the busiest IPs,
 * cross-site cards, and how many sites actually report.
 */
class FleetSecurityOverview
{
    public function __construct(private readonly SecurityCorrelator $correlator) {}

    /**
     * @return array<string, mixed>
     */
    public function build(): array
    {
        $day = now()->subDay();
        $week = now()->subDays(7);

        $window = fn ($since): array => [
            'events' => (int) SiteSecurityEvent::query()->where('occurred_at', '>=', $since)->sum('count'),
            'critical' => (int) SiteSecurityEvent::query()->where('occurred_at', '>=', $since)->where('severity', '>=', SiteSecurityEvent::SEVERITY_CRITICAL)->sum('count'),
            'warning' => (int) SiteSecurityEvent::query()->where('occurred_at', '>=', $since)->where('severity', SiteSecurityEvent::SEVERITY_WARNING)->sum('count'),
        ];

        return [
            'generated_at' => now()->toIso8601String(),
            'window_24h' => $window($day),
            'window_7d' => $window($week),
            'top_sites' => $this->topSites($week),
            'top_ips' => $this->topIps($day),
            'cross_site' => $this->correlator->crossSite($day)->all(),
            'blocks_active' => FleetIpBlock::query()->active()->count(),
            'coverage' => $this->coverage(),
        ];
    }

    /**
     * @return list<array{id: string, slug: string, name: string, events: int, critical: int}>
     */
    public function topSites($since, int $limit = 5): array
    {
        $rows = SiteSecurityEvent::query()
            ->where('occurred_at', '>=', $since)
            ->groupBy('site_id')
            ->selectRaw('site_id, SUM(count) as events, SUM(CASE WHEN severity >= 2 THEN count ELSE 0 END) as critical')
            ->orderByDesc('events')
            ->limit($limit)
            ->get();

        $sites = Site::query()->whereIn('id', $rows->pluck('site_id'))->get(['id', 'slug', 'name'])->keyBy('id');

        return $rows->map(fn ($row): array => [
            'id' => (string) $row->site_id,
            'slug' => (string) ($sites[$row->site_id]->slug ?? ''),
            'name' => (string) ($sites[$row->site_id]->name ?? $row->site_id),
            'events' => (int) $row->events,
            'critical' => (int) $row->critical,
        ])->values()->all();
    }

    /**
     * @return list<array{ip: string, events: int, critical: int, sites: int, last_seen: string, blocked: bool}>
     */
    public function topIps($since, int $limit = 10): array
    {
        $rows = SiteSecurityEvent::query()
            ->where('occurred_at', '>=', $since)
            ->groupBy('ip')
            ->selectRaw('ip, SUM(count) as events, SUM(CASE WHEN severity >= 2 THEN count ELSE 0 END) as critical, COUNT(DISTINCT site_id) as sites, MAX(occurred_at) as last_seen')
            ->orderByDesc('events')
            ->limit($limit)
            ->get();

        $blocked = FleetIpBlock::query()->active()->whereIn('ip', $rows->pluck('ip'))->pluck('ip')->flip();

        return $rows->map(fn ($row): array => [
            'ip' => (string) $row->ip,
            'events' => (int) $row->events,
            'critical' => (int) $row->critical,
            'sites' => (int) $row->sites,
            'last_seen' => (string) $row->last_seen,
            'blocked' => isset($blocked[$row->ip]),
        ])->values()->all();
    }

    /**
     * @return array{monitored: int, module_off: int, unsupported: int, failing: int, pending: int, total: int}
     */
    public function coverage(): array
    {
        $sites = Site::query()->where('status', SiteStatus::Active->value)->whereNotNull('agent_secret_encrypted')->pluck('id');
        /** @var Collection<string, SiteSecurityCursor> $cursors */
        $cursors = SiteSecurityCursor::query()->whereIn('site_id', $sites)->get()->keyBy('site_id');
        $out = ['monitored' => 0, 'module_off' => 0, 'unsupported' => 0, 'failing' => 0, 'pending' => 0, 'total' => $sites->count()];

        foreach ($sites as $id) {
            $cursor = $cursors->get($id);
            match (true) {
                $cursor === null || $cursor->last_polled_at === null => $out['pending']++,
                $cursor->supported === false => $out['unsupported']++,
                $cursor->consecutive_failures > 0 => $out['failing']++,
                $cursor->module_enabled === false => $out['module_off']++,
                default => $out['monitored']++,
            };
        }

        return $out;
    }

    /**
     * One site: its latest counters block from the CMS plus what Plane stored.
     *
     * @return array<string, mixed>
     */
    public function site(Site $site): array
    {
        $cursor = SiteSecurityCursor::query()->where('site_id', $site->id)->first();
        $since = now()->subDay();
        $base = SiteSecurityEvent::query()->where('site_id', $site->id);

        return [
            'site' => ['id' => $site->id, 'slug' => $site->slug, 'name' => $site->name],
            'reporting' => [
                'supported' => $cursor?->supported,
                'module_enabled' => $cursor?->module_enabled,
                'last_polled_at' => $cursor?->last_polled_at?->toIso8601String(),
                'last_success_at' => $cursor?->last_success_at?->toIso8601String(),
                'consecutive_failures' => (int) ($cursor?->consecutive_failures ?? 0),
                'last_error' => $cursor?->last_error,
            ],
            'health_block' => $site->last_health_payload['security'] ?? null,
            'site_counters' => $cursor?->summary,
            'stored' => [
                'events_24h' => (int) (clone $base)->where('occurred_at', '>=', $since)->sum('count'),
                'critical_24h' => (int) (clone $base)->where('occurred_at', '>=', $since)->where('severity', '>=', 2)->sum('count'),
                'events_7d' => (int) (clone $base)->where('occurred_at', '>=', now()->subDays(7))->sum('count'),
            ],
        ];
    }
}
