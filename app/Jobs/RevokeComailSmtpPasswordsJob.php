<?php

namespace App\Jobs;

use App\Models\AuditLog;
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
use Throwable;

/**
 * Deletes one site's site-SMTP app passwords (ComailSmtpCredentials names,
 * matched by site id) on every mailbox of the given domains. Used when the
 * site loses access to those mailboxes: it leaves a share group (sibling
 * domains), it is deactivated or purged. Takes plain values, so it also works
 * after the site row is gone.
 *
 * A domain that fails is retried (the whole job is idempotent): the job throws
 * after trying every domain and runs again with backoff; after the last try
 * `failed()` logs an error and writes a `comail.smtp_revoke_failed` audit row
 * (Plane activity) so ops can delete the passwords in mailcow by hand.
 */
class RevokeComailSmtpPasswordsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public int $timeout = 120;

    /**
     * @param  list<string>  $domains
     */
    public function __construct(
        public readonly string $siteId,
        public readonly array $domains,
        public readonly string $reason,
    ) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    /**
     * Every domain the site reaches (own + share group), whatever their status.
     */
    public static function dispatchForSite(Site $site, string $reason): void
    {
        self::dispatchFor($site, self::domainsOf(ComailDirectory::groupSiblings($site)->prepend($site)), $reason);
    }

    /**
     * Purge: the site's passwords everywhere it reaches, and the other group
     * members' passwords on the site's own domains.
     */
    public static function dispatchForPurge(Site $site): void
    {
        self::dispatchForSite($site, 'site_purged');

        $own = self::domainsOf([$site]);
        foreach (ComailDirectory::groupSiblings($site) as $sibling) {
            self::dispatchFor($sibling, $own, 'share_member_purged');
        }
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

        try {
            self::dispatch((string) $site->id, $list, $reason);
        } catch (Throwable $exception) {
            // Never break the purge / deactivate / group change that asked for it (sync queue).
            report($exception);
        }
    }

    /**
     * CoMail domains of the given sites, any status (pending/suspended included).
     *
     * @param  iterable<int, Site>  $sites
     * @return list<string>
     */
    public static function domainsOf(iterable $sites): array
    {
        $ids = [];
        foreach ($sites as $site) {
            $ids[] = (string) $site->id;
        }

        return $ids === [] ? [] : SiteComailDomain::query()
            ->whereIn('site_id', $ids)
            ->pluck('domain')
            ->map(static fn (mixed $domain): string => strtolower((string) $domain))
            ->unique()
            ->values()
            ->all();
    }

    public function handle(ComailSmtpCredentials $credentials): void
    {
        $server = MailServer::comail();
        if (! $server instanceof MailServer) {
            return;
        }

        $client = MailcowClient::forServer($server);
        $revoked = 0;
        $failure = null;
        foreach ($this->domains as $domain) {
            try {
                foreach ($client->mailboxes($domain) as $mailbox) {
                    $address = strtolower((string) ($mailbox['username'] ?? ''));
                    if ($address !== '' && ComailDirectory::domainOf($address) === $domain) {
                        $revoked += $credentials->revokeOwned($client, $address, $this->siteId);
                    }
                }
            } catch (MailcowException $exception) {
                $failure = $exception;
                Log::warning('comail.smtp_revoke_failed', [
                    'site_id' => $this->siteId,
                    'domain' => $domain,
                    'reason' => $this->reason,
                    'attempt' => $this->attempts(),
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

        // Retry the job (idempotent) until every domain went through.
        if ($failure !== null) {
            throw $failure;
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('comail.smtp_revoke_gave_up', [
            'site_id' => $this->siteId,
            'domains' => $this->domains,
            'reason' => $this->reason,
            'error' => $exception instanceof MailcowException ? ($exception->providerKey ?? $exception->error) : ($exception ? $exception::class : null),
        ]);

        AuditLog::query()->create([
            'actor_user_id' => null,
            'action' => 'comail.smtp_revoke_failed',
            'subject_type' => (new Site)->getMorphClass(),
            'subject_id' => $this->siteId,
            'after' => [
                'domains' => $this->domains,
                'reason' => $this->reason,
                'note' => 'Site SMTP app passwords (name contains the site id) must be deleted in mailcow by hand.',
            ],
            'ip' => null,
        ]);
    }
}
