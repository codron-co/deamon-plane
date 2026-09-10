<?php

namespace App\Services\Hostinger;

use App\Models\MailServer;
use App\Models\Site;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class HostingerMailClient
{
    public const LOCAL_PART_PATTERN = '/^(?=[a-z0-9])(?=.*[a-z0-9]$)[a-z0-9_-]+(?:\.[a-z0-9_-]+)*$/';

    public function __construct(
        private readonly MailServer $server,
        private readonly ?string $orderId = null,
        private readonly ?string $mailDomain = null,
        private readonly ?string $siteId = null,
    ) {}

    public static function fromServer(MailServer $server): self
    {
        return new self($server);
    }

    public static function forSite(Site $site, ?string $domain = null): self
    {
        $server = $site->mailServer;
        if (! $server instanceof MailServer) {
            throw new HostingerMailException('mail_not_configured', 422, 'Mail is not configured for this site.');
        }

        $site->loadMissing('mailBindings');
        $orderId = is_string($site->hostinger_order_id) ? $site->hostinger_order_id : null;
        $mailDomain = is_string($site->mail_domain) ? $site->mail_domain : null;

        if (is_string($domain) && $domain !== '') {
            $wanted = strtolower(trim($domain));
            $binding = $site->mailBindings->first(
                static fn ($row): bool => strcasecmp((string) $row->mail_domain, $wanted) === 0,
            );
            if ($binding !== null) {
                $orderId = $binding->hostinger_order_id;
                $mailDomain = $binding->mail_domain;
            } elseif (
                filled($site->hostinger_order_id)
                && is_string($site->mail_domain)
                && strcasecmp($site->mail_domain, $wanted) === 0
            ) {
                $orderId = $site->hostinger_order_id;
                $mailDomain = $site->mail_domain;
            } else {
                throw new HostingerMailException('mail_not_configured', 422, 'Mail domain is not bound to this site.');
            }
        } elseif ($site->mailBindings->isNotEmpty()) {
            $first = $site->mailBindings->first();
            $orderId = $first->hostinger_order_id;
            $mailDomain = $first->mail_domain;
        }

        return new self($server, $orderId, $mailDomain, (string) $site->id);
    }

    /**
     * @return list<array{id: string, email: string, local_part: string|null, domain: string|null}>
     */
    public static function listAllMailboxes(Site $site): array
    {
        $site->loadMissing('mailBindings');
        $bindings = $site->mailBindings;
        if ($bindings->isEmpty()) {
            if (! $site->hasHostingerMailOrder()) {
                return [];
            }

            return array_map(static function (array $box) use ($site): array {
                $box['domain'] = is_string($site->mail_domain) ? $site->mail_domain : null;

                return $box;
            }, self::forSite($site)->listMailboxes());
        }

        $out = [];
        $seen = [];
        foreach ($bindings as $binding) {
            foreach (self::forSite($site, $binding->mail_domain)->listMailboxes() as $box) {
                if (isset($seen[$box['id']])) {
                    continue;
                }
                $seen[$box['id']] = true;
                $box['domain'] = $binding->mail_domain;
                $out[] = $box;
            }
        }

        return $out;
    }

    /**
     * @return list<array{id: string, domain: string|null, status: string|null, seats: int|null}>
     */
    public function listOrders(?string $domain = null): array
    {
        $orders = [];
        $page = 1;
        $lastPage = 1;

        do {
            $query = [
                'page' => $page,
                'per_page' => 100,
            ];
            if (is_string($domain) && $domain !== '') {
                $query['domain'] = strtolower(trim($domain));
            }

            $response = $this->send('GET', '/api/mail/v1/orders', [], $query);
            $payload = $response->json();
            $rows = is_array($payload) ? ($payload['data'] ?? []) : [];
            if (! is_array($rows)) {
                break;
            }

            foreach ($rows as $row) {
                $normalized = $this->normalizeOrder($row);
                if ($normalized !== null) {
                    $orders[] = $normalized;
                }
            }

            $meta = is_array($payload) ? ($payload['meta'] ?? []) : [];
            $lastPage = is_numeric($meta['last_page'] ?? null)
                ? (int) $meta['last_page']
                : $this->inferredLastPage($meta, $page);
            $page++;
        } while ($page <= $lastPage && $page <= 20);

        return $orders;
    }

    /**
     * @return array{id: string, domain: string|null, status: string|null, seats: int|null}|null
     */
    public function findOrderByDomain(string $domain): ?array
    {
        $domain = strtolower(trim($domain));
        if ($domain === '') {
            return null;
        }

        foreach ($this->listOrders($domain) as $order) {
            if (is_string($order['domain']) && strcasecmp($order['domain'], $domain) === 0) {
                return $order;
            }
        }

        return null;
    }

    /**
     * @return list<array{id: string, email: string, local_part: string|null}>
     */
    public function listMailboxes(): array
    {
        $orderId = $this->orderId();
        $response = $this->send('GET', '/api/mail/v1/orders/'.$orderId.'/mailboxes');
        $payload = $response->json();
        $rows = is_array($payload) ? ($payload['data'] ?? $payload['mailboxes'] ?? []) : [];
        if (! is_array($rows)) {
            return [];
        }

        $mailboxes = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $normalized = $this->normalizeMailbox($row);
            if ($normalized !== null) {
                $mailboxes[] = $normalized;
            }
        }

        return $mailboxes;
    }

    /**
     * @return array{id: string, email: string, local_part: string|null}
     */
    public function createMailbox(string $localPart, string $password): array
    {
        $orderId = $this->orderId();
        $response = $this->send('POST', '/api/mail/v1/orders/'.$orderId.'/mailboxes', [
            'local_part' => $localPart,
            'password' => $password,
        ]);

        $payload = $response->json();
        $item = is_array($payload) ? ($payload['data'] ?? $payload) : null;
        if (! is_array($item)) {
            throw HostingerMailException::fromStatus(502);
        }

        $normalized = $this->normalizeMailbox($item, $localPart);
        if ($normalized === null) {
            throw HostingerMailException::fromStatus(502);
        }

        return $normalized;
    }

    public function changePassword(string $mailboxId, string $password): void
    {
        $this->send('PATCH', '/api/mail/v1/mailboxes/'.$mailboxId.'/password', [
            'password' => $password,
        ]);
    }

    public function deleteMailbox(string $mailboxId): void
    {
        $this->send('DELETE', '/api/mail/v1/mailboxes/'.$mailboxId);
    }

    /**
     * @return array{id: string, domain: string|null, status: string|null, seats: int|null}|null
     */
    public function findOrder(?string $orderId): ?array
    {
        foreach ($this->listOrders() as $order) {
            if ($orderId !== null && $orderId !== '' && hash_equals($order['id'], $orderId)) {
                return $order;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $query
     */
    private function send(string $method, string $path, array $payload = [], array $query = []): Response
    {
        $token = (string) $this->server->api_token;
        if ($token === '') {
            throw new HostingerMailException('mail_not_configured', 422, 'Mail server has no API token.');
        }

        $base = rtrim((string) config('ops.hostinger.api_base', 'https://developers.hostinger.com'), '/');
        $timeout = max(1, (int) config('ops.hostinger.timeout', 15));
        $method = strtoupper($method);
        $url = $base.$path;

        try {
            $pending = Http::timeout($timeout)
                ->acceptJson()
                ->withToken($token);

            $response = match ($method) {
                'GET' => $pending->get($url, $query),
                'POST' => $pending->post($url, $payload),
                'PATCH' => $pending->patch($url, $payload),
                'DELETE' => $pending->delete($url),
                default => throw HostingerMailException::fromStatus(502),
            };
        } catch (ConnectionException) {
            $this->logFailure($method, $path);

            throw new HostingerMailException('upstream_error', 504, 'Mail provider timed out.');
        } catch (HostingerMailException $exception) {
            throw $exception;
        } catch (Throwable) {
            $this->logFailure($method, $path);

            throw HostingerMailException::fromStatus(502);
        }

        if ($response->failed()) {
            $this->logFailure($method, $path, $response->status());

            throw HostingerMailException::fromStatus($response->status());
        }

        return $response;
    }

    private function orderId(): string
    {
        $orderId = trim((string) $this->orderId);
        if ($orderId === '') {
            throw new HostingerMailException('mail_not_configured', 422, 'Mail order is not selected.');
        }

        return $orderId;
    }

    /**
     * @return array{id: string, domain: string|null, status: string|null, seats: int|null}|null
     */
    private function normalizeOrder(mixed $row): ?array
    {
        if (! is_array($row) || ! is_scalar($row['id'] ?? null)) {
            return null;
        }

        $id = trim((string) $row['id']);
        if ($id === '') {
            return null;
        }

        $domain = $row['domain']['name'] ?? $row['domain'] ?? null;

        return [
            'id' => $id,
            'domain' => is_string($domain) ? strtolower(trim($domain)) : null,
            'status' => is_string($row['status'] ?? null) ? $row['status'] : null,
            'seats' => is_numeric($row['seats'] ?? null) ? (int) $row['seats'] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function inferredLastPage(array $meta, int $page): int
    {
        $perPage = is_numeric($meta['per_page'] ?? null) ? (int) $meta['per_page'] : 0;
        $total = is_numeric($meta['total'] ?? null) ? (int) $meta['total'] : 0;
        if ($perPage > 0 && $total > 0) {
            return (int) ceil($total / $perPage);
        }

        return $page;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{id: string, email: string, local_part: string|null}|null
     */
    private function normalizeMailbox(array $item, ?string $fallbackLocalPart = null): ?array
    {
        $id = $item['id'] ?? $item['mailbox_id'] ?? $item['mailboxId'] ?? null;
        if (! is_scalar($id)) {
            return null;
        }

        $id = trim((string) $id);
        if ($id === '' || preg_match('/\A[A-Za-z0-9._-]{1,128}\z/', $id) !== 1) {
            return null;
        }

        $email = $item['email'] ?? $item['address'] ?? null;
        $email = is_string($email) ? trim($email) : '';

        $localPart = $item['local_part'] ?? $item['localPart'] ?? $fallbackLocalPart;
        $localPart = is_string($localPart) ? trim($localPart) : null;

        if ($email === '' && is_string($localPart) && $localPart !== '') {
            $domain = trim((string) $this->mailDomain);
            $email = $domain !== '' ? $localPart.'@'.$domain : $localPart;
        }

        if ($email === '') {
            return null;
        }

        return [
            'id' => $id,
            'email' => $email,
            'local_part' => $localPart,
        ];
    }

    private function logFailure(string $method, string $path, ?int $status = null): void
    {
        Log::warning('hostinger.mail_request_failed', [
            'mail_server_id' => $this->server->id,
            'site_id' => $this->siteId,
            'method' => $method,
            'path' => $path,
            'status' => $status,
        ]);
    }
}
