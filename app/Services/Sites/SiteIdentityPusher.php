<?php

namespace App\Services\Sites;

use App\Models\Site;
use App\Models\User;
use App\Services\Agent\SiteAgentClient;
use App\Services\Agent\SiteIdentityAgentResult;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The site display name lives in Plane (`sites.name`). The CMS only seeds its
 * own copy from env on first boot, so Plane pushes the name over the signed
 * agent whenever it changes here or drifts there — never through env or a redeploy.
 */
class SiteIdentityPusher
{
    public function __construct(
        private readonly SiteAgentClient $client,
    ) {}

    /**
     * Pushes Plane's current name to the CMS. A failed call changes nothing in
     * Plane; the caller decides whether to surface the safe message.
     */
    public function push(Site $site, ?User $actor = null, ?string $ip = null, string $origin = 'rename'): SiteIdentityAgentResult
    {
        $name = trim((string) $site->name);
        if ($name === '' || ! $site->canChangePublishStatus()) {
            return SiteIdentityAgentResult::needsSecret();
        }

        $result = $this->client->setSiteName($site, $name);

        if ($result->ok && $result->changed) {
            $site->auditLogs()->create([
                'actor_user_id' => $actor?->id,
                'action' => 'site.name_pushed',
                'before' => [],
                'after' => ['name' => $result->name, 'origin' => $origin],
                'ip' => $ip,
            ]);
        }

        return $result;
    }

    /**
     * Health-poll hook: the CMS reported `$reportedName`; when it differs from
     * Plane's name, push ours. Best effort — a poll must never fail on this.
     */
    public function healFromHealth(Site $site, mixed $reportedName): void
    {
        if (! is_string($reportedName)) {
            // CMS older than 1.2.27 reports nothing; there is nothing to compare.
            return;
        }

        $expected = trim((string) $site->name);
        if ($expected === '' || trim($reportedName) === $expected) {
            return;
        }

        try {
            $this->push($site, null, null, 'health');
        } catch (Throwable $exception) {
            Log::warning('Site name heal failed', [
                'site_id' => $site->id,
                'site_slug' => $site->slug,
                'reason' => $exception->getMessage(),
            ]);
        }
    }
}
