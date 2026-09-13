<?php

namespace App\Services\Fleet;

use App\Enums\Channel;
use App\Enums\SiteStatus;
use App\Models\Deployment;
use App\Models\Site;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class FleetDashboardKpis
{
    /**
     * Unhealthy sites from the shared SQL predicate. Memoised so the KPI
     * count and the attention list do not query twice on one request.
     *
     * @var Collection<int, Site>|null
     */
    private ?Collection $unhealthy = null;

    /**
     * @return array{
     *     total_sites: int,
     *     by_channel: array<string, int>,
     *     unhealthy: int,
     *     failed_deploys: int,
     *     failed_deploy_window_hours: int,
     *     deploying: int,
     *     agent_secret: array{missing: int, unverified: int, ok: int}
     * }
     */
    public function snapshot(): array
    {
        $channels = config('ops.channels', Channel::values());
        $byChannel = [];

        foreach ($channels as $channel) {
            $byChannel[(string) $channel] = 0;
        }

        $counts = Site::query()
            ->selectRaw('channel, COUNT(*) as aggregate')
            ->groupBy('channel')
            ->pluck('aggregate', 'channel');

        foreach ($counts as $channel => $total) {
            $key = (string) $channel;
            if (array_key_exists($key, $byChannel)) {
                $byChannel[$key] = (int) $total;
            }
        }

        return [
            'total_sites' => Site::query()->count(),
            'by_channel' => $byChannel,
            'unhealthy' => $this->unhealthySiteCount(),
            'failed_deploys' => $this->failedDeploySiteCount(),
            'failed_deploy_window_hours' => $this->failedDeployWindowHours(),
            'deploying' => Site::query()
                ->whereIn('status', [SiteStatus::Provisioning, SiteStatus::Deploying])
                ->count(),
            'agent_secret' => $this->agentSecretCounts(),
        ];
    }

    /**
     * Three SQL counts, never a fleet scan. The card and the Sites filter
     * share Site::missing/unverified/verifiedAgentSecret().
     *
     * @return array{missing: int, unverified: int, ok: int}
     */
    public function agentSecretCounts(): array
    {
        return [
            'missing' => Site::query()->missingAgentSecret()->count(),
            'unverified' => Site::query()->unverifiedAgentSecret()->count(),
            'ok' => Site::query()->verifiedAgentSecret()->count(),
        ];
    }

    /**
     * Sites that still need a secret or a CMS 200, newest name first.
     * Pass 0 to skip the attention cap (search must see overflow rows).
     *
     * @return Collection<int, Site>
     */
    public function agentSecretAttentionSites(?int $limit = null): Collection
    {
        $columns = [
            'id',
            'slug',
            'name',
            'primary_domain',
            'status',
            'last_health_at',
            'last_health_payload',
            'agent_secret_encrypted',
        ];

        $ids = Site::query()->missingAgentSecret()->pluck('id')
            ->merge(Site::query()->unverifiedAgentSecret()->pluck('id'))
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        $query = Site::query()
            ->whereIn('id', $ids->all())
            ->orderBy('name');

        $cap = $limit ?? $this->attentionLimit();
        if ($cap > 0) {
            $query->limit($cap);
        }

        return $query->get($columns);
    }

    public function agentSecretAttentionTotal(): int
    {
        $counts = $this->agentSecretCounts();

        return $counts['missing'] + $counts['unverified'];
    }

    /**
     * How far back a failed deploy still counts as "now". Without a window the card only
     * ever grows and keeps reporting deploys that failed weeks ago.
     */
    public function failedDeployWindowHours(): int
    {
        return Deployment::failedWindowHours();
    }

    /**
     * How many rows an attention card renders before it summarises the rest.
     */
    public function attentionLimit(): int
    {
        return max(1, (int) config('ops.fleet.attention_limit', 8));
    }

    /**
     * Sites with a failed deploy inside the window. Counted by site, so five failed
     * retries of one site are one problem, and the card matches the list below it.
     */
    public function failedDeploySiteCount(): int
    {
        return (int) $this->failedDeploysInWindow()->distinct()->count('site_id');
    }

    /**
     * Sites still on Coolify dockerfile pack (compose not migrated). Not CMS deamon_version.
     *
     * @return Collection<int, Site>
     */
    public function dockerfilePackSites(): Collection
    {
        return Site::query()
            ->withDockerfileBuildPackWarning()
            ->orderBy('name')
            ->get(['id', 'slug', 'name', 'primary_domain', 'notes']);
    }

    /**
     * How many sites the unhealthy card counts. Same SQL as `/sites?health=unhealthy`.
     */
    public function unhealthySiteCount(): int
    {
        return $this->unhealthySites(0)->count();
    }

    /**
     * Sites the unhealthy card lists. Pass 0 to skip the attention cap
     * (search must see overflow rows).
     *
     * @return Collection<int, Site>
     */
    public function unhealthySites(?int $limit = null): Collection
    {
        if (! $this->unhealthy instanceof Collection) {
            // SQL verdict (ADR-10), never a PHP walk of every site.
            $this->unhealthy = Site::query()
                ->unhealthy()
                ->orderBy('name')
                ->get([
                    'id',
                    'slug',
                    'name',
                    'status',
                    'primary_domain',
                    'last_health_at',
                    'last_health_payload',
                    'agent_secret_encrypted',
                    'health_unhealthy',
                ]);
        }

        $cap = $limit ?? $this->attentionLimit();
        if ($cap > 0) {
            return $this->unhealthy->take($cap)->values();
        }

        return $this->unhealthy;
    }

    /**
     * The latest failure per site inside the window, newest first.
     * Pass 0 to skip the attention cap (search must see overflow rows).
     *
     * @return Collection<int, Deployment>
     */
    public function recentFailedDeploys(?int $limit = null): Collection
    {
        $query = $this->failedDeploysInWindow()
            ->selectRaw('MAX(id) as id')
            ->groupBy('site_id')
            ->orderByDesc('id');

        $cap = $limit ?? $this->attentionLimit();
        if ($cap > 0) {
            $query->limit($cap);
        }

        $latestPerSite = $query->pluck('id');

        if ($latestPerSite->isEmpty()) {
            return collect();
        }

        return Deployment::query()
            ->with('site')
            ->whereIn('id', $latestPerSite->all())
            ->latest('id')
            ->get();
    }

    /**
     * @return Builder<Deployment>
     */
    private function failedDeploysInWindow(): Builder
    {
        // Shared with the `deploy=failed` list filter, so the card and the page it
        // links to can never disagree about what "failed recently" means.
        return Deployment::query()->failedInWindow($this->failedDeployWindowHours());
    }
}
