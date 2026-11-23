<?php

namespace App\Services\Comail;

use App\Jobs\SendComailWelcomeMailJob;
use App\Mail\ComailWelcomeMail;
use App\Models\MailServer;
use App\Models\PlatformMailSetting;
use App\Models\SiteComailDomain;
use App\Services\Mail\PlatformNotificationCatalog;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Welcome mail for a newly created CoMail mailbox (docs/modules/comail.md, "Hoş geldin e-postası").
 *
 * Sent to the new mailbox itself from Plane's platform mail (noreply@codron.co over
 * CoMail SMTP), queued so creating the mailbox never waits on SMTP and never fails
 * because of it. It explains webmail, iPhone/iPad, Android and Outlook setup with the
 * real server settings. The password is never part of the mail.
 *
 * Switch: Platform mail -> Notifications -> "CoMail welcome mail" (key comail_welcome,
 * off by default; global only, not per site).
 */
final class ComailWelcome
{
    public const SUPPORT_EMAIL = 'support@codron.co';

    public const IMAP_PORT = 993;

    public const SMTP_PORT = 465;

    public const SMTP_ALT_PORT = 587;

    public const POP3_PORT = 995;

    public function __construct(private readonly ComailWebmailHost $webmailHost) {}

    public function enabled(): bool
    {
        $stored = PlatformMailSetting::current()->notifications;
        $row = is_array($stored) && is_array($stored[PlatformNotificationCatalog::COMAIL_WELCOME] ?? null)
            ? $stored[PlatformNotificationCatalog::COMAIL_WELCOME]
            : PlatformNotificationCatalog::defaultNotifications()[PlatformNotificationCatalog::COMAIL_WELCOME];

        return (bool) ($row['enabled'] ?? false);
    }

    /**
     * Queues the welcome mail when the switch is on and platform mail is ready.
     * Never throws: a failure here must not undo or fail the mailbox creation.
     */
    public function queue(string $address, ?string $name = null, bool $forcePasswordChange = false): bool
    {
        try {
            if (! $this->enabled()) {
                return false;
            }

            if (! PlatformMailSetting::current()->isReady()) {
                Log::info('comail.welcome_mail_skipped', ['address' => $address, 'reason' => 'platform_mail_not_ready']);

                return false;
            }

            SendComailWelcomeMailJob::dispatch(strtolower($address), $name, $forcePasswordChange)->afterCommit();

            return true;
        } catch (Throwable $exception) {
            Log::warning('comail.welcome_mail_queue_failed', [
                'address' => $address,
                'error' => mb_substr($exception->getMessage(), 0, 300),
            ]);

            return false;
        }
    }

    /**
     * Everything the templates need. `$assetBase` overrides the image host (preview only).
     *
     * @return array<string, mixed>
     */
    public function data(MailServer $server, string $address, ?string $name = null, bool $forcePasswordChange = false, ?string $assetBase = null): array
    {
        $address = strtolower(trim($address));
        $domain = ComailDirectory::domainOf($address);
        $host = $server->comailHostname();
        $base = rtrim($assetBase ?? (string) config('app.url'), '/');
        $asset = static fn (string $path): string => $base.'/'.ltrim($path, '/');

        $webmailUrl = $server->comailBaseUrl();
        try {
            $webmailUrl = $this->webmailHost->forDomain($server, $domain);
        } catch (Throwable) {
            // The base URL always works.
        }

        return [
            'address' => $address,
            'firstName' => self::firstName($name, $address),
            'host' => $host,
            'webmailUrl' => $webmailUrl,
            'autodiscover' => $this->autodiscover($domain),
            'forcePasswordChange' => $forcePasswordChange,
            'supportEmail' => self::SUPPORT_EMAIL,
            'ports' => [
                'imap' => self::IMAP_PORT,
                'smtp' => self::SMTP_PORT,
                'smtp_alt' => self::SMTP_ALT_PORT,
                'pop3' => self::POP3_PORT,
            ],
            'logoUrl' => $asset('brand/codron/logo.png'),
            'darkLogoUrl' => $asset('brand/codron/logo-white.png'),
            'heroUrl' => $asset('brand/mail/comail-welcome/hero.jpg'),
            'icons' => array_map(
                static fn (string $key): string => $asset('brand/mail/comail-welcome/icons/'.$key.'.png'),
                array_combine(self::ICONS, self::ICONS),
            ),
        ];
    }

    public static function subject(string $address): string
    {
        return 'E-posta hesabınız hazır: '.strtolower(trim($address));
    }

    public function mailable(MailServer $server, string $address, ?string $name, bool $forcePasswordChange, string $fromAddress, string $fromName, ?string $assetBase = null): ComailWelcomeMail
    {
        return new ComailWelcomeMail(
            $this->data($server, $address, $name, $forcePasswordChange, $assetBase),
            $fromAddress,
            $fromName,
        );
    }

    private const ICONS = ['web', 'iphone', 'android', 'outlook', 'sunucu', 'sorun'];

    /**
     * Outlook finds the settings itself when the last DNS check saw
     * autodiscover.<domain> (or the SRV record) pointing at CoMail.
     */
    private function autodiscover(string $domain): bool
    {
        $row = SiteComailDomain::query()->where('domain', $domain)->first();
        if (! $row instanceof SiteComailDomain || ! $row->mxActive()) {
            return false;
        }

        $records = is_array($row->dns_check['records'] ?? null) ? $row->dns_check['records'] : [];
        foreach ($records as $record) {
            if (is_array($record)
                && in_array($record['key'] ?? null, ['cname_autodiscover', 'srv_autodiscover'], true)
                && ($record['status'] ?? null) === 'ok') {
                return true;
            }
        }

        return false;
    }

    /**
     * A real first name only: mailcow fills `name` with the local part when none is given.
     */
    private static function firstName(?string $name, string $address): ?string
    {
        $name = trim((string) $name);
        $local = (string) strstr($address, '@', true);
        if ($name === '' || strcasecmp($name, $local) === 0 || filter_var($name, FILTER_VALIDATE_EMAIL)) {
            return null;
        }
        if (preg_match('/\b(info|bilgi|destek|support|satis|sales|muhasebe|admin|iletisim|contact|noreply)\b/iu', $name) === 1) {
            return null;
        }

        return explode(' ', $name)[0];
    }
}
