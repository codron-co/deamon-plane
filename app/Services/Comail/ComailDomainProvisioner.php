<?php

namespace App\Services\Comail;

use App\Models\MailServer;
use App\Models\Site;
use App\Models\SiteComailDomain;
use App\Models\SiteDomain;
use App\Services\Cloudflare\CloudflareAccounts;
use App\Services\Cloudflare\CloudflareHostname;
use Illuminate\Support\Facades\Log;

/**
 * Makes a site domain a CoMail domain: the mailcow domain with the package
 * limits, its DKIM key (selector `dkim`, 2048) and the Plane row. Idempotent.
 */
class ComailDomainProvisioner
{
    public function __construct(
        private readonly ComailLimits $limits,
    ) {}

    /**
     * Mail-capable hosts of the site: non-temporary, not www, not a preview host
     * under the Plane wildcard domain. Primary first.
     *
     * @return list<string>
     */
    public static function eligibleHosts(Site $site): array
    {
        $rows = $site->domains()->get();
        $temporary = $rows->where('is_temporary', true)->pluck('domain')
            ->map(fn (mixed $host): string => CloudflareHostname::host((string) $host))
            ->all();
        if (filled($site->temporary_domain)) {
            $temporary[] = CloudflareHostname::host((string) $site->temporary_domain);
        }

        $wildcards = [];
        foreach (CloudflareAccounts::enabled() as $account) {
            $wildcards[] = $account->resolvedWildcardDomain();
        }
        $wildcards[] = CloudflareHostname::normalize((string) config('ops.cloudflare.wildcard_domain', 'codron.co'));
        $wildcards = array_values(array_unique(array_filter($wildcards)));

        $out = [];
        foreach ($site->operatorHosts() as $host) {
            $host = strtolower($host);
            if ($host === '' || in_array($host, $temporary, true) || str_starts_with($host, 'www.')) {
                continue;
            }
            $row = $rows->firstWhere('domain', $host);
            if ($row instanceof SiteDomain && $row->is_www) {
                continue;
            }
            foreach ($wildcards as $wildcard) {
                if ($host === $wildcard || str_ends_with($host, '.'.$wildcard)) {
                    continue 2;
                }
            }
            if (preg_match('/\A(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}\z/', $host) !== 1) {
                continue;
            }
            $out[] = $host;
        }

        return array_values(array_unique($out));
    }

    public static function primaryHost(Site $site): ?string
    {
        $primary = CloudflareHostname::normalize((string) $site->primary_domain);
        $eligible = self::eligibleHosts($site);

        return in_array($primary, $eligible, true) ? $primary : null;
    }

    /**
     * Create (or re-sync) the mailcow domain and DKIM; returns the stored row.
     */
    public function ensure(Site $site, string $domain, bool $primary): SiteComailDomain
    {
        $domain = strtolower(trim($domain));
        $server = MailServer::comail();
        if (! $server instanceof MailServer) {
            throw ComailException::notConfigured();
        }

        $row = SiteComailDomain::query()->where('domain', $domain)->first();
        if ($row instanceof SiteComailDomain && (string) $row->site_id !== (string) $site->id) {
            throw ComailException::domainNotOwned();
        }

        if (! $row instanceof SiteComailDomain) {
            $row = new SiteComailDomain([
                'site_id' => $site->id,
                'domain' => $domain,
                'is_primary' => $primary,
                'status' => SiteComailDomain::STATUS_PENDING,
            ]);
            $row->save();
        } elseif ($primary && ! $row->is_primary) {
            $row->is_primary = true;
            $row->save();
        }

        $client = MailcowClient::forServer($server);

        try {
            $attributes = $this->limits->domainAttributes($this->limits->package($site));
            if ($client->domain($domain) === null) {
                // add/domain also creates the DKIM key (selector dkim, 2048) and
                // answers with two success elements (dkim_added, domain_added).
                $client->addDomain($domain, $attributes + [
                    'description' => mb_substr('Deamon '.$site->slug, 0, 255),
                    'active' => 1,
                    'rl_value' => '',
                    'rl_frame' => 's',
                    'backupmx' => 0,
                    'relay_all_recipients' => 0,
                    'restart_sogo' => 1,
                ]);
            } else {
                $client->editDomain($domain, $attributes + ['active' => 1]);
            }

            $dkim = $this->ensureDkim($client, $domain);
        } catch (MailcowException $exception) {
            $row->forceFill([
                'status' => $row->mailcow_synced_at !== null ? $row->status : SiteComailDomain::STATUS_FAILED,
                'last_error' => mb_substr($exception->getMessage(), 0, 500),
            ])->save();

            throw $exception;
        }

        $row->forceFill([
            'status' => SiteComailDomain::STATUS_ACTIVE,
            'mailcow_synced_at' => now(),
            'dkim_selector' => is_string($dkim['dkim_selector'] ?? null) && $dkim['dkim_selector'] !== ''
                ? $dkim['dkim_selector']
                : (string) config('ops.comail.dkim_selector', 'dkim'),
            'dkim_txt' => is_string($dkim['dkim_txt'] ?? null) ? trim($dkim['dkim_txt']) : $row->dkim_txt,
            'last_error' => null,
        ])->save();

        return $row;
    }

    /**
     * Re-apply the package limits to every CoMail domain of the site.
     */
    public function applyLimits(Site $site): void
    {
        $server = MailServer::comail();
        if (! $server instanceof MailServer) {
            return;
        }

        $client = MailcowClient::forServer($server);
        $attributes = $this->limits->domainAttributes($this->limits->package($site));

        foreach ($site->comailDomains()->where('status', SiteComailDomain::STATUS_ACTIVE)->get() as $row) {
            try {
                $client->editDomain($row->domain, $attributes);
            } catch (MailcowException $exception) {
                Log::warning('comail.domain_limits_failed', [
                    'site_id' => $site->id,
                    'domain' => $row->domain,
                    'error' => $exception->providerKey ?? $exception->error,
                ]);
                $row->forceFill(['last_error' => mb_substr($exception->getMessage(), 0, 500)])->save();
            }
        }
    }

    /**
     * Remove a non-primary domain without mailboxes from mailcow and Plane.
     */
    public function remove(SiteComailDomain $row, MailcowClient $client): void
    {
        if ($client->domain($row->domain) !== null) {
            $client->deleteDomain($row->domain);
        }

        $row->delete();
    }

    /**
     * mailcow normally made the key with the domain; a later add/dkim for the same
     * domain answers danger (`dkim_domain_or_sel_invalid`), so read first.
     *
     * @return array<string, mixed>
     */
    private function ensureDkim(MailcowClient $client, string $domain): array
    {
        $dkim = $client->dkim($domain);
        if ($dkim !== null) {
            return $dkim;
        }

        $client->addDkim(
            $domain,
            (string) config('ops.comail.dkim_selector', 'dkim'),
            (int) config('ops.comail.dkim_key_size', 2048),
        );

        return $client->dkim($domain) ?? [];
    }
}
