<?php

namespace App\Jobs;

use App\Models\MailServer;
use App\Models\PlatformMailSetting;
use App\Services\Comail\ComailWelcome;
use App\Services\Mail\PlatformOpsMailer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Sends the CoMail welcome mail to a new mailbox (ComailWelcome). Retries SMTP
 * errors with backoff; the last failure is only logged (the mailbox exists either way).
 * Carries no password: the mail never contains one.
 */
class SendComailWelcomeMailJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(
        public readonly string $address,
        public readonly ?string $name = null,
        public readonly bool $forcePasswordChange = false,
    ) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(ComailWelcome $welcome, PlatformOpsMailer $mailer): void
    {
        $server = MailServer::comail();
        $settings = PlatformMailSetting::current();
        if (! $server instanceof MailServer || ! $settings->isReady()) {
            Log::info('comail.welcome_mail_skipped', ['address' => $this->address, 'reason' => 'not_configured']);

            return;
        }

        $mailer->useRuntimeMailer($settings);

        try {
            Mail::mailer('platform_ops')->to($this->address)->send($welcome->mailable(
                $server,
                $this->address,
                $this->name,
                $this->forcePasswordChange,
                (string) $settings->from_address,
                'CodRon',
            ));
        } catch (Throwable $exception) {
            Log::warning('comail.welcome_mail_failed', [
                'address' => $this->address,
                'attempt' => $this->attempts(),
                'error' => mb_substr($mailer->redact($exception), 0, 300),
            ]);

            throw $exception;
        }

        Log::info('comail.welcome_mail_sent', ['address' => $this->address]);
    }
}
