<?php

namespace App\Services\Sites;

use App\Enums\CmsPublishStatus;
use App\Models\Site;
use App\Models\User;
use App\Services\Agent\SiteAgentClient;
use Illuminate\Support\Facades\DB;

/**
 * Publish state belongs to the CMS. Plane asks the signed agent to change it and
 * then mirrors whatever the CMS confirmed onto `sites.cms_site_status`.
 *
 * A failed call leaves the mirror alone: Plane must not claim a site is published
 * when the CMS never agreed.
 */
class SitePublishStateUpdater
{
    public function __construct(
        private readonly SiteAgentClient $client,
    ) {}

    /**
     * @throws SitePublishException
     */
    public function apply(Site $site, CmsPublishStatus $status, ?User $actor, ?string $ip): CmsPublishStatus
    {
        $result = $this->client->setSiteStatus($site, $status);

        if (! $result->ok || $result->status === null) {
            throw new SitePublishException($result->safeMessage);
        }

        $confirmed = $result->status;
        $before = $site->publishStatus();

        DB::transaction(function () use ($site, $confirmed, $before, $result, $actor, $ip): void {
            $this->mirror($site, $confirmed);

            // Only a real transition is worth an audit row; a re-assert is noise.
            if ($result->changed || $before !== $confirmed) {
                $site->auditLogs()->create([
                    'actor_user_id' => $actor?->id,
                    'action' => $confirmed === CmsPublishStatus::Published
                        ? 'site.published'
                        : 'site.unpublished',
                    'before' => ['cms_site_status' => $before?->value],
                    'after' => ['cms_site_status' => $confirmed->value],
                    'ip' => $ip,
                ]);
            }
        });

        return $confirmed;
    }

    /**
     * Writes the mirror from a health poll. `null` means the CMS stopped
     * reporting a publish state, which is not the same as "taslak".
     */
    public function mirror(Site $site, ?CmsPublishStatus $status): void
    {
        $site->cms_site_status = $status;
        $site->cms_site_status_at = $status === null ? null : now();
        $site->save();
    }
}
