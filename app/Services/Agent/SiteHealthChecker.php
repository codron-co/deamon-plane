<?php

namespace App\Services\Agent;

use App\Enums\CmsPublishStatus;
use App\Models\Site;
use App\Services\Mail\SiteHealthMailNotifier;
use App\Services\Sites\SiteFilterVerdict;
use App\Services\Sites\SiteIdentityPusher;

class SiteHealthChecker
{
    /**
     * Reported facts that stay true while the agent is unreachable.
     *
     * @var list<string>
     */
    private const LAST_KNOWN_KEYS = [
        'deamon_version',
        'active_theme_id',
        'channel_hint',
        'php',
        'site_name',
    ];

    public function __construct(
        private readonly SiteAgentClient $client,
        private readonly SiteHealthMailNotifier $mailNotifier,
        private readonly SiteIdentityPusher $identity,
        private readonly CoreThemeHealer $coreTheme,
    ) {}

    public function check(Site $site): AgentHealthResult
    {
        $result = $this->client->health($site);

        $summary = $this->sanitizedSummary($site, $result->summary);
        if (! $result->ok) {
            $summary = $this->withLastKnown($site, $summary);
        }

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

        SiteFilterVerdict::apply($site);
        $site->save();

        // Plane owns the display name. A CMS that reports a different one (stale
        // DEAMON_SITE_NAME seed, cloned env) is corrected here, on every poll, so no
        // site has to be fixed by hand.
        if ($result->ok) {
            $this->identity->healFromHealth($site, $summary['site_name'] ?? null);
            $this->coreTheme->healFromHealth($site, $summary);
        }

        $this->mailNotifier->afterHealthCheck($site->fresh() ?? $site, $result);

        return $result;
    }

    /**
     * A failed poll says nothing about which CMS version or theme the site runs.
     * Keep the last reported values so version gates and theme checks do not
     * act on "unknown" after one timeout.
     *
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>
     */
    private function withLastKnown(Site $site, array $summary): array
    {
        $previous = is_array($site->last_health_payload) ? $site->last_health_payload : [];

        foreach (self::LAST_KNOWN_KEYS as $key) {
            if (! array_key_exists($key, $summary) && array_key_exists($key, $previous)) {
                $summary[$key] = $previous[$key];
            }
        }

        return $summary;
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
            'site_name',
            'core_theme_in_sync',
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
