<?php

namespace App\Services\Cloudflare;

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
            $this->upsertTemplate($client, (string) $zone['id'], $domain, $settings);
        } catch (CloudflareApiException $exception) {
            throw $this->mapApiException($exception);
        }

        $site->cloudflare_zone_id = (string) $zone['id'];
        $site->cloudflare_nameservers = $this->nameservers($zone);
        $site->dns_applied_at = now();
        $site->save();
    }

    /**
     * @return array<string, mixed>
     */
    private function findOrCreateZone(CloudflareClient $client, string $accountId, string $domain): array
    {
        $matches = $client->listZones($accountId, $domain, 5);

        if (count($matches) > 1) {
            throw new SiteProvisionException(__('cloudflare.errors.duplicate_zone', ['domain' => $domain]));
        }

        if (count($matches) === 1) {
            return $matches[0];
        }

        try {
            return $client->createZone($accountId, $domain);
        } catch (CloudflareApiException $exception) {
            if ($exception->isForbidden()) {
                throw new SiteProvisionException(__('cloudflare.errors.zone_edit_missing'));
            }

            throw $exception;
        }
    }

    private function upsertTemplate(
        CloudflareClient $client,
        string $zoneId,
        string $zoneName,
        CloudflareSetting $settings,
    ): void {
        foreach (CloudflareDnsTemplate::records($settings->resolvedOriginIpv4(), (bool) $settings->mail_template_enabled) as $record) {
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
                    throw new SiteProvisionException(__('cloudflare.errors.dns_edit_missing'));
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
        $fqdn = $this->fqdn($record['name'], $zoneName);
        $rows = $client->listDnsRecords($zoneId, [
            'type' => $record['type'],
            'name' => $fqdn,
            'per_page' => 50,
        ]);

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $content = strtolower(trim((string) ($row['content'] ?? ''), '.'));
            $wanted = strtolower(trim($record['content'], '.'));
            if ($content !== $wanted) {
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
            'ttl' => $record['ttl'],
        ];

        if (in_array($record['type'], ['A', 'AAAA', 'CNAME'], true)) {
            $payload['proxied'] = false;
        }

        if (isset($record['priority'])) {
            $payload['priority'] = $record['priority'];
        }

        return $payload;
    }

    private function fqdn(string $name, string $zoneName): string
    {
        if ($name === '@') {
            return $zoneName;
        }

        if (str_ends_with($name, '.'.$zoneName) || $name === $zoneName) {
            return $name;
        }

        return $name.'.'.$zoneName;
    }

    private function zoneName(string $primaryDomain): string
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
    private function nameservers(array $zone): array
    {
        $ns = $zone['name_servers'] ?? $zone['nameServers'] ?? [];
        if (! is_array($ns)) {
            return [];
        }

        return array_values(array_filter($ns, is_string(...)));
    }

    private function mapApiException(CloudflareApiException $exception): SiteProvisionException
    {
        if ($exception->isForbidden()) {
            return new SiteProvisionException(__('cloudflare.errors.zone_edit_missing'));
        }

        return new SiteProvisionException($exception->getMessage(), $exception->status, $exception);
    }
}
