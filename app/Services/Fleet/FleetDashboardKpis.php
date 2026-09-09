<?php

namespace App\Services\Fleet;

use App\Enums\Channel;
use App\Enums\DeploymentStatus;
use App\Enums\SiteStatus;
use App\Models\Deployment;
use App\Models\Site;
use App\Services\Agent\SiteHealthEvaluator;
use Illuminate\Support\Collection;

class FleetDashboardKpis
{
    /**
     * @return array{
     *     total_sites: int,
     *     by_channel: array<string, int>,
     *     unhealthy: int,
     *     failed_deploys: int,
     *     deploying: int
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

        $evaluator = new SiteHealthEvaluator;
        $unhealthy = Site::query()
            ->get(['id', 'status', 'last_health_at', 'last_health_payload', 'agent_secret_encrypted'])
            ->filter(static fn (Site $site): bool => $evaluator->countsAsUnhealthy($site))
            ->count();

        return [
            'total_sites' => Site::query()->count(),
            'by_channel' => $byChannel,
            'unhealthy' => $unhealthy,
            'failed_deploys' => Deployment::query()->where('status', DeploymentStatus::Failed)->count(),
            'deploying' => Site::query()
                ->whereIn('status', [SiteStatus::Provisioning, SiteStatus::Deploying])
                ->count(),
        ];
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
}
