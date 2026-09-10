<?php

namespace App\Services\Mail;

use App\Models\MailServer;
use App\Models\Site;
use App\Services\Hostinger\HostingerMailClient;
use App\Services\Hostinger\HostingerMailException;

class SiteMailOrderBinder
{
    /**
     * Hostinger Mail has no create-order endpoint. Billing purchase does not attach a domain.
     * Bind only an existing order whose domain.name matches the site primary domain exactly.
     */
    public function bind(Site $site, ?array $orders = null): SiteMailOrderBindResult
    {
        $site->loadMissing('mailServer');
        $server = $site->mailServer;

        if (! $server instanceof MailServer || ! $server->isHostingerReady()) {
            return $this->persist($site, null, null, SiteMailOrderBindResult::cleared());
        }

        $domain = $this->normalizedDomain((string) $site->primary_domain);
        if ($domain === '') {
            return $this->persist($site, null, null, SiteMailOrderBindResult::unmatched());
        }

        try {
            $catalog = $orders ?? HostingerMailClient::fromServer($server)->listOrders($domain);
        } catch (HostingerMailException) {
            return SiteMailOrderBindResult::lookupFailed();
        }

        $matched = $this->matchExactDomain($catalog, $domain);
        if ($matched === null) {
            return $this->persist($site, null, null, SiteMailOrderBindResult::unmatched());
        }

        return $this->persist(
            $site,
            $matched['id'],
            $matched['domain'],
            SiteMailOrderBindResult::matched($matched['id'], (string) $matched['domain']),
        );
    }

    /**
     * @param  list<array{id: string, domain: string|null, status: string|null, seats: int|null}>  $orders
     */
    public function bindAssignedSites(MailServer $server, array $orders): void
    {
        $server->sites()->each(function (Site $site) use ($orders): void {
            $this->bind($site, $orders);
        });
    }

    public function clear(Site $site): void
    {
        $this->persist($site, null, null, SiteMailOrderBindResult::cleared());
    }

    /**
     * @param  list<array{id: string, domain: string|null, status: string|null, seats: int|null}>  $orders
     * @return array{id: string, domain: string|null, status: string|null, seats: int|null}|null
     */
    private function matchExactDomain(array $orders, string $domain): ?array
    {
        foreach ($orders as $order) {
            $orderDomain = $this->normalizedDomain((string) ($order['domain'] ?? ''));
            if ($orderDomain !== '' && strcasecmp($orderDomain, $domain) === 0) {
                return $order;
            }
        }

        return null;
    }

    private function persist(Site $site, ?string $orderId, ?string $mailDomain, SiteMailOrderBindResult $result): SiteMailOrderBindResult
    {
        $site->hostinger_order_id = $orderId;
        $site->mail_domain = $mailDomain;
        $site->save();

        return $result;
    }

    private function normalizedDomain(string $domain): string
    {
        return strtolower(trim($domain));
    }
}
