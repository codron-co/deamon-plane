<?php

namespace App\Services\Mail;

use App\Models\Site;
use App\Services\Agent\AgentHealthResult;
use App\Services\Agent\AgentHealthStatus;

final class SiteHealthMailNotifier
{
    public function __construct(
        private readonly PlatformOpsMailer $mailer,
        private readonly PlatformMailResolver $resolver,
    ) {}

    public function afterHealthCheck(Site $site, AgentHealthResult $result): void
    {
        $previous = (string) ($site->last_health_notify_status ?? '');
        $current = $result->ok ? AgentHealthStatus::Ok : AgentHealthStatus::Unhealthy;

        if ($previous === AgentHealthStatus::Ok && $current === AgentHealthStatus::Unhealthy) {
            $this->mailer->send(
                $site,
                PlatformNotificationCatalog::SITE_DOWN,
                'Siteniz düştü / sağlıksız',
                sprintf(
                    "%s (%s) agent health başarısız.\nDurum: %s\nSebep: %s\nPlane: %s",
                    $site->name,
                    $site->primary_domain,
                    $result->status,
                    (string) ($result->reason ?? $result->safeMessage),
                    route('ops.sites.show', $site),
                ),
            );
        }

        if ($previous === AgentHealthStatus::Unhealthy && $current === AgentHealthStatus::Ok) {
            $this->mailer->send(
                $site,
                PlatformNotificationCatalog::SITE_UP,
                'Siteniz tekrar sağlıklı',
                sprintf(
                    "%s (%s) agent health düzeldi.\nPlane: %s",
                    $site->name,
                    $site->primary_domain,
                    route('ops.sites.show', $site),
                ),
            );
        }

        $this->maybeNotifyVersion($site, $result);

        $site->last_health_notify_status = $current;
        $site->last_health_notify_at = now();
        $site->save();
    }

    private function maybeNotifyVersion(Site $site, AgentHealthResult $result): void
    {
        $version = $result->deamonVersion;
        if (! is_string($version) || $version === '') {
            return;
        }

        $previous = is_string($site->last_notified_deamon_version)
            ? $site->last_notified_deamon_version
            : null;

        if ($previous === null) {
            $site->last_notified_deamon_version = $version;

            return;
        }

        if ($previous === $version) {
            return;
        }

        $settings = $this->resolver->notification($site, PlatformNotificationCatalog::SITE_VERSION_UPDATE);
        if (! (bool) ($settings['enabled'] ?? false)) {
            $site->last_notified_deamon_version = $version;

            return;
        }

        $on = (string) ($settings['on'] ?? 'patch');
        if (! $this->versionChangeMatches($previous, $version, $on)) {
            $site->last_notified_deamon_version = $version;

            return;
        }

        $this->mailer->send(
            $site,
            PlatformNotificationCatalog::SITE_VERSION_UPDATE,
            'Siteniz güncellendi',
            sprintf(
                "%s Deamon sürümü değişti: %s → %s\nPlane: %s",
                $site->name,
                $previous,
                $version,
                route('ops.sites.show', $site),
            ),
        );

        $site->last_notified_deamon_version = $version;
    }

    private function versionChangeMatches(string $from, string $to, string $on): bool
    {
        $a = $this->parseSemver($from);
        $b = $this->parseSemver($to);
        if ($a === null || $b === null) {
            return true;
        }

        return match ($on) {
            'major' => $a[0] !== $b[0],
            'minor' => $a[0] !== $b[0] || $a[1] !== $b[1],
            default => $a !== $b,
        };
    }

    /**
     * @return array{0: int, 1: int, 2: int}|null
     */
    private function parseSemver(string $version): ?array
    {
        if (preg_match('/\A(\d+)\.(\d+)\.(\d+)/', $version, $m) !== 1) {
            return null;
        }

        return [(int) $m[1], (int) $m[2], (int) $m[3]];
    }
}
