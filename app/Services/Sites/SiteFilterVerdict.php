<?php

namespace App\Services\Sites;

use App\Enums\SiteStatus;
use App\Models\Site;
use App\Services\Agent\AgentHealthReason;
use App\Services\Agent\AgentHealthStatus;
use App\Services\Agent\SiteHealthEvaluator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Persisted Sites-list verdicts. Written on the same save as the health /
 * app-health payload (ADR-10). The list filter is SQL — never a PHP walk.
 */
final class SiteFilterVerdict
{
    /**
     * Stamp both verdicts on the in-memory model. The caller saves.
     */
    public static function apply(Site $site): void
    {
        self::applyHealth($site);
        self::applyApp($site);
    }

    public static function applyHealth(Site $site): void
    {
        $site->health_unhealthy = (new SiteHealthEvaluator)->countsAsUnhealthy($site);
        $site->health_verdict_at = now();
    }

    public static function applyApp(Site $site): void
    {
        $report = SiteAppHealthReport::forDisplay($site);
        $site->app_has_issues = SiteAppHealthFixer::orderedUniqueFixes($report) !== [];
        $site->app_health_issue_count = $report->count();
        $site->app_health_verdict_at = now();
    }

    /**
     * SQL equivalent of SiteHealthEvaluator::countsAsUnhealthy(), plus the
     * denormalized column. Stale is time-based, so it cannot live in the
     * column alone — the window is evaluated here, not in PHP over the fleet.
     *
     * @param  Builder<Site>  $query
     * @return Builder<Site>
     */
    public static function constrainUnhealthy(Builder $query): Builder
    {
        $minutes = max(1, (int) config('ops.agent.stale_after_minutes', 30));

        return $query->where(function (Builder $unhealthy) use ($minutes): void {
            $unhealthy
                ->where('health_unhealthy', true)
                ->orWhere('status', SiteStatus::Error)
                ->orWhere(function (Builder $failing): void {
                    self::constrainAgentFailing($failing);
                })
                ->orWhere(function (Builder $stale) use ($minutes): void {
                    self::constrainStale($stale, $minutes);
                });
        });
    }

    /**
     * @param  Builder<Site>  $query
     */
    private static function constrainAgentFailing(Builder $query): void
    {
        $query->where(function (Builder $notExempt): void {
            $notExempt->whereNull('last_health_payload->status')
                ->orWhereNotIn('last_health_payload->status', [
                    AgentHealthStatus::NeedsSecret,
                    AgentHealthStatus::Unknown,
                ]);
        })->where(function (Builder $signal): void {
            $signal->whereIn('last_health_payload->reason', [
                AgentHealthReason::Timeout,
                AgentHealthReason::BadSignature,
                AgentHealthReason::QueueUnhealthy,
                AgentHealthReason::HttpError,
            ])->orWhere('last_health_payload->queue_ok', false)
                ->orWhere('last_health_payload->ok', false);
        });
    }

    /**
     * @param  Builder<Site>  $query
     */
    private static function constrainStale(Builder $query, int $minutes): void
    {
        $query->whereNotNull('agent_secret_encrypted')
            ->whereNotNull('last_health_at')
            ->where('last_health_at', '<', now()->subMinutes($minutes))
            ->where(function (Builder $payload): void {
                $payload->whereNull('last_health_payload->status')
                    ->orWhereNotIn('last_health_payload->status', [
                        AgentHealthStatus::NeedsSecret,
                        AgentHealthStatus::Unknown,
                    ]);
            });
    }
}
