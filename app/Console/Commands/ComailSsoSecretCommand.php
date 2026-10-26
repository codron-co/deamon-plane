<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\MailServer;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Prints (or rotates) the webmail SSO secret of the CoMail server, for the
 * webmail server's configuration. The secret is stored encrypted and is never
 * shown in the panel.
 */
class ComailSsoSecretCommand extends Command
{
    protected $signature = 'ops:comail-sso-secret {--rotate : Generate a new secret (the webmail must be updated at once)}';

    protected $description = 'Show or rotate the CoMail webmail SSO secret (redeem signature key)';

    public function handle(): int
    {
        $server = MailServer::comail();
        if (! $server instanceof MailServer) {
            $this->error('No CoMail server is configured.');

            return self::FAILURE;
        }

        if ($this->option('rotate') || blank($server->sso_secret)) {
            $server->sso_secret = Str::random(48);
            $server->save();

            AuditLog::query()->create([
                'actor_user_id' => null,
                'action' => 'mail_server.sso_secret_rotated',
                'subject_type' => $server->getMorphClass(),
                'subject_id' => (string) $server->id,
                'after' => ['source' => 'console'],
            ]);
        }

        $this->line((string) $server->sso_secret);

        return self::SUCCESS;
    }
}
