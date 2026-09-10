<?php

namespace App\Services\Cloudflare;

use App\Models\CloudflareSetting;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;

class CloudflarePermissionProbe
{
    public const LABEL_ZONE_READ = 'DNS & Zones → Zone → Read';

    public const LABEL_ZONE_EDIT = 'DNS & Zones → Zone → Edit';

    public const LABEL_DNS_READ = 'DNS & Zones → DNS → Read';

    public const LABEL_DNS_EDIT = 'DNS & Zones → DNS → Edit';

    public function probe(CloudflareSetting $settings): CloudflareProbeResult
    {
        $client = CloudflareClient::fromSettings($settings);
        $accountId = trim((string) $settings->account_id);

        $tokenValid = false;
        $zoneRead = false;
        $zoneEdit = false;
        $dnsRead = false;
        $dnsEdit = false;
        $dnsUnverified = false;
        $accountIdInvalid = false;
        $zones = [];

        try {
            $verify = $client->verifyToken();
            $tokenValid = strtolower((string) ($verify['status'] ?? '')) === 'active';
        } catch (CloudflareApiException $exception) {
            Log::info('Cloudflare token verify failed', [
                'status' => $exception->status,
            ]);
        }

        if ($tokenValid && $accountId !== '') {
            $list = $client->rawGet('/zones', [
                'account.id' => $accountId,
                'per_page' => 1,
            ]);

            if ($list->successful()) {
                $zoneRead = true;
                $zones = $this->zonesFrom($list);
            }

            $create = $client->probeZoneCreate($accountId);
            if ($create->status() === 400) {
                if ($this->isAccountNotFound($create)) {
                    $accountIdInvalid = true;
                } else {
                    $zoneEdit = true;
                }
            }

            if ($zoneRead && $zones !== []) {
                $zoneId = (string) ($zones[0]['id'] ?? '');
                if ($zoneId !== '') {
                    $dnsList = $client->rawGet('/zones/'.$zoneId.'/dns_records', ['per_page' => 1]);
                    $dnsRead = $dnsList->successful();

                    $dnsPost = $client->probeDnsCreate($zoneId);
                    if ($dnsPost->status() === 400) {
                        $dnsEdit = true;
                    }
                }
            } elseif ($zoneRead) {
                $dnsUnverified = true;
            }
        }

        $missing = [];
        if ($tokenValid && ! $accountIdInvalid) {
            if (! $zoneRead) {
                $missing[] = self::LABEL_ZONE_READ;
            }
            if (! $zoneEdit) {
                $missing[] = self::LABEL_ZONE_EDIT;
            }
            if (! $dnsUnverified) {
                if (! $dnsRead) {
                    $missing[] = self::LABEL_DNS_READ;
                }
                if (! $dnsEdit) {
                    $missing[] = self::LABEL_DNS_EDIT;
                }
            }
        }

        $payload = [
            'token_valid' => $tokenValid,
            'zone_read' => $zoneRead,
            'zone_edit' => $zoneEdit,
            'dns_read' => $dnsRead,
            'dns_edit' => $dnsEdit,
            'dns_unverified' => $dnsUnverified,
            'account_id_invalid' => $accountIdInvalid,
            'missing' => $missing,
        ];

        return new CloudflareProbeResult(
            tokenValid: $tokenValid,
            zoneRead: $zoneRead,
            zoneEdit: $zoneEdit,
            dnsRead: $dnsRead,
            dnsEdit: $dnsEdit,
            dnsUnverified: $dnsUnverified,
            accountIdInvalid: $accountIdInvalid,
            missingLabels: $missing,
            payload: $payload,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function zonesFrom(Response $response): array
    {
        $result = $response->json('result');
        if (! is_array($result) || ! array_is_list($result)) {
            return [];
        }

        return array_values(array_filter($result, is_array(...)));
    }

    private function isAccountNotFound(Response $response): bool
    {
        $encoded = strtolower((string) json_encode($response->json(), JSON_UNESCAPED_SLASHES));

        if ($encoded === '' || $encoded === 'null') {
            return false;
        }

        $mentionsAccount = str_contains($encoded, 'account');
        $notFound = str_contains($encoded, 'not found')
            || str_contains($encoded, 'could not find')
            || str_contains($encoded, 'unknown account')
            || str_contains($encoded, 'invalid account');

        return $mentionsAccount && $notFound;
    }
}
