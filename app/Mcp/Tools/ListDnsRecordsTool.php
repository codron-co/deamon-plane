<?php

namespace App\Mcp\Tools;

use App\Mcp\McpContext;
use App\Mcp\McpException;
use App\Mcp\McpModels;
use App\Services\Cloudflare\CloudflareApiException;
use App\Services\Cloudflare\CloudflareClient;
use App\Services\Cloudflare\CloudflareZoneService;

class ListDnsRecordsTool extends ReadTool
{
    public function name(): string
    {
        return 'list_dns_records';
    }

    public function description(): string
    {
        return 'Cloudflare zone with its nameservers, status and DNS records. Pass `site` for the site\'s zone, or `zone` (zone id or apex name) with an optional `account`. Record ids feed update_dns_record / delete_dns_record.';
    }

    public function inputSchema(): array
    {
        return self::schema([
            'site' => self::SITE_PROPERTY,
            'zone' => ['type' => 'string', 'description' => 'Zone id (32 hex) or apex domain, e.g. example.com.'],
            'account' => ['type' => 'string', 'description' => 'Cloudflare account id or name; default account if omitted.'],
        ]);
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $zone = trim((string) ($arguments['zone'] ?? ''));
        $account = null;

        if (filled($arguments['site'] ?? null)) {
            $site = McpModels::site($arguments['site']);
            if (blank($site->cloudflare_zone_id) || $site->cloudflare_setting_id === null) {
                throw new McpException("{$site->slug} has no Cloudflare zone yet. Run setup_site_cloudflare.", 'not_ready');
            }
            $account = McpModels::cloudflareAccount((string) $site->cloudflare_setting_id);
            $zone = (string) $site->cloudflare_zone_id;
        }

        if ($zone === '') {
            throw new McpException('Pass `site` or `zone`.', 'validation_error');
        }

        $account ??= McpModels::cloudflareAccount($arguments['account'] ?? '');
        $client = CloudflareClient::fromSettings($account);
        $zones = app(CloudflareZoneService::class);

        try {
            if (preg_match('/^[a-f0-9]{32}$/i', $zone) !== 1) {
                $match = $client->listZones($account->account_id, McpModels::host($zone))[0] ?? null;
                if (! is_array($match)) {
                    throw new McpException("No zone `{$zone}` on Cloudflare account {$account->name}.", 'not_found');
                }
                $zone = (string) $match['id'];
            }

            $zoneData = $zones->requireZoneOnAccount($client, $account, strtolower($zone));
            $records = $client->listAllDnsRecords(strtolower($zone));
        } catch (CloudflareApiException $exception) {
            throw new McpException('Cloudflare: '.$exception->getMessage(), 'upstream_error');
        }

        return [
            'account' => ['id' => $account->id, 'name' => $account->name],
            'zone' => [
                'id' => $zoneData['id'] ?? $zone,
                'name' => $zoneData['name'] ?? null,
                'status' => $zoneData['status'] ?? null,
                'nameservers' => $zones->nameservers($zoneData),
            ],
            'records' => array_map(static fn (array $row): array => [
                'id' => $row['id'] ?? null,
                'type' => $row['type'] ?? null,
                'name' => $row['name'] ?? null,
                'content' => $row['content'] ?? null,
                'ttl' => $row['ttl'] ?? null,
                'proxied' => $row['proxied'] ?? null,
                'priority' => $row['priority'] ?? null,
            ], $records),
        ];
    }
}
