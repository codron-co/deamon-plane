<?php

namespace App\Console\Commands;

use App\Models\MailServer;
use App\Models\PlatformMailSetting;
use App\Services\Comail\ComailWelcome;
use App\Services\Mail\PlatformOpsMailer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Preview or test the CoMail welcome mail (docs/modules/comail.md, "Hoş geldin e-postası").
 * Works while the switch is off. Never sends to the mailbox itself; only --to.
 *
 *   comail:welcome-mail ali@acme.com.tr --dry-run          HTML + text into storage, prints the paths
 *   comail:welcome-mail ali@acme.com.tr --to=me@codron.co  sends one test copy to --to
 */
class ComailWelcomeMailCommand extends Command
{
    protected $signature = 'comail:welcome-mail
        {address : mailbox the mail is written for}
        {--to= : send one test copy to this address}
        {--name= : first name shown in the greeting}
        {--force-password-change : show the "new password on first login" note}
        {--dry-run : write HTML and text to storage/app/comail-welcome and print the paths}
        {--asset-base= : image host override (preview only, e.g. file:///C:/.../public)}';

    protected $description = 'Preview (--dry-run) or test-send (--to) the CoMail welcome mail for a mailbox';

    public function handle(ComailWelcome $welcome, PlatformOpsMailer $mailer): int
    {
        $address = strtolower(trim((string) $this->argument('address')));
        if (! filter_var($address, FILTER_VALIDATE_EMAIL)) {
            $this->error('Not an e-mail address: '.$address);

            return self::INVALID;
        }

        $server = MailServer::comail() ?? new MailServer([
            'base_url' => 'https://mail.codron.co',
            'mail_hostname' => 'mail.codron.co',
        ]);
        $settings = PlatformMailSetting::current();
        $mail = $welcome->mailable(
            $server,
            $address,
            $this->option('name') ? (string) $this->option('name') : null,
            (bool) $this->option('force-password-change'),
            (string) ($settings->from_address ?: 'noreply@codron.co'),
            'CodRon',
            $this->option('asset-base') ? (string) $this->option('asset-base') : null,
        );

        $to = trim((string) $this->option('to'));
        if ($this->option('dry-run') || $to === '') {
            $dir = storage_path('app/comail-welcome');
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            $slug = preg_replace('/[^a-z0-9]+/', '-', $address);
            $html = $dir.DIRECTORY_SEPARATOR.$slug.'.html';
            $text = $dir.DIRECTORY_SEPARATOR.$slug.'.txt';
            file_put_contents($html, $mail->render());
            file_put_contents($text, view('mail.comail-welcome.text', ['w' => $mail->welcome])->render());
            $this->line('Subject: '.ComailWelcome::subject($address));
            $this->line('HTML: '.$html);
            $this->line('Text: '.$text);

            return self::SUCCESS;
        }

        if (! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $this->error('--to is not an e-mail address.');

            return self::INVALID;
        }
        if (! $settings->isReady()) {
            $this->error('Platform mail is not configured (Settings -> Platform mail).');

            return self::FAILURE;
        }

        $mailer->useRuntimeMailer($settings);
        try {
            Mail::mailer('platform_ops')->to($to)->send($mail);
        } catch (Throwable $exception) {
            $this->error('Send failed: '.$mailer->redact($exception));

            return self::FAILURE;
        }

        $this->info('Sent to '.$to.' (written for '.$address.').');

        return self::SUCCESS;
    }
}
