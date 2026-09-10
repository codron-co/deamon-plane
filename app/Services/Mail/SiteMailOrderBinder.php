<?php

namespace App\Services\Mail;

use App\Models\MailServer;
use App\Models\Site;
use App\Services\Hostinger\HostingerMailClient;
use App\Services\Hostinger\HostingerMailException;

class SiteMailOrderBinder
{
    /**
     * Hostinger Mail has no create-order endpoint. Bind existing catalog rows
     * the operator selects, or auto-match the site primary domain when none exist.
     */
    public function bind(Site $site, ?array $orders = null): SiteMailOrderBindResult
    {
        $site->loadMissing(['mailServer', 'mailBindings']);
        $server = $site->mailServer;

        if (! $server instanceof MailServer || ! $server->isHostingerReady()) {
            return $this->clear($site);
        }

        if ($site->mailBindings->isNotEmpty()) {
            $this->syncPrimaryColumns($site);
            $first = $site->mailBindings->first();

            return SiteMailOrderBindResult::matched(
                (string) $first->hostinger_order_id,
                (string) $first->mail_domain,
            );
        }

        $domain = $this->normalizedDomain((string) $site->primary_domain);
        if ($domain === '') {
            $this->replaceBindings($site, []);

            return SiteMailOrderBindResult::unmatched();
        }

        try {
            $catalog = $orders ?? HostingerMailClient::fromServer($server)->listOrders($domain);
        } catch (HostingerMailException) {
            return SiteMailOrderBindResult::lookupFailed();
        }

        $matched = $this->matchExactDomain($catalog, $domain);
        if ($matched === null) {
            $this->replaceBindings($site, []);

            return SiteMailOrderBindResult::unmatched();
        }

        $this->replaceBindings($site, [[
            'hostinger_order_id' => $matched['id'],
            'mail_domain' => (string) $matched['domain'],
        ]]);

        return SiteMailOrderBindResult::matched($matched['id'], (string) $matched['domain']);
    }

    /**
     * @param  list<string>  $orderIds
     * @param  list<array{id: string, domain: string|null, status: string|null, seats: int|null}>|null  $catalog
     */
    public function bindSelected(Site $site, array $orderIds, ?array $catalog = null): SiteMailOrderBindResult
    {
        $site->loadMissing(['mailServer', 'mailBindings']);
        $server = $site->mailServer;

        if (! $server instanceof MailServer || ! $server->isHostingerReady()) {
            return $this->clear($site);
        }

        $catalogById = $this->catalogById($server, $catalog, $site);

        $rows = [];
        $seen = [];
        foreach ($orderIds as $orderId) {
            $orderId = trim((string) $orderId);
            if ($orderId === '' || isset($seen[$orderId]) || ! isset($catalogById[$orderId])) {
                continue;
            }

            $order = $catalogById[$orderId];
            $domain = $this->normalizedDomain((string) ($order['domain'] ?? ''));
            if ($domain === '') {
                continue;
            }

            $seen[$orderId] = true;
            $rows[] = [
                'hostinger_order_id' => $order['id'],
                'mail_domain' => $domain,
            ];
        }

        $this->replaceBindings($site, $rows);

        if ($rows === []) {
            return SiteMailOrderBindResult::unmatched();
        }

        return SiteMailOrderBindResult::matched($rows[0]['hostinger_order_id'], $rows[0]['mail_domain']);
    }

    /**
     * @param  list<array{id: string, domain: string|null, status: string|null, seats: int|null}>  $orders
     */
    public function bindAssignedSites(MailServer $server, array $orders): void
    {
        $server->sites()->with('mailBindings')->each(function (Site $site) use ($orders): void {
            if ($site->mailBindings->isNotEmpty()) {
                $this->syncPrimaryColumns($site);

                return;
            }

            $this->bind($site, $orders);
        });
    }

    /**
     * @return list<array{id: string, domain: string|null, status: string|null, seats: int|null}>
     */
    public function refreshCatalog(MailServer $server): array
    {
        $orders = HostingerMailClient::fromServer($server)->listOrders();
        $this->storeProbe($server, $orders);

        return $orders;
    }

    /**
     * @return list<array{id: string, domain: string|null, status: string|null, seats: int|null}>
     */
    public function optionsForSite(Site $site): array
    {
        $site->loadMissing(['mailServer', 'mailBindings']);
        $byId = [];

        if ($site->mailServer instanceof MailServer) {
            foreach ($site->mailServer->probedOrders() as $order) {
                $byId[$order['id']] = $order;
            }
        }

        foreach ($site->mailBindings as $binding) {
            $byId[$binding->hostinger_order_id] ??= [
                'id' => $binding->hostinger_order_id,
                'domain' => $binding->mail_domain,
                'status' => null,
                'seats' => null,
            ];
        }

        if ($byId === [] && filled($site->hostinger_order_id) && filled($site->mail_domain)) {
            $byId[(string) $site->hostinger_order_id] = [
                'id' => (string) $site->hostinger_order_id,
                'domain' => (string) $site->mail_domain,
                'status' => null,
                'seats' => null,
            ];
        }

        return array_values($byId);
    }

    public function clear(Site $site): SiteMailOrderBindResult
    {
        $this->replaceBindings($site, []);

        return SiteMailOrderBindResult::cleared();
    }

    /**
     * @param  list<array{id: string, domain: string|null, status: string|null, seats: int|null}>|null  $catalog
     * @return array<string, array{id: string, domain: string|null, status: string|null, seats: int|null}>
     */
    private function catalogById(MailServer $server, ?array $catalog, Site $site): array
    {
        $catalog ??= $server->probedOrders();
        if ($catalog === []) {
            try {
                $catalog = $this->refreshCatalog($server);
            } catch (HostingerMailException) {
                $catalog = [];
            }
        }

        $byId = [];
        foreach ($catalog as $order) {
            if (! is_array($order) || ! is_string($order['id'] ?? null) || $order['id'] === '') {
                continue;
            }
            $byId[$order['id']] = $order;
        }

        foreach ($site->mailBindings as $binding) {
            $byId[$binding->hostinger_order_id] ??= [
                'id' => $binding->hostinger_order_id,
                'domain' => $binding->mail_domain,
                'status' => null,
                'seats' => null,
            ];
        }

        return $byId;
    }

    /**
     * @param  list<array{hostinger_order_id: string, mail_domain: string}>  $rows
     */
    private function replaceBindings(Site $site, array $rows): void
    {
        $site->mailBindings()->delete();

        foreach ($rows as $row) {
            $site->mailBindings()->create($row);
        }

        $site->unsetRelation('mailBindings');
        $site->load('mailBindings');
        $this->syncPrimaryColumns($site);
    }

    private function syncPrimaryColumns(Site $site): void
    {
        $site->loadMissing('mailBindings');
        $first = $site->mailBindings->first();
        $site->hostinger_order_id = $first?->hostinger_order_id;
        $site->mail_domain = $first?->mail_domain;
        $site->save();
    }

    /**
     * @param  list<array{id: string, domain: string|null, status: string|null, seats: int|null}>  $orders
     */
    private function storeProbe(MailServer $server, array $orders): void
    {
        $server->last_probe_at = now();
        $server->last_probe_payload = [
            'ok' => true,
            'order_count' => count($orders),
            'orders' => $orders,
        ];
        $server->save();
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

    private function normalizedDomain(string $domain): string
    {
        return strtolower(trim($domain));
    }
}
