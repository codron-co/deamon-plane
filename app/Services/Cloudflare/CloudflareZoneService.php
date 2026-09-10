<?php

namespace App\Services\Cloudflare;

use App\Models\CloudflareDnsDefault;
use App\Models\CloudflareSetting;
use App\Models\Site;
use App\Services\Sites\SiteProvisionException;

class CloudflareZoneService
{
    public function __construct(
        private readonly PreviewHostname $previewHostnames,
    ) {}

    public function ensureZoneAndDns(Site $site, CloudflareSetting $settings): void
    {
        $requested = CloudflareHostname::normalize((string) $site->primary_domain);
        if ($requested === '') {
            throw new SiteProvisionException(__('cloudflare.errors.domain_required'));
        }

        $client = CloudflareClient::fromSettings($settings);
        $accountId = trim((string) $settings->account_id);

        try {
            $zone = $this->findCoveringZone($client, $accountId, $requested);
            $host = $requested;

            if ($zone === null) {
                $wildcard = $settings->resolvedWildcardDomain();
                if ($wildcard === '') {
                    throw new CloudflareApiException(__('cloudflare.errors.wildcard_unavailable', [
                        'domain' => '—',
                    ]), 422);
                }

                $preview = $this->previewHostnames->allocate($wildcard);
                $zone = $this->findCoveringZone($client, $accountId, $preview);
                if ($zone === null) {
                    throw new CloudflareApiException(__('cloudflare.errors.wildcard_unavailable', [
                        'domain' => $wildcard,
                    ]), 422);
                }

                $this->promotePreviewDomain($site, $requested, $preview);
                $host = $preview;
            }

            $zoneName = (string) ($zone['name'] ?? '');
            $this->applyDnsForHost($client, (string) $zone['id'], $zoneName, $host, $settings);

            $site->cloudflare_zone_id = (string) $zone['id'];
            $site->cloudflare_nameservers = $this->nameservers($zone);
            $site->dns_applied_at = now();
            $site->save();
        } catch (CloudflareApiException $exception) {
            throw $this->mapApiException($exception);
        }
    }

    public function applyDefaults(CloudflareSetting $settings, string $zoneId): void
    {
        $client = CloudflareClient::fromSettings($settings);
        $zone = $this->requireZoneOnAccount($client, $settings, $zoneId);
        $this->upsertTemplate($client, $zoneId, (string) ($zone['name'] ?? ''));

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
            $this->upsertTemplate($client, (string) $zone['id'], $domain);
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

    /**
     * Longest existing Cloudflare zone that is a suffix of the hostname.
     * Provision never creates zones — NS for the wildcard (*.codron.co) is infra, not Plane.
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
            $this->upsertTemplate($client, $zoneId, $zoneName);

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

    private function upsertTemplate(CloudflareClient $client, string $zoneId, string $zoneName): void
    {
        foreach (CloudflareDnsDefault::templateRecords() as $record) {
            $this->upsertRecord($client, $zoneId, $zoneName, $record);
        }
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

    private function promotePreviewDomain(Site $site, string $requested, string $preview): void
    {
        if ($requested === $preview) {
            return;
        }

        $site->domains()->where('is_primary', true)->update(['is_primary' => false]);

        $site->domains()->updateOrCreate(
            ['domain' => $requested],
            ['is_primary' => false],
        );

        $site->domains()->updateOrCreate(
            ['domain' => $preview],
            ['is_primary' => true],
        );

        $site->primary_domain = $preview;
    }

    private function mapApiException(CloudflareApiException $exception): SiteProvisionException
    {
        return new SiteProvisionException($exception->getMessage(), $exception->status, $exception);
    }
}
