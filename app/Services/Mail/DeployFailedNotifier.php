<?php

namespace App\Services\Mail;

use App\Models\Deployment;
use App\Models\Site;
use Throwable;

/**
 * The one "deploy failed" alert, for every path that fails a deployment:
 * Coolify status sync, provisioning and channel switch (audit S7-B05).
 */
final class DeployFailedNotifier
{
    public function __construct(
        private readonly PlatformOpsMailer $mailer,
    ) {}

    public function notify(Site $site, Deployment $deployment): void
    {
        try {
            $this->mailer->send(
                $site,
                PlatformNotificationCatalog::DEPLOY_FAILED,
                'Deploy başarısız',
                sprintf(
                    "%s deploy failed.\n%s\nPlane: %s",
                    $site->name,
                    (string) ($deployment->error_message ?: 'Coolify deployment failed.'),
                    route('ops.sites.show', $site),
                ),
            );
        } catch (Throwable) {
            // An alert must never break the deploy path that raised it.
        }
    }
}
