<?php

namespace App\Services\Cloudflare;

use App\Models\CloudflareDnsDefault;
use App\Models\CloudflareSetting;
use App\Models\Site;
use App\Services\Sites\SiteProvisionException;
use Illuminate\Support\Facades\Log;

class CloudflareZoneService
{
    /**
     * Attach the site hostname to a covering zone, or create a Free full zone
     * on the registrable apex and return Cloudflare nameservers.
     *
     * @return array{zone: array<string, mixed>, created: bool}
     */
    public function ensureZoneAndDns(Site $site, CloudflareSetting $settings): array
    {
        $requested = CloudflareHostname::normalize((string) $site->primary_domain);
        if ($requested === '') {
            throw new SiteProvisionException(__('cloudflare.errors.domain_required'));
        }

        $client = CloudflareClient::fromSettings($settings);
        $accountId = trim((string) $settings->account_id);

        try {
            $zone = $this->findCoveringZone($client, $accountId, $requested);
            $created = false;

            if ($zone === null) {
                $apex = CloudflareHostname::apex($requested);
                if ($apex === '') {
                    throw new CloudflareApiException(__('cloudflare.errors.domain_required'), 422);
                }

                $existing = $this->findExactZone($client, $accountId, $apex);
                if ($existing === null) {
                    $zone = $this->createApexZone($client, $accountId, $apex);
                    $created = true;
                    $this->upsertTemplate($client, (string) $zone['id'], $apex, $settings);
                } else {
                    $zone = $existing;
                }
            }

            $zoneName = (string) ($zone['name'] ?? '');
            $hosts = $site->operatorHosts();
            if ($hosts === []) {
                $hosts = [$requested];
            }

            // Hosts under the primary zone attach there. A host on another apex gets
            // its own zone (found or created) and records it on its site_domains row.
            $foreignZones = [];
            foreach ($hosts as $host) {
                if (self::hostUnderZone($host, $zoneName)) {
                    $this->applyDnsForHost($client, (string) $zone['id'], $zoneName, $host, $settings);
                    $this->storeRowZone($site, $host, null);

                    continue;
                }

                $own = $this->resolveZoneForHost($client, $accountId, $host, $foreignZones, $settings);
                $this->applyDnsForHost($client, (string) $own['id'], (string) ($own['name'] ?? ''), $host, $settings);
                $this->storeRowZone($site, $host, $own);
            }

            $status = strtolower(trim((string) ($zone['status'] ?? '')));
            $site->cloudflare_zone_id = (string) $zone['id'];
            $site->cloudflare_nameservers = $this->nameservers($zone);
            $site->cloudflare_zone_status = $status !== '' ? $status : null;
            $site->dns_applied_at = now();
            $site->save();

            return [
                'zone' => $zone,
                'created' => $created,
            ];
        } catch (CloudflareApiException $exception) {
            throw $this->mapApiException($exception);
        }
    }

    /**
     * Re-read the zone status of alias hosts that live on their own zone. Best effort:
     * a zone Cloudflare cannot return keeps its last known status.
     */
    public function refreshAliasZones(Site $site, CloudflareSetting $settings): void
    {
        $rows = $site->domains()
            ->where('is_temporary', false)
            ->whereNotNull('cloudflare_zone_id')
            ->get();
        if ($rows->isEmpty()) {
            return;
        }

        $client = CloudflareClient::fromSettings($settings);
        $seen = [];

        foreach ($rows->groupBy('cloudflare_zone_id') as $zoneId => $group) {
            $zoneId = (string) $zoneId;
            if (isset($seen[$zoneId])) {
                continue;
            }
            $seen[$zoneId] = true;

            try {
                $zone = $client->getZone($zoneId);
            } catch (CloudflareApiException) {
                continue;
            }

            $status = strtolower(trim((string) ($zone['status'] ?? '')));
            $ns = $this->nameservers($zone);
            foreach ($group as $row) {
                $row->forceFill([
                    'cloudflare_zone_status' => $status !== '' ? $status : null,
                    'cloudflare_nameservers' => $ns !== [] ? $ns : $row->cloudflare_nameservers,
                ])->save();
            }
        }
    }

    public function applyDefaults(CloudflareSetting $settings, string $zoneId): void
    {
        $client = CloudflareClient::fromSettings($settings);
        $zone = $this->requireZoneOnAccount($client, $settings, $zoneId);
        $this->upsertTemplate($client, $zoneId, (string) ($zone['name'] ?? ''), $settings);

        Site::query()->where('cloudflare_zone_id', $zoneId)->update(['dns_applied_at' => now()]);
    }

    /**
     * @return array<string, mixed>
     */
    public function createZone(CloudflareSetting $settings, string $domain, bool $applyDefaults): array
    {
        $domain = $this->zoneName($domain);
        if ($domain === '') {
            throw new CloudflareApiException(__('cloudflare.errors.domain_required'), 422);
        }

        $client = CloudflareClient::fromSettings($settings);
        $zone = $this->findOrCreateZone($client, trim((string) $settings->account_id), $domain);

        if ($applyDefaults) {
            $this->upsertTemplate($client, (string) $zone['id'], $domain, $settings);
        }

        return $zone;
    }

    public function deleteZone(CloudflareSetting $settings, string $zoneId): void
    {
        $client = CloudflareClient::fromSettings($settings);
        $this->requireZoneOnAccount($client, $settings, $zoneId);
        $client->deleteZone($zoneId);

        Site::query()->where('cloudflare_zone_id', $zoneId)->update([
            'cloudflare_zone_id' => null,
            'cloudflare_nameservers' => null,
            'cloudflare_zone_status' => null,
            'temporary_domain' => null,
            'dns_applied_at' => null,
        ]);
    }

    /**
     * @param  array{type: string, name: string, content: string, ttl: int, proxied: bool, priority?: int}  $record
     * @return array<string, mixed>
     */
    public function createDns(CloudflareSetting $settings, string $zoneId, array $record): array
    {
        $client = CloudflareClient::fromSettings($settings);
        $this->requireZoneOnAccount($client, $settings, $zoneId);

        return $client->createDnsRecord($zoneId, $this->recordPayload($record));
    }

    /**
     * @param  array{type: string, name: string, content: string, ttl: int, proxied: bool, priority?: int}  $record
     * @return array<string, mixed>
     */
    public function updateDns(CloudflareSetting $settings, string $zoneId, string $recordId, array $record): array
    {
        $client = CloudflareClient::fromSettings($settings);
        $this->requireZoneOnAccount($client, $settings, $zoneId);

        return $client->updateDnsRecord($zoneId, $recordId, $this->recordPayload($record));
    }

    public function deleteDns(CloudflareSetting $settings, string $zoneId, string $recordId): void
    {
        $client = CloudflareClient::fromSettings($settings);
        $this->requireZoneOnAccount($client, $settings, $zoneId);
        $client->deleteDnsRecord($zoneId, $recordId);
    }

    /**
     * @return array<string, mixed>
     */
    public function requireZoneOnAccount(CloudflareClient $client, CloudflareSetting $settings, string $zoneId): array
    {
        $zone = $client->getZone($zoneId);
        $zoneAccount = strtolower((string) data_get($zone, 'account.id', ''));
        $accountId = strtolower(trim((string) $settings->account_id));

        if ($zoneAccount === '' || $accountId === '' || $zoneAccount !== $accountId) {
            abort(404);
        }

        return $zone;
    }

    public function attachPreviewHost(Site $site, CloudflareSetting $settings): string
    {
        $wildcard = CloudflareHostname::normalize((string) ($settings->wildcard_domain ?: ''));
        if ($wildcard === '') {
            throw new SiteProvisionException(__('sites.landing.preview_unavailable'));
        }

        $host = app(PreviewHostname::class)->allocate($wildcard);
        $client = CloudflareClient::fromSettings($settings);
        $zone = $this->findCoveringZone($client, trim((string) $settings->account_id), $host);
        if ($zone === null) {
            throw new SiteProvisionException(__('sites.landing.preview_unavailable'));
        }

        $this->applyDnsForHost(
            $client,
            (string) $zone['id'],
            (string) ($zone['name'] ?? $wildcard),
            $host,
            $settings,
        );

        return $host;
    }

    public function releasePreviewHost(Site $site, CloudflareSetting $settings, string $host): void
    {
        $host = CloudflareHostname::host($host);
        if ($host === '') {
            return;
        }

        $client = CloudflareClient::fromSettings($settings);
        $zone = $this->findCoveringZone($client, trim((string) $settings->account_id), $host);
        if ($zone === null) {
            return;
        }

        $zoneId = (string) $zone['id'];
        $zoneName = (string) ($zone['name'] ?? '');
        $existing = $this->findExisting(
            $client,
            $zoneId,
            $zoneName,
            $this->originA(CloudflareDnsRecord::relative($host, $zoneName), $settings->resolvedOriginIpv4()),
        );
        if ($existing === null) {
            return;
        }

        $client->deleteDnsRecord($zoneId, (string) $existing['id']);
    }

    /**
     * Delete the origin A record Plane wrote for each host. A host that is the apex of
     * its zone only ever received the zone template, which belongs to the customer
     * zone and is left alone, as is the zone itself and the `*` record.
     *
     * @param  list<string>  $hosts
     * @return list<string> hosts whose record was deleted
     */
    public function removeHostRecords(CloudflareSetting $settings, array $hosts): array
    {
        $client = CloudflareClient::fromSettings($settings);
        $accountId = trim((string) $settings->account_id);
        $origin = $settings->resolvedOriginIpv4();
        $removed = [];

        foreach ($hosts as $host) {
            // host(), not normalize(): normalize() drops a leading "www." and would look
            // the apex record up twice, leaving every www sibling's A record behind.
            $host = CloudflareHostname::host($host);
            if ($host === '') {
                continue;
            }

            try {
                $zone = $this->findCoveringZone($client, $accountId, $host);
                if ($zone === null) {
                    continue;
                }

                $zoneName = (string) ($zone['name'] ?? '');
                if (strcasecmp($host, $zoneName) === 0) {
                    continue;
                }

                $existing = $this->findExisting(
                    $client,
                    (string) $zone['id'],
                    $zoneName,
                    $this->originA(CloudflareDnsRecord::relative($host, $zoneName), $origin),
                );
                if ($existing === null) {
                    continue;
                }

                $client->deleteDnsRecord((string) $zone['id'], (string) $existing['id']);
                $removed[] = $host;
            } catch (CloudflareApiException $exception) {
                throw $this->mapApiException($exception);
            }
        }

        return $removed;
    }

    public static function hostUnderZone(string $host, string $zoneName): bool
    {
        $host = CloudflareHostname::normalize($host);
        $zoneName = CloudflareHostname::normalize($zoneName);

        return $host !== '' && $zoneName !== ''
            && (strcasecmp($host, $zoneName) === 0 || str_ends_with($host, '.'.$zoneName));
    }

    /**
     * Covering zone for a host on another apex, else a new Free zone on that apex
     * (with the Deamon template). `$cache` keeps www siblings from listing twice.
     *
     * @param  array<string, array<string, mixed>>  $cache
     * @return array<string, mixed>
     */
    private function resolveZoneForHost(CloudflareClient $client, string $accountId, string $host, array &$cache, CloudflareSetting $settings): array
    {
        foreach ($cache as $name => $zone) {
            if (self::hostUnderZone($host, $name)) {
                return $zone;
            }
        }

        $zone = $this->findCoveringZone($client, $accountId, $host);
        if ($zone === null) {
            $apex = CloudflareHostname::apex($host);
            if ($apex === '') {
                throw new CloudflareApiException(__('cloudflare.errors.domain_required'), 422);
            }

            $zone = $this->createApexZone($client, $accountId, $apex);
            $this->upsertTemplate($client, (string) $zone['id'], $apex, $settings);
        }

        $cache[(string) ($zone['name'] ?? $host)] = $zone;

        return $zone;
    }

    /**
     * @param  array<string, mixed>|null  $zone  null clears a stale own-zone record
     */
    private function storeRowZone(Site $site, string $host, ?array $zone): void
    {
        $row = $site->domains()->where('domain', $host)->first();
        if ($row === null) {
            return;
        }

        if ($zone === null) {
            if ($row->cloudflare_zone_id === null) {
                return;
            }

            $row->forceFill([
                'cloudflare_zone_id' => null,
                'cloudflare_zone_status' => null,
                'cloudflare_nameservers' => null,
            ])->save();

            return;
        }

        $status = strtolower(trim((string) ($zone['status'] ?? '')));
        $row->forceFill([
            'cloudflare_zone_id' => (string) $zone['id'],
            'cloudflare_zone_status' => $status !== '' ? $status : null,
            'cloudflare_nameservers' => $this->nameservers($zone),
        ])->save();
    }

    /**
     * Longest existing Cloudflare zone that is a suffix of the hostname.
     * Nested hosts under *.codron.co attach here. Unbound customer apexes are created separately.
     *
     * @return array<string, mixed>|null
     */
    private function findCoveringZone(CloudflareClient $client, string $accountId, string $host): ?array
    {
        foreach (CloudflareHostname::zoneCandidates($host) as $candidate) {
            $zone = $this->findExactZone($client, $accountId, $candidate);
            if ($zone !== null) {
                return $zone;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findExactZone(CloudflareClient $client, string $accountId, string $domain): ?array
    {
        $matches = [];
        foreach ($client->listZones($accountId, $domain, 5) as $zone) {
            if (! is_array($zone)) {
                continue;
            }

            if (strcasecmp((string) ($zone['name'] ?? ''), $domain) === 0) {
                $matches[] = $zone;
            }
        }

        if (count($matches) > 1) {
            throw new CloudflareApiException(__('cloudflare.errors.duplicate_zone', ['domain' => $domain]), 409);
        }

        return $matches[0] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    private function findOrCreateZone(CloudflareClient $client, string $accountId, string $domain): array
    {
        $existing = $this->findExactZone($client, $accountId, $domain);
        if ($existing !== null) {
            return $existing;
        }

        return $this->createApexZone($client, $accountId, $domain);
    }

    /**
     * @return array<string, mixed>
     */
    private function createApexZone(CloudflareClient $client, string $accountId, string $domain): array
    {
        try {
            return $client->createZone($accountId, $domain);
        } catch (CloudflareApiException $exception) {
            if ($exception->isForbidden()) {
                throw new CloudflareApiException(__('cloudflare.errors.zone_edit_missing'), 403, $exception->payload, $exception);
            }

            throw $exception;
        }
    }

    private function applyDnsForHost(
        CloudflareClient $client,
        string $zoneId,
        string $zoneName,
        string $host,
        CloudflareSetting $settings,
    ): void {
        $origin = $settings->resolvedOriginIpv4();

        if (strcasecmp($host, $zoneName) === 0) {
            $this->upsertTemplate($client, $zoneId, $zoneName, $settings);

            return;
        }

        $this->ensureStarA($client, $zoneId, $zoneName, $origin);
        $this->upsertRecord($client, $zoneId, $zoneName, $this->originA(CloudflareDnsRecord::relative($host, $zoneName), $origin));
    }

    private function ensureStarA(CloudflareClient $client, string $zoneId, string $zoneName, string $origin): void
    {
        $this->upsertRecord($client, $zoneId, $zoneName, $this->originA('*', $origin));
    }

    /**
     * @return array{type: string, name: string, content: string, ttl: int, proxied: bool}
     */
    private function originA(string $name, string $origin): array
    {
        return [
            'type' => 'A',
            'name' => $name,
            'content' => $origin,
            'ttl' => 1,
            'proxied' => false,
        ];
    }

    private function upsertTemplate(CloudflareClient $client, string $zoneId, string $zoneName, CloudflareSetting $settings): void
    {
        $records = CloudflareDnsDefault::templateRecords();
        $mailEnabled = (bool) $settings->mail_template_enabled;
        $templateMx = [];
        foreach ($records as $record) {
            if ($record['type'] === 'MX') {
                $templateMx[] = self::recordContent($record['content']);
            }
        }

        foreach ($records as $record) {
            if (in_array($record['type'], ['A', 'AAAA'], true)) {
                // The site has to answer on the origin, so address records follow it.
                $this->upsertRecord($client, $zoneId, $zoneName, $record);

                continue;
            }

            if (! $mailEnabled && self::isMailRecord($record)) {
                continue;
            }

            $this->addTemplateRecordIfMissing($client, $zoneId, $zoneName, $record, $templateMx);
        }
    }

    /**
     * Mail and verification rows sit next to records the customer may already
     * depend on. Editing them, or adding a second one beside them, breaks mail:
     * two SPF or two DMARC records are invalid (RFC 7208 section 3.2) and an
     * extra MX splits delivery. So a template row is only added when nothing of
     * its kind is there yet; an existing record is never replaced.
     *
     * @param  array{type: string, name: string, content: string, ttl: int, proxied: bool, priority?: int}  $record
     * @param  list<string>  $templateMx
     */
    private function addTemplateRecordIfMissing(
        CloudflareClient $client,
        string $zoneId,
        string $zoneName,
        array $record,
        array $templateMx,
    ): void {
        try {
            $rows = $client->listDnsRecords($zoneId, [
                'type' => $record['type'],
                'name' => CloudflareDnsRecord::host($record['name'], $zoneName),
                'per_page' => 50,
            ]);

            if ($this->templateSlotTaken($rows, $record, $templateMx)) {
                return;
            }

            $client->createDnsRecord($zoneId, $this->recordPayload($record));
        } catch (CloudflareApiException $exception) {
            if ($exception->isForbidden()) {
                throw new CloudflareApiException(__('cloudflare.errors.dns_edit_missing'), 403, $exception->payload, $exception);
            }

            if ($exception->isDuplicateRecord()) {
                return;
            }

            throw $exception;
        }
    }

    /**
     * @param  array<int, mixed>  $rows
     * @param  array{type: string, name: string, content: string, ttl: int, proxied: bool, priority?: int}  $record
     * @param  list<string>  $templateMx
     */
    private function templateSlotTaken(array $rows, array $record, array $templateMx): bool
    {
        $wanted = self::recordContent($record['content']);
        $txtKind = $record['type'] === 'TXT' ? self::txtKind($wanted) : null;

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $content = self::recordContent((string) ($row['content'] ?? ''));
            if ($content === $wanted) {
                return true;
            }

            if ($record['type'] === 'TXT') {
                if ($txtKind !== null && str_starts_with($content, $txtKind)) {
                    Log::notice('cloudflare.template_record_kept', ['type' => 'TXT', 'name' => $record['name'], 'kind' => $txtKind]);

                    return true;
                }

                continue;
            }

            if ($record['type'] === 'MX') {
                // Our own template MX rows may coexist; any other MX means the
                // customer routes mail elsewhere and we stay out of it.
                if (! in_array($content, $templateMx, true)) {
                    Log::notice('cloudflare.template_record_kept', ['type' => 'MX', 'name' => $record['name']]);

                    return true;
                }

                continue;
            }

            Log::notice('cloudflare.template_record_kept', ['type' => $record['type'], 'name' => $record['name']]);

            return true;
        }

        return false;
    }

    /**
     * @param  array{type: string, name: string, content: string}  $record
     */
    private static function isMailRecord(array $record): bool
    {
        if ($record['type'] === 'MX') {
            return true;
        }

        if ($record['type'] === 'TXT') {
            return self::txtKind(self::recordContent($record['content'])) !== null;
        }

        return $record['type'] === 'CNAME' && str_contains(strtolower($record['name']), '_domainkey');
    }

    private static function txtKind(string $content): ?string
    {
        foreach (['v=spf1', 'v=dmarc1'] as $kind) {
            if (str_starts_with($content, $kind)) {
                return $kind;
            }
        }

        return null;
    }

    private static function recordContent(string $content): string
    {
        return strtolower(trim(trim($content), '"'));
    }

    /**
     * @param  array{type: string, name: string, content: string, ttl: int, proxied: bool, priority?: int}  $record
     */
    private function upsertRecord(CloudflareClient $client, string $zoneId, string $zoneName, array $record): void
    {
        $payload = $this->recordPayload($record);

        try {
            $existing = $this->findExisting($client, $zoneId, $zoneName, $record);
            if ($existing !== null) {
                $this->updateIfChanged($client, $zoneId, $existing, $payload);

                return;
            }

            $client->createDnsRecord($zoneId, $payload);
        } catch (CloudflareApiException $exception) {
            if ($exception->isForbidden()) {
                throw new CloudflareApiException(__('cloudflare.errors.dns_edit_missing'), 403, $exception->payload, $exception);
            }

            if ($exception->isDuplicateRecord()) {
                $existing = $this->findExisting($client, $zoneId, $zoneName, $record);
                if ($existing !== null) {
                    $this->updateIfChanged($client, $zoneId, $existing, $payload);

                    return;
                }
            }

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $existing
     * @param  array<string, mixed>  $payload
     */
    private function updateIfChanged(CloudflareClient $client, string $zoneId, array $existing, array $payload): void
    {
        $sameContent = (string) ($existing['content'] ?? '') === (string) ($payload['content'] ?? '');
        $sameProxied = ((bool) ($existing['proxied'] ?? false)) === (bool) ($payload['proxied'] ?? false);

        if ($sameContent && $sameProxied) {
            return;
        }

        $client->updateDnsRecord($zoneId, (string) $existing['id'], $payload);
    }

    /**
     * @param  array{type: string, name: string, content: string, ttl: int, proxied: bool, priority?: int}  $record
     * @return array<string, mixed>|null
     */
    private function findExisting(CloudflareClient $client, string $zoneId, string $zoneName, array $record): ?array
    {
        $fqdn = CloudflareDnsRecord::host($record['name'], $zoneName);
        $rows = $client->listDnsRecords($zoneId, [
            'type' => $record['type'],
            'name' => $fqdn,
            'per_page' => 50,
        ]);

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            if (isset($record['priority']) && (int) ($row['priority'] ?? 0) !== (int) $record['priority']) {
                continue;
            }

            return $row;
        }

        return null;
    }

    /**
     * @param  array{type: string, name: string, content: string, ttl: int, proxied: bool, priority?: int}  $record
     * @return array<string, mixed>
     */
    private function recordPayload(array $record): array
    {
        $payload = [
            'type' => $record['type'],
            'name' => $record['name'],
            'content' => $record['content'],
            'ttl' => $record['ttl'] ?? 1,
        ];

        if (in_array($record['type'], ['A', 'AAAA', 'CNAME'], true)) {
            $payload['proxied'] = false;
        }

        if (isset($record['priority'])) {
            $payload['priority'] = $record['priority'];
        }

        return $payload;
    }

    public function zoneName(string $primaryDomain): string
    {
        return CloudflareHostname::normalize($primaryDomain);
    }

    /**
     * @param  array<string, mixed>  $zone
     * @return list<string>
     */
    public function nameservers(array $zone): array
    {
        $ns = $zone['name_servers'] ?? $zone['nameServers'] ?? [];
        if (! is_array($ns)) {
            return [];
        }

        return array_values(array_filter($ns, is_string(...)));
    }

    private function mapApiException(CloudflareApiException $exception): SiteProvisionException
    {
        return new SiteProvisionException($exception->getMessage(), $exception->status, $exception);
    }
}
