<?php

namespace App\Services\Sites;

use App\Models\FleetDailySnapshot;
use App\Models\Site;
use Carbon\CarbonImmutable;

/**
 * Fleet-wide counts behind the Sites summary tiles. The list page and the daily
 * snapshot both read them from here, so a trend never compares two different
 * definitions of the same metric.
 */
class SiteListSummary
{
    public const KEYS = ['total', 'unhealthy', 'failed_deploys', 'app_issues', 'git_themes'];

    /**
     * Metrics where a rise means more broken sites.
     */
    public const PROBLEM_KEYS = ['unhealthy', 'failed_deploys', 'app_issues'];

    /**
     * @return array{total: int, unhealthy: int, failed_deploys: int, app_issues: int, git_themes: int}
     */
    public function counts(): array
    {
        return [
            'total' => Site::query()->count(),
            'unhealthy' => Site::query()->unhealthy()->count(),
            'failed_deploys' => Site::query()->matchingListFilters(deploy: 'failed')->count(),
            'app_issues' => Site::query()->withAppIssues()->count(),
            'git_themes' => Site::query()->matchingListFilters(theme: 'git')->count(),
        ];
    }

    /**
     * Writes (or refreshes) today's row; safe to run any number of times a day.
     */
    public function snapshot(?CarbonImmutable $day = null): FleetDailySnapshot
    {
        $date = ($day ?? CarbonImmutable::now(config('app.timezone')))->toDateString();

        return FleetDailySnapshot::query()->updateOrCreate(
            ['snapshot_date' => $date],
            $this->counts(),
        );
    }

    /**
     * Change of each metric against the latest snapshot from an earlier day.
     * Null when no such snapshot exists: no baseline, no trend.
     *
     * @param  array<string, int>  $current
     * @return array{date: string, yesterday: bool, deltas: array<string, int>}|null
     */
    public function trend(array $current, ?CarbonImmutable $today = null): ?array
    {
        $today ??= CarbonImmutable::now(config('app.timezone'));

        $baseline = FleetDailySnapshot::query()
            ->where('snapshot_date', '<', $today->toDateString())
            ->orderByDesc('snapshot_date')
            ->first();

        if ($baseline === null) {
            return null;
        }

        $deltas = [];
        foreach (self::KEYS as $key) {
            if (array_key_exists($key, $current)) {
                $deltas[$key] = (int) $current[$key] - (int) $baseline->{$key};
            }
        }

        $date = substr((string) $baseline->snapshot_date, 0, 10);

        return [
            'date' => $date,
            'yesterday' => $date === $today->subDay()->toDateString(),
            'deltas' => $deltas,
        ];
    }
}
