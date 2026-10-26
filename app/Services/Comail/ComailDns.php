<?php

namespace App\Services\Comail;

use App\Models\CloudflareSetting;
use App\Models\MailServer;
use App\Models\Site;
use App\Models\SiteComailDomain;
use App\Services\Cloudflare\CloudflareAccounts;
use App\Services\Cloudflare\CloudflareApiException;
use App\Services\Cloudflare\CloudflareClient;
use App\Services\Cloudflare\CloudflareHostname;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * CoMail DNS for one domain D: plan, apply (Cloudflare zones Plane manages),
 * activate (switch MX/SPF/autodiscover to CoMail) and check (public DNS).
 *
 * Records (all DNS only):
 *  MX D → mail host (10) · TXT D SPF · TXT {selector}._domainkey.D DKIM ·
 *  TXT _dmarc.D (only when none) · CNAME mail./autodiscover./autoconfig.D →
 *  mail host · SRV _autodiscover._tcp.D 0 1 443 mail host.
 *
 * Apply never touches a customer's mail routing: MX and SPF are only written
 * when D has no MX at all. Activate is the explicit switch. Zones outside
 * Plane's Cloudflare are never written; the plan is returned for manual entry.
 */
class ComailDns
{
    public const KEYS = ['mx', 'spf', 'dkim', 'dmarc', 'cname_mail', 'cname_autodiscover', 'cname_autoconfig', 'srv_autodiscover'];

    public function __construct(
        private readonly DnsResolver $resolver,
    ) {}

    public static function mailHost(): string
    {
        $server = MailServer::comail();
        $host = $server instanceof MailServer ? $server->comailHostname() : '';

        return $host !== '' ? $host : 'mail.codron.co';
    }

    public static function spfValue(string $mailHost): string
    {
        return 'v=spf1 mx a:'.$mailHost.' ~all';
    }

    /**
     * @return list<array{key: string, type: string, name: string, value: string, priority?: int}>
     */
    public function plan(SiteComailDomain $row): array
    {
        $domain = strtolower($row->domain);
        $host = self::mailHost();
        $selector = filled($row->dkim_selector) ? (string) $row->dkim_selector : (string) config('ops.comail.dkim_selector', 'dkim');

        $records = [
            ['key' => 'mx', 'type' => 'MX', 'name' => $domain, 'value' => $host, 'priority' => 10],
            ['key' => 'spf', 'type' => 'TXT', 'name' => $domain, 'value' => self::spfValue($host)],
        ];
        if (filled($row->dkim_txt)) {
            $records[] = ['key' => 'dkim', 'type' => 'TXT', 'name' => $selector.'._domainkey.'.$domain, 'value' => trim((string) $row->dkim_txt)];
        }
        $records[] = ['key' => 'dmarc', 'type' => 'TXT', 'name' => '_dmarc.'.$domain, 'value' => 'v=DMARC1; p=none'];
        $records[] = ['key' => 'cname_mail', 'type' => 'CNAME', 'name' => 'mail.'.$domain, 'value' => $host];
        $records[] = ['key' => 'cname_autodiscover', 'type' => 'CNAME', 'name' => 'autodiscover.'.$domain, 'value' => $host];
        $records[] = ['key' => 'cname_autoconfig', 'type' => 'CNAME', 'name' => 'autoconfig.'.$domain, 'value' => $host];
        $records[] = ['key' => 'srv_autodiscover', 'type' => 'SRV', 'name' => '_autodiscover._tcp.'.$domain, 'value' => '0 1 443 '.$host];

        return $records;
    }

    /**
     * Cloudflare zone (on any enabled Plane account) that covers the domain.
     *
     * @return array{account: CloudflareSetting, zone_id: string, zone_name: string}|null
     */
    public function zoneFor(SiteComailDomain $row): ?array
    {
        $site = $row->site;
        $accounts = [];
        if ($site instanceof Site && $site->cloudflareAccount instanceof CloudflareSetting && $site->cloudflareAccount->is_enabled) {
            $accounts[] = $site->cloudflareAccount;
        }
        foreach (CloudflareAccounts::enabled() as $account) {
            $accounts[] = $account;
        }

        $seen = [];
        foreach ($accounts as $account) {
            if (isset($seen[$account->id]) || ! $account->hasCredentials()) {
                continue;
            }
            $seen[$account->id] = true;

            try {
                $client = CloudflareClient::fromSettings($account);
                foreach (CloudflareHostname::zoneCandidates($row->domain) as $candidate) {
                    foreach ($client->listZones((string) $account->account_id, $candidate, 5) as $zone) {
                        if (strcasecmp((string) ($zone['name'] ?? ''), $candidate) === 0 && filled($zone['id'] ?? null)) {
                            return ['account' => $account, 'zone_id' => (string) $zone['id'], 'zone_name' => strtolower($candidate)];
                        }
                    }
                }
            } catch (CloudflareApiException $exception) {
                Log::notice('comail.zone_lookup_failed', ['domain' => $row->domain, 'status' => $exception->status]);
            }
        }

        return null;
    }

    /**
     * Whether Plane can write the domain's DNS (cached 10 minutes: one zone lookup
     * is several Cloudflare calls and the overview asks for every domain).
     */
    public function isManaged(SiteComailDomain $row): bool
    {
        return (bool) Cache::remember(
            'comail:dns-managed:'.strtolower($row->domain),
            now()->addMinutes(10),
            fn (): bool => $this->zoneFor($row) !== null,
        );
    }

    /**
     * Fresh public-DNS check; stores the result and the MX state on the row.
     *
     * @return array{domain: string, dns_managed: bool, mx_active: bool, records: list<array<string, mixed>>}
     */
    public function check(SiteComailDomain $row, ?bool $managed = null, bool $keepActive = false): array
    {
        $host = self::mailHost();
        $records = [];
        $mxStatus = 'missing';

        foreach ($this->plan($row) as $record) {
            [$status, $current] = $this->verify($record, $host, $mxStatus);
            if ($record['key'] === 'mx') {
                $mxStatus = $status;
            }

            $records[] = self::publicRecord($record, $status, $current);
        }

        $wasActive = $row->mx_active_at !== null;
        $mxActiveAt = $row->mx_active_at;
        if ($mxStatus === 'ok') {
            $mxActiveAt ??= now();
        } elseif ($mxStatus === 'different' && ! $keepActive) {
            // Right after activate() public DNS may still show the old MX for the TTL.
            $mxActiveAt = null;
        }

        $row->forceFill([
            'dns_checked_at' => now(),
            'dns_check' => ['records' => $records],
            'mx_active_at' => $mxActiveAt,
        ])->save();

        if ($wasActive !== ($mxActiveAt !== null)) {
            app(ComailDomainProvisioner::class)->syncRouting($row, $mxActiveAt !== null);
        }

        return $this->shape($row, $managed ?? $this->isManaged($row), $records);
    }

    /**
     * Write what is missing, without taking over existing mail routing.
     *
     * @return array{domain: string, dns_managed: bool, mx_active: bool, records: list<array<string, mixed>>}
     */
    public function apply(SiteComailDomain $row): array
    {
        $zone = $this->requireZone($row);
        $this->write($row, $zone, activate: false);

        return $this->check($row, true);
    }

    /**
     * Switch D's mail to CoMail: replace MX and SPF, point autodiscover/autoconfig here.
     *
     * @return array{domain: string, dns_managed: bool, mx_active: bool, records: list<array<string, mixed>>}
     */
    public function activate(SiteComailDomain $row): array
    {
        $zone = $this->requireZone($row);
        $this->write($row, $zone, activate: true);

        $row->forceFill(['mx_active_at' => now()])->save();
        app(ComailDomainProvisioner::class)->syncRouting($row, true);

        return $this->check($row, true, keepActive: true);
    }

    /**
     * Best effort apply for automatic enrollment: unmanaged zones are skipped.
     */
    public function applyIfManaged(SiteComailDomain $row): bool
    {
        $zone = $this->zoneFor($row);
        if ($zone === null) {
            return false;
        }

        $this->write($row, $zone, activate: false);

        return true;
    }

    /**
     * Response shape without a fresh lookup (the stored check).
     *
     * @param  list<array<string, mixed>>|null  $records
     * @return array{domain: string, dns_managed: bool, mx_active: bool, records: list<array<string, mixed>>}
     */
    public function shape(SiteComailDomain $row, bool $managed, ?array $records = null): array
    {
        if ($records === null) {
            $stored = is_array($row->dns_check['records'] ?? null) ? $row->dns_check['records'] : null;
            $records = $stored ?? array_map(
                static fn (array $record): array => self::publicRecord($record, 'missing', null),
                $this->plan($row),
            );
        }

        return [
            'domain' => (string) $row->domain,
            'dns_managed' => $managed,
            'mx_active' => $row->mx_active_at !== null,
            'records' => array_values($records),
        ];
    }

    /**
     * @param  array{key: string, type: string, name: string, value: string, priority?: int}  $record
     * @return array<string, mixed>
     */
    private static function publicRecord(array $record, string $status, ?string $current): array
    {
        $out = [
            'key' => $record['key'],
            'type' => $record['type'],
            'name' => $record['name'],
            'value' => $record['value'],
        ];
        if (isset($record['priority'])) {
            $out['priority'] = $record['priority'];
        }
        $out['status'] = $status;
        if ($current !== null && $current !== '') {
            $out['current'] = $current;
        }

        return $out;
    }

    /**
     * @param  array{key: string, type: string, name: string, value: string, priority?: int}  $record
     * @return array{0: string, 1: string|null}
     */
    private function verify(array $record, string $host, string $mxStatus): array
    {
        $key = $record['key'];
        $answers = $this->resolver->lookup($record['name'], $record['type']);

        if ($record['type'] === 'MX' || $record['type'] === 'CNAME') {
            $targets = array_values(array_filter(array_map(
                static fn (array $answer): string => strtolower(rtrim((string) ($answer['target'] ?? ''), '.')),
                $answers,
            )));
            if ($targets === []) {
                return ['missing', null];
            }

            return [in_array($host, $targets, true) ? 'ok' : 'different', implode(', ', $targets)];
        }

        if ($record['type'] === 'SRV') {
            $current = [];
            foreach ($answers as $answer) {
                $target = strtolower(rtrim((string) ($answer['target'] ?? ''), '.'));
                $current[] = ((int) ($answer['priority'] ?? 0)).' '.((int) ($answer['weight'] ?? 0)).' '.((int) ($answer['port'] ?? 0)).' '.$target;
                if ($target === $host && (int) ($answer['port'] ?? 0) === 443) {
                    return ['ok', null];
                }
            }

            return [$current === [] ? 'missing' : 'different', $current === [] ? null : implode(', ', $current)];
        }

        $values = array_map(static fn (array $answer): string => trim((string) ($answer['value'] ?? '')), $answers);

        return match ($key) {
            'spf' => $this->verifySpf($values, $host, $mxStatus),
            'dkim' => $this->verifyDkim($values, $record['value']),
            'dmarc' => $this->verifyPrefixed($values, 'v=dmarc1'),
            default => ['missing', null],
        };
    }

    /**
     * @param  list<string>  $values
     * @return array{0: string, 1: string|null}
     */
    private function verifySpf(array $values, string $host, string $mxStatus): array
    {
        foreach ($values as $value) {
            $lower = strtolower($value);
            if (! str_starts_with($lower, 'v=spf1')) {
                continue;
            }

            $tokens = preg_split('/\s+/', $lower) ?: [];
            $authorises = in_array('a:'.$host, $tokens, true)
                || in_array('include:'.$host, $tokens, true)
                || (in_array('mx', $tokens, true) && $mxStatus === 'ok');

            return [$authorises ? 'ok' : 'different', $value];
        }

        return ['missing', null];
    }

    /**
     * @param  list<string>  $values
     * @return array{0: string, 1: string|null}
     */
    private function verifyDkim(array $values, string $expected): array
    {
        $want = self::dkimKey($expected);
        foreach ($values as $value) {
            if ($want !== '' && self::dkimKey($value) === $want) {
                return ['ok', null];
            }
        }

        return $values === [] ? ['missing', null] : ['different', mb_substr(implode(' ', $values), 0, 120)];
    }

    /**
     * @param  list<string>  $values
     * @return array{0: string, 1: string|null}
     */
    private function verifyPrefixed(array $values, string $prefix): array
    {
        foreach ($values as $value) {
            if (str_starts_with(strtolower(str_replace(' ', '', $value)), $prefix)) {
                return ['ok', null];
            }
        }

        return $values === [] ? ['missing', null] : ['different', implode(', ', $values)];
    }

    private static function dkimKey(string $value): string
    {
        $flat = str_replace(['"', ' ', "\t", "\n", "\r"], '', $value);
        if (preg_match('/(?:^|;)p=([A-Za-z0-9+\/=]+)/', $flat, $match) === 1) {
            return $match[1];
        }

        return '';
    }

    /**
     * @return array{account: CloudflareSetting, zone_id: string, zone_name: string}
     */
    private function requireZone(SiteComailDomain $row): array
    {
        $zone = $this->zoneFor($row);
        if ($zone === null) {
            throw ComailException::validation('Bu alan adının DNS’i bizim tarafımızdan yönetilmiyor; kayıtları alan adı sağlayıcınızda elle ekleyin.');
        }

        return $zone;
    }

    /**
     * @param  array{account: CloudflareSetting, zone_id: string, zone_name: string}  $zone
     */
    private function write(SiteComailDomain $row, array $zone, bool $activate): void
    {
        $client = CloudflareClient::fromSettings($zone['account']);
        $zoneId = $zone['zone_id'];
        $host = self::mailHost();
        $plan = [];
        foreach ($this->plan($row) as $record) {
            $plan[$record['key']] = $record;
        }

        try {
            // Any record at or under D makes D an existing name: a parent wildcard
            // (*.example.com) stops answering for it and a site served by that wildcard
            // goes offline (demo.basutsilo.com, 2026-10-26). Pin the wildcard first.
            $this->pinWildcardAddress($client, $zoneId, $plan['mx']['name']);

            $apex = $this->rowsAt($client, $zoneId, $plan['mx']['name']);
            $mx = array_values(array_filter($apex, static fn (array $r): bool => strtoupper((string) ($r['type'] ?? '')) === 'MX'));
            $spf = array_values(array_filter($apex, static fn (array $r): bool => strtoupper((string) ($r['type'] ?? '')) === 'TXT'
                && str_starts_with(self::txt((string) ($r['content'] ?? '')), 'v=spf1')));
            $mxIsOurs = $mx !== [] && array_filter($mx, static fn (array $r): bool => self::host((string) ($r['content'] ?? '')) !== $host) === [];

            if ($activate) {
                foreach ($mx as $existing) {
                    if (self::host((string) ($existing['content'] ?? '')) !== $host) {
                        $client->deleteDnsRecord($zoneId, (string) $existing['id']);
                    }
                }
                $hasOurs = array_filter($mx, static fn (array $r): bool => self::host((string) ($r['content'] ?? '')) === $host) !== [];
                if (! $hasOurs) {
                    $this->createMx($client, $zoneId, $plan['mx']);
                }
                $this->replaceSpf($client, $zoneId, $plan['spf'], $spf);
                foreach (['cname_autodiscover', 'cname_autoconfig'] as $key) {
                    $this->pointCname($client, $zoneId, $plan[$key], $host);
                }
            } elseif ($mx === []) {
                // Fresh domain: no mail anywhere yet, CoMail takes it.
                $this->createMx($client, $zoneId, $plan['mx']);
                $this->mergeSpf($client, $zoneId, $plan['spf'], $spf, $host);
            } elseif ($mxIsOurs) {
                $this->mergeSpf($client, $zoneId, $plan['spf'], $spf, $host);
            }

            if (isset($plan['dkim'])) {
                $this->upsertDkim($client, $zoneId, $plan['dkim']);
            }

            // Client auto-setup records only once CoMail actually receives the mail: while
            // the MX is still at another provider they would send Outlook / phones to CoMail
            // with a password that only works at the old provider.
            $comailReceives = $activate || $mx === [] || $mxIsOurs;
            $discovery = $comailReceives ? ['cname_mail', 'cname_autodiscover', 'cname_autoconfig'] : ['cname_mail'];
            foreach ($discovery as $key) {
                if ($this->rowsAt($client, $zoneId, $plan[$key]['name']) === []) {
                    $client->createDnsRecord($zoneId, $this->payload($plan[$key]));
                }
            }

            $dmarc = array_filter(
                $this->rowsAt($client, $zoneId, $plan['dmarc']['name']),
                static fn (array $r): bool => strtoupper((string) ($r['type'] ?? '')) === 'TXT',
            );
            if ($dmarc === []) {
                $client->createDnsRecord($zoneId, $this->payload($plan['dmarc']));
            }

            if ($comailReceives && $this->rowsAt($client, $zoneId, $plan['srv_autodiscover']['name']) === []) {
                $client->createDnsRecord($zoneId, $this->payload($plan['srv_autodiscover']));
            }
        } catch (CloudflareApiException $exception) {
            Log::warning('comail.dns_write_failed', [
                'domain' => $row->domain,
                'status' => $exception->status,
                'activate' => $activate,
            ]);

            throw new ComailException(
                'provider_error',
                $exception->isForbidden()
                    ? 'DNS kayıtları yazılamadı: Cloudflare jetonunda DNS düzenleme izni yok.'
                    : 'DNS kayıtları yazılamadı. Biraz sonra tekrar deneyin.',
            );
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rowsAt(CloudflareClient $client, string $zoneId, string $fqdn): array
    {
        return array_values(array_filter(
            $client->listDnsRecords($zoneId, ['name' => $fqdn, 'per_page' => 100]),
            static fn (array $row): bool => strcasecmp(rtrim((string) ($row['name'] ?? ''), '.'), $fqdn) === 0,
        ));
    }

    /**
     * When $fqdn has no address record of its own but a parent wildcard answers for it,
     * copy the wildcard's A/AAAA/CNAME records onto $fqdn (same content and proxy flag).
     */
    private function pinWildcardAddress(CloudflareClient $client, string $zoneId, string $fqdn): void
    {
        $dot = strpos($fqdn, '.');
        if ($dot === false) {
            return;
        }

        $address = static fn (array $r): bool => in_array(strtoupper((string) ($r['type'] ?? '')), ['A', 'AAAA', 'CNAME'], true);
        if (array_filter($this->rowsAt($client, $zoneId, $fqdn), $address) !== []) {
            return;
        }

        $wildcard = array_values(array_filter($this->rowsAt($client, $zoneId, '*.'.substr($fqdn, $dot + 1)), $address));
        foreach ($wildcard as $row) {
            $client->createDnsRecord($zoneId, [
                'type' => strtoupper((string) $row['type']),
                'name' => $fqdn,
                'content' => (string) $row['content'],
                'proxied' => (bool) ($row['proxied'] ?? false),
                'ttl' => 1,
                'comment' => 'CoMail: explicit copy of the wildcard',
            ]);
        }

        if ($wildcard !== []) {
            Log::info('comail.wildcard_pinned', ['name' => $fqdn, 'records' => count($wildcard)]);
        }
    }

    /**
     * @param  array{key: string, type: string, name: string, value: string, priority?: int}  $record
     */
    private function createMx(CloudflareClient $client, string $zoneId, array $record): void
    {
        $client->createDnsRecord($zoneId, $this->payload($record));
    }

    /**
     * Activate: exactly one SPF, CoMail's.
     *
     * @param  array{key: string, type: string, name: string, value: string}  $record
     * @param  list<array<string, mixed>>  $existing
     */
    private function replaceSpf(CloudflareClient $client, string $zoneId, array $record, array $existing): void
    {
        if ($existing === []) {
            $client->createDnsRecord($zoneId, $this->payload($record));

            return;
        }

        $first = array_shift($existing);
        if (self::txt((string) ($first['content'] ?? '')) !== strtolower($record['value'])) {
            $client->updateDnsRecord($zoneId, (string) $first['id'], $this->payload($record));
        }
        foreach ($existing as $extra) {
            $client->deleteDnsRecord($zoneId, (string) $extra['id']);
        }
    }

    /**
     * Fresh domain: keep senders an existing SPF already allows, add CoMail to it.
     *
     * @param  array{key: string, type: string, name: string, value: string}  $record
     * @param  list<array<string, mixed>>  $existing
     */
    private function mergeSpf(CloudflareClient $client, string $zoneId, array $record, array $existing, string $host): void
    {
        if ($existing === []) {
            $client->createDnsRecord($zoneId, $this->payload($record));

            return;
        }

        $first = $existing[0];
        $content = trim(trim((string) ($first['content'] ?? '')), '"');
        $tokens = preg_split('/\s+/', $content) ?: [];
        $lower = array_map('strtolower', $tokens);
        if (in_array('a:'.$host, $lower, true) || in_array('include:'.$host, $lower, true)) {
            return;
        }

        array_splice($tokens, 1, 0, in_array('mx', $lower, true) ? ['a:'.$host] : ['mx', 'a:'.$host]);
        $client->updateDnsRecord($zoneId, (string) $first['id'], $this->payload(['value' => implode(' ', $tokens)] + $record));
    }

    /**
     * @param  array{key: string, type: string, name: string, value: string}  $record
     */
    private function upsertDkim(CloudflareClient $client, string $zoneId, array $record): void
    {
        $rows = array_values(array_filter(
            $this->rowsAt($client, $zoneId, $record['name']),
            static fn (array $r): bool => strtoupper((string) ($r['type'] ?? '')) === 'TXT',
        ));

        $want = self::dkimKey($record['value']);
        foreach ($rows as $existing) {
            if ($want !== '' && self::dkimKey((string) ($existing['content'] ?? '')) === $want) {
                return;
            }
        }

        if ($rows !== []) {
            $client->updateDnsRecord($zoneId, (string) $rows[0]['id'], $this->payload($record));

            return;
        }

        $client->createDnsRecord($zoneId, $this->payload($record));
    }

    /**
     * @param  array{key: string, type: string, name: string, value: string}  $record
     */
    private function pointCname(CloudflareClient $client, string $zoneId, array $record, string $host): void
    {
        $rows = $this->rowsAt($client, $zoneId, $record['name']);
        foreach ($rows as $existing) {
            if (strtoupper((string) ($existing['type'] ?? '')) === 'CNAME') {
                if (self::host((string) ($existing['content'] ?? '')) !== $host) {
                    $client->updateDnsRecord($zoneId, (string) $existing['id'], $this->payload($record));
                }

                return;
            }
        }

        foreach ($rows as $existing) {
            $client->deleteDnsRecord($zoneId, (string) $existing['id']);
        }
        $client->createDnsRecord($zoneId, $this->payload($record));
    }

    /**
     * @param  array{key?: string, type: string, name: string, value: string, priority?: int}  $record
     * @return array<string, mixed>
     */
    private function payload(array $record): array
    {
        if ($record['type'] === 'SRV') {
            [$priority, $weight, $port, $target] = explode(' ', $record['value']) + [0, 1, 443, ''];

            return [
                'type' => 'SRV',
                'name' => $record['name'],
                'ttl' => 1,
                'data' => [
                    'priority' => (int) $priority,
                    'weight' => (int) $weight,
                    'port' => (int) $port,
                    'target' => (string) $target,
                ],
            ];
        }

        $payload = [
            'type' => $record['type'],
            'name' => $record['name'],
            'content' => $record['value'],
            'ttl' => 1,
        ];
        if ($record['type'] === 'CNAME') {
            $payload['proxied'] = false;
        }
        if (isset($record['priority'])) {
            $payload['priority'] = $record['priority'];
        }

        return $payload;
    }

    private static function txt(string $content): string
    {
        return strtolower(trim(trim($content), '"'));
    }

    private static function host(string $value): string
    {
        return strtolower(rtrim(trim($value), '.'));
    }
}
