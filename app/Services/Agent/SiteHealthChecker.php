<?php

namespace App\Services\Agent;

use App\Enums\CmsPublishStatus;
use App\Models\Site;
use App\Services\Mail\SiteHealthMailNotifier;

class SiteHealthChecker
{
    public function __construct(
        private readonly SiteAgentClient $client,
        private readonly SiteHealthMailNotifier $mailNotifier,
    ) {}

    public function check(Site $site): AgentHealthResult
    {
        $result = $this->client->health($site);

        $summary = $this->sanitizedSummary($site, $result->summary);

        $site->last_health_at = now();
        $site->last_health_payload = $summary;

        // Mirror the CMS publish state so the sites list can sort/filter on a real
        // column. A poll that could not reach the CMS says nothing about publish
        // state, so it must not clear a value we already know.
        if ($result->ok || array_key_exists('site_status', $summary)) {
            $reported = CmsPublishStatus::tryFrom((string) ($summary['site_status'] ?? ''));
            $site->cms_site_status = $reported;
            $site->cms_site_status_at = $reported === null ? null : now();
        }

        $site->save();

        $this->mailNotifier->afterHealthCheck($site->fresh() ?? $site, $result);

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
            'site_status',
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
