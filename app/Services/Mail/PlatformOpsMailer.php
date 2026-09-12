<?php

namespace App\Services\Mail;

use App\Mail\PlatformTestMail;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

final class PlatformOpsMailer
{
    public function __construct(
        private readonly PlatformMailResolver $resolver,
    ) {}

    public function sendTest(string $to): void
    {
        $settings = $this->resolver->settings();
        $this->applyRuntimeMailer($settings);

        Mail::mailer('platform_ops')->to($to)->send(new PlatformTestMail);
    }

    public function send(Site $site, string $notificationKey, string $subject, string $body): bool
    {
        if (! $this->resolver->notificationEnabled($site, $notificationKey)) {
            return false;
        }

        $settings = $this->resolver->settings();
        if (! $settings->isReady()) {
            return false;
        }

        $recipients = $this->recipients($site);
        if ($recipients === []) {
            return false;
        }

        try {
            $this->applyRuntimeMailer($settings);

            foreach ($recipients as $recipient) {
                Mail::mailer('platform_ops')->raw($body, function ($message) use ($recipient, $subject, $settings, $site): void {
                    $message->to($recipient)
                        ->subject(sprintf('[%s] %s', $site->name, $subject))
                        ->from(
                            (string) $settings->from_address,
                            (string) ($settings->from_name ?: $settings->from_address),
                        );
                });
            }

            return true;
        } catch (Throwable $exception) {
            Log::warning('Platform ops mail failed', [
                'site_id' => $site->id,
                'notification' => $notificationKey,
                'message' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * @return list<string>
     */
    private function recipients(Site $site): array
    {
        $out = [];
        $primary = $this->resolver->recipientFor($site);
        if ($primary !== null) {
            $out[] = $primary;
        }

        User::query()
            ->whereNotNull('email')
            ->orderBy('id')
            ->limit(20)
            ->pluck('email')
            ->each(function (mixed $email) use (&$out): void {
                $email = is_string($email) ? trim($email) : '';
                if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) && ! in_array($email, $out, true)) {
                    $out[] = $email;
                }
            });

        return $out;
    }

    private function applyRuntimeMailer(\App\Models\PlatformMailSetting $settings): void
    {
        $encryption = strtolower((string) ($settings->encryption ?: 'ssl'));
        $scheme = in_array($encryption, ['ssl', 'smtps'], true) ? 'smtps' : null;

        Config::set('mail.mailers.platform_ops', array_filter([
            'transport' => config('mail.default') === 'array' ? 'array' : 'smtp',
            'scheme' => $scheme,
            'host' => $settings->host,
            'port' => (int) $settings->port,
            'username' => $settings->username,
            'password' => $settings->password,
            'timeout' => 30,
        ], static fn ($value) => $value !== null && $value !== ''));
    }
}
