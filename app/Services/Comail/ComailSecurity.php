<?php

namespace App\Services\Comail;

use App\Models\CloudflareSetting;
use App\Models\MailServer;
use App\Models\Site;
use App\Models\SiteComailDomain;
use App\Services\Cloudflare\CloudflareApiException;
use App\Services\Cloudflare\CloudflareClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * CoMail v3 mail security for one site: the security overview (fresh DNS check,
 * cached 60 s), the DMARC policy, and the security records of local-mode domains
 * (ComailSecurityRecords) together with their server side: the mailcow MTA-STS
 * policy (mode testing) and the external DMARC report authorization in the
 * report domain's zone.
 *
 * Relay-mode domains (MX still at another provider) never get more DNS than v1.
 * A customer's own `_dmarc` with a stricter policy than requested is left alone,
 * and so is an `mta-sts.D` / `_smtp._tls.D` the customer already has.
 */
class ComailSecurity
{
    public function __construct(
        private readonly ComailDns $dns,
        private readonly ComailDomainProvisioner $provisioner,
        private readonly DnsResolver $resolver,
    ) {}

    public static function forget(SiteComailDomain $row): void
    {
        Cache::forget(self::cacheKey($row));
    }

    private static function cacheKey(SiteComailDomain $row): string
    {
        return 'comail:security:'.strtolower($row->domain);
    }

    /**
     * @return array{domains: list<array<string, mixed>>, antivirus: bool, spam: array{default_score: string}}
     */
    public function overview(Site $site, MailcowClient $client): array
    {
        $rows = $site->comailDomains()
            ->where('status', SiteComailDomain::STATUS_ACTIVE)
            ->orderByDesc('is_primary')
            ->orderBy('domain')
            ->get();

        return [
            'domains' => $rows->map(fn (SiteComailDomain $row): array => $this->row($row, $client))->values()->all(),
            // ClamAV runs for every CoMail domain (rspamd rejects infected mail).
            'antivirus' => true,
            'spam' => ['default_score' => (string) config('ops.comail.spam_default_score', '8,15')],
        ];
    }

    /**
     * One domain's security row (fresh DNS check, cached for a minute).
     *
     * @return array<string, mixed>
     */
    public function row(SiteComailDomain $row, MailcowClient $client): array
    {
        return Cache::remember(
            self::cacheKey($row),
            now()->addSeconds(max(1, (int) config('ops.comail.security_cache_seconds', 60))),
            fn (): array => $this->build($row, $client),
        );
    }

    /**
     * Set the DMARC policy (managed zones, local mode only) and write `_dmarc`.
     *
     * @return array{row: array<string, mixed>, written: bool}
     */
    public function setDmarcPolicy(SiteComailDomain $row, string $policy, MailcowClient $client): array
    {
        if (! in_array($policy, SiteComailDomain::DMARC_POLICIES, true)) {
            throw ComailException::validation('Politika none, quarantine ya da reject olmalı.');
        }
        if ($this->provisioner->relayFor($row)) {
            throw ComailException::validation('DMARC politikası, alan adının e-postaları CoMail’e geçtikten sonra seçilebilir.');
        }

        $zone = $this->dns->zoneFor($row);
        if ($zone === null) {
            throw new ComailException('dns_not_managed', 'Bu alan adının DNS’i bizim tarafımızdan yönetilmiyor; DMARC kaydını alan adı sağlayıcınızda güncelleyin.');
        }

        $row->forceFill(['dmarc_policy' => $policy])->save();

        try {
            $cloudflare = CloudflareClient::fromSettings($zone['account']);
            $this->guard($cloudflare, $zone, ['_dmarc.'.strtolower($row->domain)]);
            $written = $this->writeDmarc($cloudflare, $zone['zone_id'], $row);
        } catch (CloudflareApiException $exception) {
            Log::warning('comail.dmarc_write_failed', ['domain' => $row->domain, 'status' => $exception->status]);

            throw new ComailException('provider_error', 'DMARC kaydı yazılamadı. Biraz sonra tekrar deneyin.');
        }

        $this->ensureReportAuthorization($row);
        self::forget($row);

        return ['row' => $this->row($row, $client), 'written' => $written];
    }

    /**
     * Security records of a local-mode domain on a Plane-managed zone (called by
     * ComailDns::write() whenever CoMail receives the domain's mail). Idempotent.
     *
     * @param  array{account: CloudflareSetting, zone_id: string, zone_name: string}  $zone
     */
    public function writeRecords(SiteComailDomain $row, CloudflareClient $cloudflare, array $zone): void
    {
        $domain = strtolower($row->domain);
        $zoneId = $zone['zone_id'];
        $ip = ComailSecurityRecords::mailIpv4();

        $this->guard($cloudflare, $zone, ['mta-sts.'.$domain, '_mta-sts.'.$domain, '_smtp._tls.'.$domain, '_dmarc.'.$domain]);

        $this->writeDmarc($cloudflare, $zoneId, $row);

        $tlsRpt = array_filter(
            $this->txtRows($cloudflare, $zoneId, '_smtp._tls.'.$domain),
            static fn (array $r): bool => str_starts_with(self::flat((string) ($r['content'] ?? '')), 'v=tlsrptv1'),
        );
        if ($tlsRpt === []) {
            $cloudflare->createDnsRecord($zoneId, self::txtPayload('_smtp._tls.'.$domain, ComailSecurityRecords::tlsRptValue()));
        }

        // MTA-STS only with a policy mailcow actually serves, and only on a policy
        // host that is ours (a customer's own mta-sts.D stays as it is).
        if ($this->ensurePolicy($row)) {
            $host = $this->dns->rowsAt($cloudflare, $zoneId, 'mta-sts.'.$domain);
            if ($host === []) {
                $cloudflare->createDnsRecord($zoneId, [
                    'type' => 'A',
                    'name' => 'mta-sts.'.$domain,
                    'content' => $ip,
                    'proxied' => false,
                    'ttl' => 1,
                    'comment' => 'CoMail MTA-STS',
                ]);
                $ours = true;
            } else {
                $ours = array_filter($host, static fn (array $r): bool => strtoupper((string) ($r['type'] ?? '')) === 'A'
                    && trim((string) ($r['content'] ?? '')) === $ip) !== [];
            }

            if ($ours) {
                $want = ComailSecurityRecords::mtaStsValue((string) $row->mta_sts_id);
                $txt = array_values(array_filter(
                    $this->txtRows($cloudflare, $zoneId, '_mta-sts.'.$domain),
                    static fn (array $r): bool => str_starts_with(self::flat((string) ($r['content'] ?? '')), 'v=stsv1'),
                ));
                if ($txt === []) {
                    $cloudflare->createDnsRecord($zoneId, self::txtPayload('_mta-sts.'.$domain, $want));
                } elseif (self::flat((string) ($txt[0]['content'] ?? '')) !== self::flat($want)) {
                    $cloudflare->updateDnsRecord($zoneId, (string) $txt[0]['id'], self::txtPayload('_mta-sts.'.$domain, $want));
                }
            }
        }

        $this->ensureReportAuthorization($row);
    }

    /**
     * Server-side parts for a domain whose mail CoMail receives, also when its zone
     * is not Plane's (the customer adds the DNS records by hand). Best effort.
     */
    public function ensureServerSide(SiteComailDomain $row): void
    {
        if ($row->mx_active_at === null) {
            return;
        }

        $this->ensurePolicy($row);
        $this->ensureReportAuthorization($row);
    }

    /**
     * The domain leaves CoMail: drop its report authorization. Best effort.
     */
    public function removeReportAuthorization(SiteComailDomain $row): void
    {
        $name = ComailSecurityRecords::reportAuthorizationName($row->domain);
        $zone = $name === null ? null : $this->dns->zoneForName(ComailSecurityRecords::reportDomain());
        if ($zone === null) {
            return;
        }

        try {
            $cloudflare = CloudflareClient::fromSettings($zone['account']);
            foreach ($this->txtRows($cloudflare, $zone['zone_id'], (string) $name) as $record) {
                if (ComailSecurityRecords::isDmarc((string) ($record['content'] ?? ''))) {
                    $cloudflare->deleteDnsRecord($zone['zone_id'], (string) $record['id']);
                }
            }
        } catch (CloudflareApiException $exception) {
            Log::warning('comail.report_auth_remove_failed', ['domain' => $row->domain, 'status' => $exception->status]);
        }
    }

    // ── Overview ────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function build(SiteComailDomain $row, MailcowClient $client): array
    {
        $result = $this->dns->check($row);
        $records = [];
        foreach ($result['records'] as $record) {
            $records[(string) $record['key']] = $record;
        }

        $local = ! $this->provisioner->relayFor($row);
        $domain = strtolower($row->domain);
        $host = ComailDns::mailHost();

        $checks = [
            'mx' => $local
                ? self::fromRecord($records['mx'] ?? null, '10 '.$host)
                : self::check('not_applicable', $records['mx']['current'] ?? null, null),
            'spf' => self::fromRecord($records['spf'] ?? null, ComailDns::spfValue($host)),
            'dkim' => self::fromRecord($records['dkim'] ?? null, null),
        ];

        if ($local) {
            $policy = $row->dmarcPolicy();
            [$status, $current] = ComailSecurityRecords::verifyDmarc($this->txt('_dmarc.'.$domain), $policy);
            $checks['dmarc'] = self::check($status, $current, ComailSecurityRecords::dmarcValue($policy));
            $checks['mta_sts'] = $this->mtaStsCheck($row);
            [$status, $current] = ComailSecurityRecords::verifyTxt('tls_rpt', $this->txt('_smtp._tls.'.$domain));
            $checks['tls_rpt'] = self::check($status, $current, ComailSecurityRecords::tlsRptValue());
        } else {
            foreach (['dmarc', 'mta_sts', 'tls_rpt'] as $key) {
                $checks[$key] = self::check('not_applicable', null, null);
            }
        }

        try {
            $rateLimit = $client->domainRateLimit($domain);
        } catch (MailcowException) {
            $rateLimit = ['value' => null, 'frame' => null];
        }

        return [
            'domain' => $domain,
            'mode' => $local ? 'local' : 'relay',
            'dns_managed' => (bool) $result['dns_managed'],
            'checks' => $checks,
            'dmarc_policy' => $row->dmarcPolicy(),
            'rate_limit' => $rateLimit,
        ];
    }

    /**
     * ok only when both records are right and the policy URL answers over valid TLS
     * (mailcow's ACME adds mta-sts.D within the hour after the A record appears).
     *
     * @return array{status: string, current?: string, expected?: string}
     */
    private function mtaStsCheck(SiteComailDomain $row): array
    {
        $domain = strtolower($row->domain);
        $expected = ComailSecurityRecords::mtaStsValue(ComailSecurityRecords::mtaStsId($row));
        [$address, $addressCurrent] = ComailSecurityRecords::verifyAddress(array_map(
            static fn (array $answer): string => (string) ($answer['ip'] ?? ''),
            $this->resolver->lookup('mta-sts.'.$domain, 'A'),
        ));
        [$txt, $txtCurrent] = ComailSecurityRecords::verifyTxt('mta_sts_txt', $this->txt('_mta-sts.'.$domain));

        if ($address === 'missing' && $txt === 'missing') {
            return self::check('missing', null, $expected);
        }
        if ($address === 'ok' && $txt === 'ok') {
            return $this->policyServed($domain)
                ? self::check('ok', null, $expected)
                : self::check('different', 'https://mta-sts.'.$domain.'/.well-known/mta-sts.txt yanıt vermiyor', $expected);
        }

        $current = array_values(array_filter([
            $addressCurrent !== null ? 'mta-sts.'.$domain.' → '.$addressCurrent : ($address === 'missing' ? 'mta-sts.'.$domain.' yok' : null),
            $txtCurrent ?? ($txt === 'missing' ? '_mta-sts.'.$domain.' yok' : null),
        ]));

        return self::check('different', implode('; ', $current), $expected);
    }

    private function policyServed(string $domain): bool
    {
        try {
            $response = Http::timeout(5)
                ->connectTimeout(3)
                ->withOptions(['allow_redirects' => false])
                ->get('https://mta-sts.'.$domain.'/.well-known/mta-sts.txt');
        } catch (Throwable) {
            return false;
        }

        return $response->successful() && str_contains(strtolower($response->body()), 'version: stsv1');
    }

    /**
     * @return list<string>
     */
    private function txt(string $name): array
    {
        return array_map(static fn (array $answer): string => trim((string) ($answer['value'] ?? '')), $this->resolver->lookup($name, 'TXT'));
    }

    /**
     * @param  array<string, mixed>|null  $record
     * @return array{status: string, current?: string, expected?: string}
     */
    private static function fromRecord(?array $record, ?string $expected): array
    {
        return self::check(
            is_string($record['status'] ?? null) ? $record['status'] : 'missing',
            is_string($record['current'] ?? null) ? $record['current'] : null,
            $expected,
        );
    }

    /**
     * @return array{status: string, current?: string, expected?: string}
     */
    private static function check(string $status, ?string $current, ?string $expected): array
    {
        $out = ['status' => $status];
        if ($current !== null && $current !== '') {
            $out['current'] = $current;
        }
        if ($expected !== null && $expected !== '') {
            $out['expected'] = $expected;
        }

        return $out;
    }

    // ── Writes ──────────────────────────────────────────────────────────

    /**
     * `_dmarc.D`: create, or merge the policy and CoMail's report address into an
     * existing record. False when a stricter customer record was left in place.
     */
    private function writeDmarc(CloudflareClient $cloudflare, string $zoneId, SiteComailDomain $row): bool
    {
        $name = '_dmarc.'.strtolower($row->domain);
        $existing = array_values(array_filter(
            $this->txtRows($cloudflare, $zoneId, $name),
            static fn (array $r): bool => ComailSecurityRecords::isDmarc((string) ($r['content'] ?? '')),
        ));
        $current = $existing === [] ? null : trim(trim((string) ($existing[0]['content'] ?? '')), '"');
        $want = ComailSecurityRecords::mergeDmarc($current, $row->dmarcPolicy());
        if ($want === null) {
            return false;
        }

        if ($current === null) {
            $cloudflare->createDnsRecord($zoneId, self::txtPayload($name, $want));
        } elseif ($current !== $want) {
            $cloudflare->updateDnsRecord($zoneId, (string) $existing[0]['id'], self::txtPayload($name, $want));
        }

        return true;
    }

    /**
     * mailcow MTA-STS policy (mode testing, MX = CoMail host). mailcow cannot read a
     * policy back, so an existing one (duplicate entry) counts as present and keeps
     * whatever mode ops gave it. Sets `mta_sts_id` once. Best effort.
     */
    private function ensurePolicy(SiteComailDomain $row): bool
    {
        if (filled($row->mta_sts_id)) {
            return true;
        }

        $server = MailServer::comail();
        if (! $server instanceof MailServer || $row->mailcow_synced_at === null) {
            return false;
        }

        try {
            MailcowClient::forServer($server)->addMtaSts(strtolower($row->domain), [
                'version' => 'STSv1',
                'mode' => 'testing',
                'mx' => ComailDns::mailHost(),
                'max_age' => (string) config('ops.comail.mta_sts_max_age', 86400),
                'active' => '1',
            ]);
        } catch (MailcowException $exception) {
            $key = strtolower((string) $exception->providerKey);
            if (! str_contains($key, 'duplicate') && ! str_contains($key, '23000')) {
                Log::warning('comail.mta_sts_failed', ['domain' => $row->domain, 'error' => $exception->providerKey ?? $exception->error]);

                return false;
            }
        }

        $row->forceFill(['mta_sts_id' => now()->format('YmdHis')])->save();

        return true;
    }

    /**
     * `D._report._dmarc.<report domain>` TXT v=DMARC1 in Plane's own zone. Best effort.
     */
    private function ensureReportAuthorization(SiteComailDomain $row): void
    {
        if ($row->report_auth_at !== null) {
            return;
        }

        $name = ComailSecurityRecords::reportAuthorizationName($row->domain);
        if ($name === null) {
            $row->forceFill(['report_auth_at' => now()])->save();

            return;
        }

        $zone = $this->dns->zoneForName(ComailSecurityRecords::reportDomain());
        if ($zone === null) {
            Log::notice('comail.report_zone_missing', ['domain' => $row->domain]);

            return;
        }

        try {
            $cloudflare = CloudflareClient::fromSettings($zone['account']);
            $this->guard($cloudflare, $zone, [$name]);
            $present = array_filter(
                $this->txtRows($cloudflare, $zone['zone_id'], $name),
                static fn (array $r): bool => ComailSecurityRecords::isDmarc((string) ($r['content'] ?? '')),
            );
            if ($present === []) {
                $cloudflare->createDnsRecord($zone['zone_id'], self::txtPayload($name, 'v=DMARC1'));
            }
            $row->forceFill(['report_auth_at' => now()])->save();
        } catch (CloudflareApiException $exception) {
            Log::warning('comail.report_auth_failed', ['domain' => $row->domain, 'status' => $exception->status]);
        }
    }

    /**
     * Wildcard-shadowing guard (same rule as ComailDns, Plane #29): a record under a
     * name that has no records yet turns that name into an existing (empty) node, and
     * a parent wildcard stops answering for it. Every hostname-like ancestor of the
     * names about to be written, below the zone apex, gets the wildcard's address
     * records pinned first. Underscore labels (_dmarc, _tls, …) are never hostnames.
     *
     * @param  array{account: CloudflareSetting, zone_id: string, zone_name: string}  $zone
     * @param  list<string>  $names
     */
    private function guard(CloudflareClient $cloudflare, array $zone, array $names): void
    {
        $apex = strtolower($zone['zone_name']);
        $ancestors = [];
        foreach ($names as $name) {
            $labels = explode('.', strtolower($name));
            for ($i = 1; $i < count($labels); $i++) {
                $ancestor = implode('.', array_slice($labels, $i));
                if ($ancestor === $apex || ! str_ends_with($ancestor, '.'.$apex)) {
                    break;
                }
                if (! str_starts_with($ancestor, '_')) {
                    $ancestors[$ancestor] = true;
                }
            }
        }

        foreach (array_keys($ancestors) as $ancestor) {
            $this->dns->pinWildcardAddress($cloudflare, $zone['zone_id'], $ancestor);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function txtRows(CloudflareClient $cloudflare, string $zoneId, string $name): array
    {
        return array_values(array_filter(
            $this->dns->rowsAt($cloudflare, $zoneId, $name),
            static fn (array $r): bool => strtoupper((string) ($r['type'] ?? '')) === 'TXT',
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private static function txtPayload(string $name, string $value): array
    {
        return ['type' => 'TXT', 'name' => $name, 'content' => $value, 'ttl' => 1];
    }

    private static function flat(string $value): string
    {
        return strtolower(str_replace([' ', '"'], '', $value));
    }
}
