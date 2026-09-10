<?php

namespace App\Services\Cloudflare;

use App\Models\CloudflareDnsDefault;
use App\Models\CloudflareSetting;
use App\Models\Site;
use App\Services\Sites\SiteProvisionException;

class CloudflareZoneService
{
    public function ensureZoneAndDns(Site $site, CloudflareSetting $settings): void
    {
        $domain = $this->zoneName((string) $site->primary_domain);
        if ($domain === '') {
            throw new SiteProvisionException(__('cloudflare.errors.domain_required'));
        }

        $client = CloudflareClient::fromSettings($settings);
        $accountId = trim((string) $settings->account_id);

        try {
            $zone = $this->findOrCreateZone($client, $accountId, $domain);
            $this->upsertTemplate($client, (string) $zone['id'], $domain);
        } catch (CloudflareApiException $exception) {
            throw $this->mapApiException($exception);
        }

        $site->cloudflare_zone_id = (string) $zone['id'];
        $site->cloudflare_nameservers = $this->nameservers($zone);
        $site->dns_applied_at = now();
        $site->save();
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
     * @return array<string, mixed>
     */
    private function findOrCreateZone(CloudflareClient $client, string $accountId, string $domain): array
    {
        $matches = $client->listZones($accountId, $domain, 5);

        if (count($matches) > 1) {
            throw new CloudflareApiException(__('cloudflare.errors.duplicate_zone', ['domain' => $domain]), 409);
        }

        if (count($matches) === 1) {
            return $matches[0];
        }

        try {
            return $client->createZone($accountId, $domain);
        } catch (CloudflareApiException $exception) {
            if ($exception->isForbidden()) {
                throw new CloudflareApiException(__('cloudflare.errors.zone_edit_missing'), 403, $exception->payload, $exception);
            }

            throw $exception;
        }
    }

    private function upsertTemplate(CloudflareClient $client, string $zoneId, string $zoneName): void
    {
        foreach (CloudflareDnsDefault::templateRecords() as $record) {
            $payload = $this->recordPayload($record);

            try {
                $existing = $this->findExisting($client, $zoneId, $zoneName, $record);
                if ($existing !== null) {
                    $client->updateDnsRecord($zoneId, (string) $existing['id'], $payload);

                    continue;
                }

                $client->createDnsRecord($zoneId, $payload);
            } catch (CloudflareApiException $exception) {
                if ($exception->isForbidden()) {
                    throw new CloudflareApiException(__('cloudflare.errors.dns_edit_missing'), 403, $exception->payload, $exception);
                }

                throw $exception;
            }
        }
    }

    /**
     * @param  array{type: string, name: string, content: string, ttl: int, proxied: bool, priority?: int}  $record
     * @return array<string, mixed>|null
     */
    private function findExisting(CloudflareClient $client, string $zoneId, string $zoneName, array $record): ?array
    {
        $fqdn = CloudflareDnsNames::fqdn($record['name'], $zoneName);
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
        $host = strtolower(trim($primaryDomain));
        $host = (string) preg_replace('#^https?://#i', '', $host);
        $host = explode('/', $host)[0] ?? '';
        $host = explode(':', $host)[0] ?? '';
        $host = rtrim($host, '.');

        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }

        return $host;
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
