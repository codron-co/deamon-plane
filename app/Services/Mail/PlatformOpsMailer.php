<?php

namespace App\Services\Mail;

use App\Mail\PlatformOpsMail;
use App\Mail\PlatformTestMail;
use App\Models\PlatformMailSetting;
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

        Mail::mailer('platform_ops')->to($to)->send(new PlatformTestMail(
            (string) $settings->from_address,
            (string) ($settings->from_name ?: $settings->from_address),
        ));
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

        $recipients = $this->recipients($site, $notificationKey);
        if ($recipients === []) {
            return false;
        }

        try {
            $this->applyRuntimeMailer($settings);

            foreach ($recipients as $recipient) {
                $mailSubject = sprintf('[%s] %s', $site->name, $subject);
                if ($recipient['user'] instanceof User) {
                    $unsubscribeUrl = app(PlatformMailUnsubscribe::class)->url($recipient['user'], $notificationKey);
                    Mail::mailer('platform_ops')
                        ->to($recipient['user'])
                        ->send((new PlatformOpsMail(
                            (string) $settings->from_address,
                            (string) ($settings->from_name ?: $settings->from_address),
                            $mailSubject,
                            $body,
                            $unsubscribeUrl,
                        ))->locale($recipient['user']->localeValue()));

                    continue;
                }

                Mail::mailer('platform_ops')->raw($body, function ($message) use ($recipient, $mailSubject, $settings): void {
                    $message->to($recipient['email'])
                        ->subject($mailSubject)
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
     * @return list<array{email: string, user: User|null}>
     */
    private function recipients(Site $site, string $notificationKey): array
    {
        $out = [];
        $primary = $this->resolver->recipientFor($site);
        if ($primary !== null) {
            $primaryUser = User::query()->where('email', $primary)->first();
            if (! $primaryUser?->hasMailOptOut($notificationKey)) {
                $out[strtolower($primary)] = ['email' => $primary, 'user' => $primaryUser];
            }
        }

        User::query()
            ->whereNotNull('email')
            ->orderBy('id')
            ->limit(20)
            ->get()
            ->each(function (User $user) use (&$out, $notificationKey): void {
                $email = trim((string) $user->email);
                $lookup = strtolower($email);
                if (
                    $email !== ''
                    && filter_var($email, FILTER_VALIDATE_EMAIL)
                    && ! $user->hasMailOptOut($notificationKey)
                    && ! array_key_exists($lookup, $out)
                ) {
                    $out[$lookup] = ['email' => $email, 'user' => $user];
                }
            });

        return array_values($out);
    }

    private function applyRuntimeMailer(PlatformMailSetting $settings): void
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
