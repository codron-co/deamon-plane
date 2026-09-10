<?php

namespace App\Services\Cloudflare;

use App\Models\CloudflareSetting;
use Throwable;

class CloudflarePermissionProbe
{
    public function probe(CloudflareSetting $settings): CloudflareProbeResult
    {
        $client = CloudflareClient::fromSettings($settings);
        $missing = [];
        $payload = [
            'token' => 'unknown',
            'zone_read' => false,
            'zone_edit' => false,
            'dns_read' => null,
            'dns_edit' => null,
            'zero_zones' => false,
            'account_id_invalid' => false,
            'missing' => [],
        ];

        try {
            $verify = $client->verifyToken();
        } catch (Throwable $exception) {
            $this->forgetException($exception);
            $payload['token'] = 'invalid';

            return new CloudflareProbeResult('error', __('cloudflare.flash.token_invalid'), $payload);
        }

        if (($verify['status'] ?? null) !== 'active') {
            $payload['token'] = (string) ($verify['status'] ?? 'inactive');

            return new CloudflareProbeResult('error', __('cloudflare.flash.token_invalid'), $payload);
        }

        $payload['token'] = 'active';

        try {
            $zones = $client->listZones(perPage: 1);
            $payload['zone_read'] = true;
        } catch (CloudflareApiException $exception) {
            $zones = [];
            if ($exception->status === 403) {
                $missing[] = __('cloudflare.permissions.zone_read');
                $payload['zone_read'] = false;
            } else {
                $payload['zone_read'] = false;

                return new CloudflareProbeResult(
                    'error',
                    $this->partialOrError($missing, $exception->getMessage()),
                    $this->withMissing($payload, $missing),
                );
            }
        }

        $zoneEdit = $client->probeZoneCreate();
        if ($zoneEdit->isAccountNotFound()) {
            $payload['account_id_invalid'] = true;

            return new CloudflareProbeResult('error', __('cloudflare.flash.account_id_invalid'), $this->withMissing($payload, $missing));
        }

        if ($zoneEdit->status === 403) {
            $missing[] = __('cloudflare.permissions.zone_edit');
            $payload['zone_edit'] = false;
        } elseif ($zoneEdit->status === 400) {
            $payload['zone_edit'] = true;
        } else {
            $missing[] = __('cloudflare.permissions.zone_edit');
            $payload['zone_edit'] = false;
        }

        $firstZoneId = isset($zones[0]['id']) && is_string($zones[0]['id']) ? $zones[0]['id'] : null;
        if ($firstZoneId === null) {
            $payload['zero_zones'] = true;
            $payload['dns_read'] = null;
            $payload['dns_edit'] = null;

            if ($missing !== []) {
                return new CloudflareProbeResult('error', $this->partialMessage($missing), $this->withMissing($payload, $missing));
            }

            return new CloudflareProbeResult(
                'status',
                trim(__('cloudflare.flash.ok').' '.__('cloudflare.flash.dns_unverified')),
                $this->withMissing($payload, $missing),
            );
        }

        try {
            $client->listDnsRecords($firstZoneId, perPage: 1);
            $payload['dns_read'] = true;
        } catch (CloudflareApiException $exception) {
            if ($exception->status === 403) {
                $missing[] = __('cloudflare.permissions.dns_read');
                $payload['dns_read'] = false;
            } else {
                $payload['dns_read'] = false;

                return new CloudflareProbeResult(
                    'error',
                    $this->partialOrError($missing, $exception->getMessage()),
                    $this->withMissing($payload, $missing),
                );
            }
        }

        $dnsEdit = $client->probeDnsCreate($firstZoneId);
        if ($dnsEdit->status === 403) {
            $missing[] = __('cloudflare.permissions.dns_edit');
            $payload['dns_edit'] = false;
        } elseif ($dnsEdit->status === 400) {
            $payload['dns_edit'] = true;
        } else {
            $missing[] = __('cloudflare.permissions.dns_edit');
            $payload['dns_edit'] = false;
        }

        $payload = $this->withMissing($payload, $missing);

        if ($missing !== []) {
            return new CloudflareProbeResult('error', $this->partialMessage($missing), $payload);
        }

        return new CloudflareProbeResult('status', __('cloudflare.flash.ok'), $payload);
    }

    /**
     * @param  list<string>  $missing
     */
    private function partialMessage(array $missing): string
    {
        return __('cloudflare.flash.partial').' '.implode(' ', $missing);
    }

    /**
     * @param  list<string>  $missing
     */
    private function partialOrError(array $missing, string $fallback): string
    {
        return $missing !== [] ? $this->partialMessage($missing) : $fallback;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $missing
     * @return array<string, mixed>
     */
    private function withMissing(array $payload, array $missing): array
    {
        $payload['missing'] = $missing;

        return $payload;
    }

    private function forgetException(Throwable $exception): void
    {
        unset($exception);
    }
}
