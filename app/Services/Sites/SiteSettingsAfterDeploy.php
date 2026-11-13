<?php

namespace App\Services\Sites;

use App\Jobs\ConfigureSiteMailJob;
use App\Jobs\PushPlatformMailJob;
use App\Models\PlatformMailSetting;
use App\Models\Site;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Agent pushes a new CMS still needs once its container answers.
 *
 * The agent secret is written before the first build, but the CMS only exists
 * after the provision deploy finishes. Settings Plane pushes over the signed
 * agent therefore have to wait for that moment: pushed at provision start they
 * hit a site that is not up yet, and pushes without an hourly retry stayed
 * unconfigured for good (2026-10-27: four new sites never got the platform SMTP,
 * so password reset, admin invites, weekly reports and bulletins did not send).
 *
 * DeskRon, Turnstile and CoMail have their own delayed push and hourly reconcile;
 * Search Console / analytics and Instagram are pushed when an operator sets them.
 * Here: the platform SMTP and, when the site has a mail server, its own SMTP.
 * Each only when the site has not accepted it yet, so a later deploy pushes nothing.
 * ReconcileMailPushJob catches anything this first attempt misses.
 */
class SiteSettingsAfterDeploy
{
    public function dispatch(Site $site): void
    {
        try {
            $delay = now()->addSeconds(max(0, (int) config('ops.provision.settings_push_delay_seconds', 60)));

            if (self::needsPlatformMail($site)) {
                PushPlatformMailJob::dispatch((string) $site->id)->delay($delay);
            }

            if (self::needsSiteMail($site)) {
                ConfigureSiteMailJob::dispatch((string) $site->id)->delay($delay);
            }
        } catch (Throwable $exception) {
            // A settings push never fails the deploy that triggered it.
            Log::warning('site.settings_push_dispatch_failed', [
                'site_id' => $site->id,
                'site_slug' => $site->slug,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * The platform SMTP is ready in Plane and this site never accepted it, or
     * its last push failed.
     */
    public static function needsPlatformMail(Site $site): bool
    {
        if (! $site->hasAgentSecret()) {
            return false;
        }

        $settings = PlatformMailSetting::current();
        if (! $settings->exists || ! $settings->isReady()) {
            return false;
        }

        return self::pending($site->platform_mail_pushed_at, $site->platform_mail_push_failed_at);
    }

    /**
     * The site has a mail server and its SMTP never reached the CMS, or the last
     * attempt failed.
     */
    public static function needsSiteMail(Site $site): bool
    {
        if (! $site->hasAgentSecret() || blank($site->mail_server_id)) {
            return false;
        }

        return self::pending($site->mail_configured_at, $site->mail_configure_failed_at);
    }

    private static function pending(mixed $pushedAt, mixed $failedAt): bool
    {
        if ($pushedAt === null) {
            return true;
        }

        return $failedAt !== null && $failedAt > $pushedAt;
    }
}
