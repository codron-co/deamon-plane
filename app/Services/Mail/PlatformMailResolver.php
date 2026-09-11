<?php

namespace App\Services\Mail;

use App\Models\PlatformMailSetting;
use App\Models\Site;

final class PlatformMailResolver
{
    public function settings(): PlatformMailSetting
    {
        return PlatformMailSetting::current();
    }

    /**
     * Effective notification bag for a site (global + per-site override).
     *
     * @return array<string, mixed>
     */
    public function notificationsFor(Site $site): array
    {
        $global = $this->settings()->notifications;
        if (! is_array($global) || $global === []) {
            $global = PlatformNotificationCatalog::defaultNotifications();
        } else {
            $global = array_replace_recursive(
                PlatformNotificationCatalog::defaultNotifications(),
                $global,
            );
        }

        $override = is_array($site->platform_notification_overrides)
            ? $site->platform_notification_overrides
            : [];

        foreach ($override as $key => $row) {
            if (! is_string($key) || ! is_array($row)) {
                continue;
            }
            $global[$key] = array_merge($global[$key] ?? ['enabled' => false], $row);
        }

        return $global;
    }

    public function notificationEnabled(Site $site, string $key): bool
    {
        $bag = $this->notificationsFor($site);
        $row = is_array($bag[$key] ?? null) ? $bag[$key] : [];

        return (bool) ($row['enabled'] ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function notification(Site $site, string $key): array
    {
        $bag = $this->notificationsFor($site);

        return is_array($bag[$key] ?? null) ? $bag[$key] : ['enabled' => false];
    }

    public function recipientFor(Site $site): ?string
    {
        $siteRecipient = is_string($site->platform_mail_recipient)
            ? trim($site->platform_mail_recipient)
            : '';
        if ($siteRecipient !== '' && filter_var($siteRecipient, FILTER_VALIDATE_EMAIL)) {
            return $siteRecipient;
        }

        $global = trim((string) ($this->settings()->default_admin_recipient ?? ''));
        if ($global !== '' && filter_var($global, FILTER_VALIDATE_EMAIL)) {
            return $global;
        }

        return null;
    }
}
