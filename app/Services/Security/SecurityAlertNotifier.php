<?php

namespace App\Services\Security;

use App\Models\FleetAllowlistEntry;
use App\Models\Site;
use App\Models\SiteSecurityEvent;
use App\Services\Mail\PlatformNotificationCatalog;
use App\Services\Mail\PlatformOpsMailer;
use Illuminate\Support\Facades\Cache;

/**
 * Mail to the super admins (the only alert channel, design §13) for two things:
 * a critical event on a site, and one IP probing several sites. Both are
 * suppressed per rule+IP (critical) or per IP (cross-site) for a window, and one
 * collection run sends at most one mail however many things it found.
 */
class SecurityAlertNotifier
{
    public function __construct(private readonly PlatformOpsMailer $mailer) {}

    /**
     * @param  list<array<string, mixed>>  $newEvents  sanitized rows just stored for the site
     * @return int mails queued (recipients)
     */
    public function handle(Site $site, array $newEvents): int
    {
        if ($newEvents === []) {
            return 0;
        }

        $lines = [...$this->criticalLines($site, $newEvents), ...$this->crossSiteLines($site, $newEvents)];
        if ($lines === []) {
            return 0;
        }

        $subject = (string) __('security.mail.subject', ['site' => $site->name, 'count' => count($lines)]);
        $body = (string) __('security.mail.intro', ['site' => $site->name])."\n\n".implode("\n", $lines)
            ."\n\n".(string) __('security.mail.link', ['url' => route('ops.security')]);

        return $this->mailer->sendToSuperAdmins(PlatformNotificationCatalog::SECURITY_ALERT, $subject, $body, $site);
    }

    /**
     * @param  list<array<string, mixed>>  $events
     * @return list<string>
     */
    private function criticalLines(Site $site, array $events): array
    {
        $window = max(1, (int) config('ops.security.alert_window_minutes', 30));
        $lines = [];

        foreach ($events as $event) {
            if ((int) $event['severity'] < SiteSecurityEvent::SEVERITY_CRITICAL) {
                continue;
            }
            $key = 'security-alert:critical:'.$site->id.':'.$event['rule'].':'.$event['ip'];
            if (! Cache::add($key, 1, now()->addMinutes($window))) {
                continue;
            }
            $lines[] = (string) __('security.mail.critical_line', [
                'rule' => $event['rule'],
                'ip' => $event['ip'],
                'path' => $event['path'] ?? '-',
                'count' => $event['count'],
            ]);
        }

        return $lines;
    }

    /**
     * @param  list<array<string, mixed>>  $events
     * @return list<string>
     */
    private function crossSiteLines(Site $site, array $events): array
    {
        $min = max(2, (int) config('ops.security.cross_site_min_sites', 2));
        $window = max(1, (int) config('ops.security.cross_site_window_minutes', 360));
        $lines = [];

        $ips = collect($events)
            ->filter(fn (array $event): bool => (int) $event['severity'] >= SiteSecurityEvent::SEVERITY_WARNING)
            ->pluck('ip')
            ->unique()
            ->values();

        foreach ($ips as $ip) {
            // Plane's own egress, monitoring, the team: on the allowlist, never an alert.
            if (FleetAllowlistEntry::query()->active()->where('ip', $ip)->exists()) {
                continue;
            }

            $sites = SiteSecurityEvent::query()
                ->where('ip', $ip)
                ->where('severity', '>=', SiteSecurityEvent::SEVERITY_WARNING)
                ->where('occurred_at', '>=', now()->subDay())
                ->distinct()
                ->pluck('site_id');

            if ($sites->count() < $min) {
                continue;
            }

            if (! Cache::add('security-alert:xsite:'.$ip, 1, now()->addMinutes($window))) {
                continue;
            }

            $names = Site::query()->whereIn('id', $sites)->orderBy('name')->limit(8)->pluck('name')->implode(', ');
            $lines[] = (string) __('security.mail.cross_site_line', [
                'ip' => $ip,
                'count' => $sites->count(),
                'sites' => $names,
            ]);
        }

        return $lines;
    }
}
