<?php

namespace App\Jobs;

use App\Models\MailServer;
use App\Models\Site;
use App\Models\SiteComailDomain;
use App\Services\Comail\ComailDirectory;
use App\Services\Comail\ComailSmtpCredentials;
use App\Services\Comail\MailcowClient;
use App\Services\Comail\MailcowException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Deletes one site's site-SMTP app passwords (ComailSmtpCredentials names) on
 * every mailbox of the given domains. Used when the site loses access to
 * those mailboxes: it leaves a share group (sibling domains), it is archived
 * or purged (all reachable domains). Takes plain values, so it also works
 * after the site row is gone. Failures are logged, never thrown.
 */
class RevokeComailSmtpPasswordsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    /**
     * @param  list<string>  $domains
     */
    public function __construct(
        public readonly string $siteId,
        public readonly string $legacyName,
        public readonly array $domains,
        public readonly string $reason,
    ) {}

    /**
     * Every domain the site reaches now (own + share group), for archive/purge.
     */
    public static function dispatchForSite(Site $site, string $reason): void
    {
        $domains = $site->comailDomains()->pluck('domain')->all();
        foreach (ComailDirectory::groupSiblings($site) as $sibling) {
            $domains = array_merge($domains, $sibling->comailDomains()->pluck('domain')->all());
        }

        self::dispatchFor($site, $domains, $reason);
    }

    /**
     * @param  iterable<int, string>  $domains
     */
    public static function dispatchFor(Site $site, iterable $domains, string $reason): void
    {
        $list = [];
        foreach ($domains as $domain) {
            $domain = strtolower(trim((string) $domain));
            if ($domain !== '' && ! in_array($domain, $list, true)) {
                $list[] = $domain;
            }
        }

        if ($list === [] || ! MailServer::comail() instanceof MailServer) {
            return;
        }

        self::dispatch((string) $site->id, ComailSmtpCredentials::legacyName($site), $list, $reason);
    }

    public function handle(ComailSmtpCredentials $credentials): void
    {
        $server = MailServer::comail();
        if (! $server instanceof MailServer) {
            return;
        }

        $client = MailcowClient::forServer($server);
        $revoked = 0;
        foreach ($this->domains as $domain) {
            try {
                foreach ($client->mailboxes($domain) as $mailbox) {
                    $address = strtolower((string) ($mailbox['username'] ?? ''));
                    if ($address !== '' && ComailDirectory::domainOf($address) === $domain) {
                        $revoked += $credentials->revokeOwned($client, $address, $this->siteId, $this->legacyName);
                    }
                }
            } catch (MailcowException $exception) {
                Log::warning('comail.smtp_revoke_failed', [
                    'site_id' => $this->siteId,
                    'domain' => $domain,
                    'reason' => $this->reason,
                    'error' => $exception->providerKey ?? $exception->error,
                ]);
            }
        }

        if ($revoked > 0) {
            Log::info('comail.smtp_passwords_revoked', [
                'site_id' => $this->siteId,
                'domains' => $this->domains,
                'reason' => $this->reason,
                'revoked' => $revoked,
            ]);
        }
    }

    /**
     * Domains of the given sites that are active in CoMail.
     *
     * @param  iterable<int, Site>  $sites
     * @return list<string>
     */
    public static function activeDomains(iterable $sites): array
    {
        $ids = [];
        foreach ($sites as $site) {
            $ids[] = (string) $site->id;
        }

        return $ids === [] ? [] : SiteComailDomain::query()
            ->whereIn('site_id', $ids)
            ->where('status', SiteComailDomain::STATUS_ACTIVE)
            ->pluck('domain')
            ->map(static fn (mixed $domain): string => strtolower((string) $domain))
            ->all();
    }
}
