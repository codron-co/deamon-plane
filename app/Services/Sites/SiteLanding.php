<?php

namespace App\Services\Sites;

use App\Models\CloudflareSetting;
use App\Models\CoolifyConnection;
use App\Models\Site;
use App\Services\Cloudflare\CloudflareAccounts;
use App\Services\Cloudflare\CloudflareApiException;
use App\Services\Cloudflare\CloudflareClient;
use App\Services\Cloudflare\CloudflareHostname;
use App\Services\Cloudflare\CloudflareZoneService;
use App\Services\Coolify\CoolifyApiException;
use App\Services\Coolify\CoolifyApplicationService;
use Throwable;

class SiteLanding
{
    public function __construct(
        private readonly CloudflareZoneService $zones,
        private readonly CoolifyApplicationService $coolify,
    ) {}

    /**
     * @param  array<string, mixed>  $zone
     */
    public function afterCloudflare(Site $site, CloudflareSetting $settings, array $zone): void
    {
        $status = strtolower(trim((string) ($zone['status'] ?? $site->cloudflare_zone_status ?? '')));
        $site->cloudflare_zone_status = $status !== '' ? $status : null;

        if (CloudflareHostname::zoneIsReady($status)) {
            $this->clearTemporaryRow($site);
            $site->temporary_domain = null;
            $site->save();

            return;
        }

        $site->save();
    }

    public function ensureTemporaryPreview(Site $site, CloudflareSetting $settings): void
    {
        if (CloudflareHostname::zoneIsReady($site->cloudflare_zone_status)) {
            return;
        }

        if (filled($site->temporary_domain)) {
            return;
        }

        $host = $this->zones->attachPreviewHost($site, $settings);
        $site->temporary_domain = $host;
        $site->domains()->updateOrCreate(
            ['domain' => $host],
            [
                'is_primary' => false,
                'is_www' => false,
                'is_temporary' => true,
            ],
        );
        $site->save();
    }

    /**
     * @return array{ready: bool, message: string, zone_status: string, nameservers: list<string>}
     */
    public function confirmDns(Site $site, ?int $actorUserId = null, ?string $ip = null): array
    {
        $settings = $this->settingsFor($site);
        $status = strtolower(trim((string) $site->cloudflare_zone_status));
        $nameservers = is_array($site->cloudflare_nameservers) ? array_values($site->cloudflare_nameservers) : [];

        if ($settings instanceof CloudflareSetting && filled($site->cloudflare_zone_id)) {
            try {
                $zone = CloudflareClient::fromSettings($settings)->getZone((string) $site->cloudflare_zone_id);
                $status = strtolower(trim((string) ($zone['status'] ?? $status)));
                $fromZone = $this->zones->nameservers($zone);
                if ($fromZone !== []) {
                    $nameservers = $fromZone;
                    $site->cloudflare_nameservers = $fromZone;
                }
            } catch (CloudflareApiException $exception) {
                throw new SiteProvisionException($exception->getMessage(), $exception->status, $exception);
            }
        }

        $site->cloudflare_zone_status = $status !== '' ? $status : $site->cloudflare_zone_status;
        $site->save();

        if (! CloudflareHostname::zoneIsReady($status)) {
            return [
                'ready' => false,
                'message' => __('sites.landing.dns_still_pending'),
                'zone_status' => $status,
                'nameservers' => $nameservers,
            ];
        }

        $temp = $site->temporary_domain;
        if ($settings instanceof CloudflareSetting && filled($temp)) {
            try {
                $this->zones->releasePreviewHost($site, $settings, (string) $temp);
            } catch (Throwable) {
                // Coolify/site row still move off the temp host.
            }
        }

        $this->clearTemporaryRow($site);
        $site->temporary_domain = null;
        $site->cloudflare_zone_status = $status !== '' ? $status : 'active';
        if (filled($temp) && filled($site->agent_base_url) && str_contains((string) $site->agent_base_url, (string) $temp)) {
            $site->agent_base_url = 'https://'.$site->primary_domain;
        }
        $site->save();
        $site->unsetRelation('domains');

        $this->syncCoolifyDomains($site);

        $site->domains()->where('is_temporary', false)->update(['verified_at' => now()]);

        $site->auditLogs()->create([
            'actor_user_id' => $actorUserId,
            'action' => 'site.dns_confirmed',
            'after' => [
                'primary_domain' => $site->primary_domain,
                'hosts' => $site->operatorHosts(),
                'removed_temporary_domain' => $temp,
            ],
            'ip' => $ip,
        ]);

        return [
            'ready' => true,
            'message' => __('sites.landing.dns_confirmed'),
            'zone_status' => (string) $site->cloudflare_zone_status,
            'nameservers' => $nameservers,
        ];
    }

    public function syncCoolifyDomains(Site $site): void
    {
        if (blank($site->coolify_app_uuid)) {
            return;
        }

        $binding = $site->fresh()?->coolifyDomainBinding() ?: $site->coolifyDomainBinding();
        if ($binding === '') {
            return;
        }

        $connection = $site->coolifyConnection ?: CoolifyConnection::default();
        $coolify = $connection instanceof CoolifyConnection
            ? CoolifyApplicationService::forConnection($connection)
            : $this->coolify;

        try {
            $coolify->setDomains((string) $site->coolify_app_uuid, $binding);
        } catch (CoolifyApiException $exception) {
            throw new SiteProvisionException($exception->getMessage(), $exception->status, $exception);
        }
    }

    public function applyAliasDns(Site $site): void
    {
        $settings = $this->settingsFor($site);
        if (! $settings instanceof CloudflareSetting) {
            return;
        }

        $this->zones->ensureZoneAndDns($site, $settings);
    }

    private function settingsFor(Site $site): ?CloudflareSetting
    {
        if (filled($site->cloudflare_setting_id)) {
            $site->loadMissing('cloudflareAccount');
            $account = $site->cloudflareAccount;
            if ($account instanceof CloudflareSetting && $account->is_enabled && $account->hasCredentials()) {
                return $account;
            }
        }

        return CloudflareAccounts::default();
    }

    private function clearTemporaryRow(Site $site): void
    {
        $site->domains()->where('is_temporary', true)->delete();
    }
}
