<?php

namespace App\Services\Agent;

use App\Enums\SiteStatus;
use App\Models\Site;

class SiteHealthEvaluator
{
    /**
     * Fleet unhealthy KPI: status=error or a failing/stale agent check.
     * needs_secret / unknown do not count (import leftovers).
     */
    public function countsAsUnhealthy(Site $site): bool
    {
        if ($site->status === SiteStatus::Error) {
            return true;
        }

        return $this->isAgentFailing($site);
    }

    public function isAgentFailing(Site $site): bool
    {
        $payload = $this->payload($site);
        $status = $payload['status'] ?? null;

        if (in_array($status, [AgentHealthStatus::NeedsSecret, AgentHealthStatus::Unknown], true)) {
            return false;
        }

        $reason = $payload['reason'] ?? null;
        if (in_array($reason, [
            AgentHealthReason::Timeout,
            AgentHealthReason::BadSignature,
            AgentHealthReason::QueueUnhealthy,
            AgentHealthReason::HttpError,
        ], true)) {
            return true;
        }

        if (array_key_exists('queue_ok', $payload) && $payload['queue_ok'] === false) {
            return true;
        }

        if (($payload['ok'] ?? null) === false) {
            return true;
        }

        return $this->isStale($site);
    }

    public function isStale(Site $site): bool
    {
        if (! $site->hasAgentSecret() || $site->last_health_at === null) {
            return false;
        }

        $payload = $this->payload($site);
        if (in_array($payload['status'] ?? null, [AgentHealthStatus::NeedsSecret, AgentHealthStatus::Unknown], true)) {
            return false;
        }

        $minutes = max(1, (int) config('ops.agent.stale_after_minutes', 30));

        return $site->last_health_at->lt(now()->subMinutes($minutes));
    }

    public function displayStatus(Site $site): string
    {
        $payload = $this->payload($site);
        $stored = is_string($payload['status'] ?? null) ? $payload['status'] : AgentHealthStatus::Unknown;

        if ($stored === AgentHealthStatus::Ok && $this->isStale($site)) {
            return AgentHealthStatus::Stale;
        }

        return $stored;
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(Site $site): array
    {
        return is_array($site->last_health_payload) ? $site->last_health_payload : [];
    }
}
