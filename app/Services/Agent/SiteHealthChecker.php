<?php

namespace App\Services\Agent;

use App\Models\Site;

class SiteHealthChecker
{
    public function __construct(
        private readonly SiteAgentClient $client,
    ) {}

    public function check(Site $site): AgentHealthResult
    {
        $result = $this->client->health($site);

        $site->last_health_at = now();
        $site->last_health_payload = $this->sanitizedSummary($site, $result->summary);
        $site->save();

        return $result;
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>
     */
    private function sanitizedSummary(Site $site, array $summary): array
    {
        $secret = (string) $site->agent_secret_encrypted;
        $allowed = [
            'ok',
            'status',
            'reason',
            'deamon_version',
            'channel_hint',
            'active_theme_id',
            'php',
            'queue_ok',
            'http_status',
        ];

        $clean = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $summary)) {
                $clean[$key] = $summary[$key];
            }
        }

        if ($secret !== '') {
            $encoded = json_encode($clean, JSON_UNESCAPED_SLASHES);
            if (is_string($encoded) && str_contains($encoded, $secret)) {
                return [
                    'ok' => false,
                    'status' => AgentHealthStatus::Unhealthy,
                    'reason' => AgentHealthReason::HttpError,
                ];
            }
        }

        return $clean;
    }
}
