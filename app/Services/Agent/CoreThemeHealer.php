<?php

namespace App\Services\Agent;

use App\Models\Site;
use App\Services\Coolify\CoolifyApplicationService;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * CMS 1.2.30+ reports `core_theme.in_sync: false` when themes/default on the
 * persistent volume is older than the image seed — core:: views the running CMS
 * renders (timeline feed, appointment confirm…) may be missing and 500. The
 * entrypoint refreshes the copy on boot, so a restart is the whole fix; it is
 * issued at most once per window so a seed that never converges cannot loop.
 */
class CoreThemeHealer
{
    /**
     * @param  array<string, mixed>  $summary  sanitized health summary
     * @return bool true when a restart was issued now
     */
    public function healFromHealth(Site $site, array $summary): bool
    {
        if (($summary['core_theme_in_sync'] ?? null) !== false) {
            return false;
        }

        if (! (bool) config('ops.agent.core_theme_auto_restart', true) || blank($site->coolify_app_uuid)) {
            return false;
        }

        $hours = max(1, (int) config('ops.agent.core_theme_restart_window_hours', 6));
        if (! Cache::add($this->throttleKey($site), true, now()->addHours($hours))) {
            return false;
        }

        $uuid = (string) $site->coolify_app_uuid;

        try {
            CoolifyApplicationService::forSite($site)->restartApplication($uuid);
        } catch (Throwable $exception) {
            // A health poll must not fail because Coolify is down or unconfigured.
            $site->auditLogs()->create([
                'actor_user_id' => null,
                'action' => 'site.core_theme_restart_failed',
                'after' => ['coolify_app_uuid' => $uuid, 'error' => $exception->getMessage()],
                'ip' => null,
            ]);

            return false;
        }

        $site->auditLogs()->create([
            'actor_user_id' => null,
            'action' => 'site.core_theme_restarted',
            'after' => ['coolify_app_uuid' => $uuid, 'reason' => 'core_theme_stale'],
            'ip' => null,
        ]);

        return true;
    }

    public function throttleKey(Site $site): string
    {
        return 'ops.core_theme_restart.'.$site->id;
    }
}
