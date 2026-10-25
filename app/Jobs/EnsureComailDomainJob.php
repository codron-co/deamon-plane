<?php

namespace App\Jobs;

use App\Models\MailServer;
use App\Models\Site;
use App\Models\SiteComailDomain;
use App\Services\Comail\ComailDns;
use App\Services\Comail\ComailDomainProvisioner;
use App\Services\Comail\ComailException;
use App\Services\Comail\ComailSiteConfigurer;
use App\Services\Comail\MailcowException;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Enrolls a site domain in CoMail: mailcow domain with package limits + DKIM,
 * then DNS (Cloudflare zones Plane manages; MX only on a fresh domain), then
 * the CMS configure push. Idempotent; dispatched on site create and on
 * provision success, and by `ops:comail-enroll`.
 */
class EnsureComailDomainJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public int $uniqueFor = 300;

    public function __construct(
        public readonly string $siteId,
        public readonly string $domain,
        public readonly bool $primary = true,
    ) {}

    public function uniqueId(): string
    {
        return $this->siteId.':'.strtolower($this->domain);
    }

    /**
     * Queue the primary domain of a site when CoMail is on and the host can take mail.
     */
    public static function dispatchForPrimary(Site $site): bool
    {
        if (! MailServer::comail() instanceof MailServer) {
            return false;
        }

        $domain = ComailDomainProvisioner::primaryHost($site);
        if ($domain === null) {
            return false;
        }

        // Another site already owns it: never steal a domain.
        $owner = SiteComailDomain::query()->where('domain', $domain)->value('site_id');
        if ($owner !== null && (string) $owner !== (string) $site->id) {
            return false;
        }

        self::dispatch((string) $site->id, $domain, true);

        return true;
    }

    public function handle(ComailDomainProvisioner $provisioner, ComailDns $dns, ComailSiteConfigurer $configurer): void
    {
        $site = Site::query()->find($this->siteId);
        if (! $site instanceof Site || ! MailServer::comail() instanceof MailServer) {
            return;
        }

        try {
            $row = $provisioner->ensure($site, $this->domain, $this->primary);
        } catch (ComailException|MailcowException $exception) {
            Log::warning('comail.enroll_failed', [
                'site_id' => $site->id,
                'domain' => $this->domain,
                'error' => $exception instanceof MailcowException ? ($exception->providerKey ?? $exception->error) : $exception->error,
            ]);

            return;
        }

        try {
            $dns->applyIfManaged($row);
        } catch (Throwable $exception) {
            // DNS is retried from the panel ("DNS uygula"); the domain itself is ready.
            Log::warning('comail.enroll_dns_failed', [
                'site_id' => $site->id,
                'domain' => $row->domain,
                'error' => $exception->getMessage(),
            ]);
            $row->forceFill(['last_error' => mb_substr($exception->getMessage(), 0, 500)])->save();
        }

        $site->auditLogs()->create([
            'actor_user_id' => null,
            'action' => 'comail.domain_enrolled',
            'after' => [
                'domain' => $row->domain,
                'is_primary' => $row->is_primary,
                'status' => $row->status,
            ],
        ]);

        if ($site->hasAgentSecret()) {
            $configurer->queue($site);
        }
    }
}
