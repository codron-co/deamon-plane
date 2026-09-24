<?php

namespace App\Services\Mail;

use App\Jobs\SendOpsNotificationJob;
use App\Mail\PlatformOpsMail;
use App\Mail\PlatformTestMail;
use App\Models\OpsNotification;
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

    /**
     * Queues the alert: one ops_notifications row and one job per recipient.
     * Returns true when at least one delivery was queued. Delivery itself runs
     * in SendOpsNotificationJob, which retries, so a slow or failing SMTP never
     * drops the alert and one bad address never blocks the others.
     */
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

        $mailSubject = sprintf('[%s] %s', $site->name, $subject);

        foreach ($recipients as $recipient) {
            $notification = OpsNotification::query()->create([
                'site_id' => $site->id,
                'notification_key' => $notificationKey,
                'user_id' => $recipient['user']?->id,
                'recipient' => $recipient['email'],
                'subject' => mb_substr($mailSubject, 0, 255),
                'body' => $body,
                'status' => OpsNotification::STATUS_PENDING,
            ]);

            SendOpsNotificationJob::dispatch($notification->id)->afterCommit();
        }

        return true;
    }

    /**
     * Sends one queued notification. Throws on transport errors so the job can
     * retry; the caller records the redacted message.
     */
    public function deliver(OpsNotification $notification): void
    {
        $settings = $this->resolver->settings();
        if (! $settings->isReady()) {
            throw new PlatformMailNotReadyException('Platform mail is not configured.');
        }

        $this->applyRuntimeMailer($settings);
        $fromAddress = (string) $settings->from_address;
        $fromName = (string) ($settings->from_name ?: $settings->from_address);
        $user = $notification->user;

        if ($user instanceof User) {
            $unsubscribeUrl = app(PlatformMailUnsubscribe::class)->url($user, $notification->notification_key);
            Mail::mailer('platform_ops')
                ->to($user)
                ->send((new PlatformOpsMail(
                    $fromAddress,
                    $fromName,
                    $notification->subject,
                    $notification->body,
                    $unsubscribeUrl,
                ))->locale($user->localeValue()));

            return;
        }

        Mail::mailer('platform_ops')->raw($notification->body, function ($message) use ($notification, $fromAddress, $fromName): void {
            $message->to($notification->recipient)
                ->subject($notification->subject)
                ->from($fromAddress, $fromName);
        });
    }

    public function safeError(Throwable $exception): string
    {
        $message = $this->redactTransportExceptionMessage($this->resolver->settings(), $exception);

        Log::warning('Platform ops mail failed', ['message' => $message]);

        return $message;
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

    private function redactTransportExceptionMessage(PlatformMailSetting $settings, Throwable $exception): string
    {
        $message = $exception->getMessage();
        $password = (string) ($settings->password ?? '');
        if ($password === '') {
            return $message;
        }

        return str_replace($password, '[redacted]', $message);
    }

    private function applyRuntimeMailer(PlatformMailSetting $settings): void
    {
        // A long-lived worker keeps the resolved mailer; drop it so a changed
        // SMTP host or password is used from the next send on.
        Mail::purge('platform_ops');

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
