<?php

namespace App\Services\Sites;

use App\Jobs\ConfigureSiteMailJob;
use App\Jobs\PushPlatformMailJob;
use App\Models\Site;
use App\Services\Coolify\CoolifyAppEnvSync;
use App\Services\Coolify\CoolifyApplicationService;
use Throwable;

/**
 * The CMS accepts Plane callbacks only from hosts in CONTROL_PLANE_HOST_ALLOWLIST and answers
 * "URL host is not on the allowlist." otherwise. That env is written by the Plane catalog sync,
 * which only runs on a Plane deploy, and the CMS caches config at boot. A stale value therefore
 * failed every mail push with no path back. This writes the catalog env for the site and marks
 * it so both mail pushes run again once the next deploy finishes.
 */
final class SitePlaneAllowlistHeal
{
    private const MARKER = 'not on the allowlist';

    public function __construct(
        private readonly CoolifyAppEnvSync $envSync,
    ) {}

    public static function isAllowlistRejection(?string $cmsMessage): bool
    {
        return is_string($cmsMessage) && str_contains(strtolower($cmsMessage), self::MARKER);
    }

    /**
     * @return list<string> env keys written (never values)
     */
    public function prepare(Site $site): array
    {
        if (blank($site->coolify_app_uuid)) {
            return [];
        }

        try {
            $touched = $this->envSync->sync($site, CoolifyApplicationService::forSite($site));
        } catch (Throwable $exception) {
            report($exception);
            $touched = [];
        }

        Site::query()->whereKey($site->getKey())->update(['mail_push_after_deploy' => true]);
        $site->mail_push_after_deploy = true;

        return $touched;
    }

    /**
     * Called when a deploy finishes: the CMS booted with the rewritten env.
     */
    public function pushAfterDeploy(Site $site): bool
    {
        $pending = (bool) Site::query()->whereKey($site->getKey())->value('mail_push_after_deploy');
        if (! $pending) {
            return false;
        }

        Site::query()->whereKey($site->getKey())->update(['mail_push_after_deploy' => false]);
        $site->mail_push_after_deploy = false;

        if (! $site->hasAgentSecret()) {
            return false;
        }

        try {
            if (filled($site->mail_server_id)) {
                ConfigureSiteMailJob::dispatch((string) $site->id);
            }
            PushPlatformMailJob::dispatch((string) $site->id);
        } catch (Throwable $exception) {
            // A sync queue runs the job inline; its failure must not break deployment sync.
            report($exception);
        }

        return true;
    }
}
