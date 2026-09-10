<?php

namespace App\Services\Hostinger;

use App\Models\MailServer;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class HostingerMailClient
{
    public const LOCAL_PART_PATTERN = '/^(?=[a-z0-9])(?=.*[a-z0-9]$)[a-z0-9_-]+(?:\.[a-z0-9_-]+)*$/';

    public function __construct(private readonly MailServer $server) {}

    public static function fromServer(MailServer $server): self
    {
        return new self($server);
    }

    /**
     * @return list<array{id: string, domain: string|null, status: string|null, seats: int|null}>
     */
    public function listOrders(): array
    {
        $response = $this->send('GET', '/api/mail/v1/orders');
        $payload = $response->json();
        $rows = is_array($payload) ? ($payload['data'] ?? []) : [];
        if (! is_array($rows)) {
            return [];
        }

        $orders = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! is_scalar($row['id'] ?? null)) {
                continue;
            }
            $domain = $row['domain']['name'] ?? $row['domain'] ?? null;
            $orders[] = [
                'id' => trim((string) $row['id']),
                'domain' => is_string($domain) ? strtolower(trim($domain)) : null,
                'status' => is_string($row['status'] ?? null) ? $row['status'] : null,
                'seats' => is_numeric($row['seats'] ?? null) ? (int) $row['seats'] : null,
            ];
        }

        return $orders;
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
     */
    private function send(string $method, string $path, array $payload = []): Response
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
                'GET' => $pending->get($url),
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
        $orderId = trim((string) $this->server->hostinger_order_id);
        if ($orderId === '') {
            throw new HostingerMailException('mail_not_configured', 422, 'Mail order is not selected.');
        }

        return $orderId;
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
            $domain = trim((string) $this->server->mail_domain);
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
            'method' => $method,
            'path' => $path,
            'status' => $status,
        ]);
    }
}
