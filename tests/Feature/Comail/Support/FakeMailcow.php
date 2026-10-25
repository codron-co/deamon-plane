<?php

namespace Tests\Feature\Comail\Support;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * In-memory mailcow API v1 for Http::fake. Mirrors the shapes the real server
 * answers (writes: 200 + [{type, msg}], quotas MB in / bytes out, add/domain
 * also creating the DKIM key and a second add/dkim answering danger).
 */
class FakeMailcow
{
    public const BASE = 'https://mail.example.test';

    public const KEY = 'mailcow-api-key-never-show-123';

    /** @var array<string, array<string, mixed>> */
    public array $domains = [];

    /** @var array<string, array<string, mixed>> */
    public array $dkim = [];

    /** @var array<string, array<string, mixed>> */
    public array $mailboxes = [];

    /** @var array<string, string> */
    public array $passwords = [];

    /** @var array<int, array<string, mixed>> */
    public array $aliases = [];

    /** @var array<int, array<string, mixed>> */
    public array $syncjobs = [];

    /** @var array<int, array<string, mixed>> */
    public array $filters = [];

    /** @var list<array{method: string, path: string, body: mixed}> */
    public array $calls = [];

    /** @var array<string, array<string, mixed>> path => one failure element */
    public array $failOn = [];

    public bool $dkimWithDomain = true;

    /** Answer every call with this HTTP status (a broken upstream). */
    public ?int $httpStatus = null;

    private int $nextId = 1;

    public function fake(): array
    {
        return [self::BASE.'/api/v1/*' => fn (Request $request): PromiseInterface => $this->handle($request)];
    }

    public function handle(Request $request): PromiseInterface
    {
        if (($request->header('X-API-Key')[0] ?? null) !== self::KEY) {
            return Http::response(['type' => 'error', 'msg' => 'authentication failed'], 401);
        }

        if ($this->httpStatus !== null) {
            return Http::response('upstream error', $this->httpStatus);
        }

        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        $path = substr($path, strlen('/api/v1'));
        $body = json_decode($request->body(), true);
        $this->calls[] = ['method' => $request->method(), 'path' => $path, 'body' => $body];

        foreach ($this->failOn as $prefix => $failure) {
            if (str_starts_with($path, $prefix)) {
                return Http::response([$failure], 200);
            }
        }

        if ($request->method() === 'GET') {
            return Http::response($this->read($path), 200);
        }

        return Http::response($this->write($path, is_array($body) ? $body : []), 200);
    }

    public function addMailbox(string $address, int $quotaMb = 1024, int $usedBytes = 0): void
    {
        [$local, $domain] = explode('@', $address);
        $this->mailboxes[$address] = [
            'username' => $address,
            'local_part' => $local,
            'domain' => $domain,
            'name' => $local,
            'quota' => $quotaMb * 1048576,
            'quota_used' => $usedBytes,
            'percent_in_use' => 0,
            'messages' => 0,
            'active' => 1,
            'active_int' => 1,
            'created' => '2026-10-26 10:00:00',
            'last_imap_login' => 0,
        ];
        $this->aliases[$this->nextId] = ['id' => $this->nextId, 'address' => $address, 'goto' => $address, 'domain' => $domain, 'active' => 1];
        $this->nextId++;
    }

    /**
     * @return list<string>
     */
    public function paths(string $method = 'POST'): array
    {
        return array_values(array_map(
            static fn (array $call): string => $call['path'],
            array_filter($this->calls, static fn (array $call): bool => $call['method'] === $method),
        ));
    }

    /**
     * @return array<int|string, mixed>
     */
    private function read(string $path): array
    {
        if ($path === '/get/status/version') {
            return ['version' => '2026-09'];
        }
        if (preg_match('~^/get/domain/(.+)$~', $path, $m)) {
            return $this->domains[urldecode($m[1])] ?? [];
        }
        if (preg_match('~^/get/dkim/(.+)$~', $path, $m)) {
            return $this->dkim[urldecode($m[1])] ?? [];
        }
        if (preg_match('~^/get/mailbox/all/(.+)$~', $path, $m)) {
            $domain = urldecode($m[1]);

            return array_values(array_filter($this->mailboxes, static fn (array $box): bool => $box['domain'] === $domain));
        }
        if (preg_match('~^/get/mailbox/(.+)$~', $path, $m)) {
            return $this->mailboxes[urldecode($m[1])] ?? [];
        }
        if ($path === '/get/alias/all') {
            return array_values($this->aliases);
        }
        if ($path === '/get/syncjobs/all/no_log') {
            return array_values($this->syncjobs);
        }
        if ($path === '/get/filters/all') {
            return array_values($this->filters);
        }

        return [];
    }

    /**
     * @param  array<int|string, mixed>  $body
     * @return list<array<string, mixed>>
     */
    private function write(string $path, array $body): array
    {
        switch ($path) {
            case '/add/domain':
                $domain = (string) $body['domain'];
                if (isset($this->domains[$domain])) {
                    return [$this->danger('domain_exists', $domain)];
                }
                $this->domains[$domain] = ['domain_name' => $domain] + $body;
                $out = [];
                if ($this->dkimWithDomain) {
                    $this->dkim[$domain] = $this->dkimRow();
                    $out[] = $this->success('dkim_added', $domain);
                }
                $out[] = $this->success('domain_added', $domain);

                return $out;
            case '/edit/domain':
                foreach ($body['items'] as $domain) {
                    $this->domains[$domain] = array_merge($this->domains[$domain] ?? [], $body['attr']);
                }

                return [$this->success('domain_modified', $body['items'][0])];
            case '/delete/domain':
                foreach ($body as $domain) {
                    unset($this->domains[$domain], $this->dkim[$domain]);
                }

                return [$this->success('domain_removed', $body[0])];
            case '/add/dkim':
                $domain = (string) $body['domains'];
                if (isset($this->dkim[$domain])) {
                    return [$this->danger('dkim_domain_or_sel_invalid', $domain)];
                }
                $this->dkim[$domain] = $this->dkimRow();

                return [$this->success('dkim_added', $domain)];
            case '/add/mailbox':
                $address = $body['local_part'].'@'.$body['domain'];
                if (isset($this->mailboxes[$address])) {
                    return [$this->danger('object_exists', $address)];
                }
                $this->addMailbox($address, (int) $body['quota']);
                $this->mailboxes[$address]['name'] = $body['name'] ?? $body['local_part'];
                $this->passwords[$address] = (string) $body['password'];

                return [$this->success('mailbox_added', $address)];
            case '/edit/mailbox':
                foreach ($body['items'] as $address) {
                    $attr = $body['attr'];
                    if (isset($attr['password'])) {
                        $this->passwords[$address] = (string) $attr['password'];
                        unset($attr['password'], $attr['password2']);
                    }
                    if (isset($attr['quota'])) {
                        $attr['quota'] = (int) $attr['quota'] * 1048576;
                    }
                    if (isset($attr['active'])) {
                        $attr['active_int'] = $attr['active'];
                    }
                    $this->mailboxes[$address] = array_merge($this->mailboxes[$address], $attr);
                }

                return [$this->success('mailbox_modified', $body['items'][0])];
            case '/delete/mailbox':
                foreach ($body as $address) {
                    unset($this->mailboxes[$address]);
                }

                return [$this->success('mailbox_removed', $body[0])];
            case '/add/alias':
                $id = $this->nextId++;
                $address = (string) $body['address'];
                $this->aliases[$id] = [
                    'id' => $id,
                    'address' => $address,
                    'goto' => (string) $body['goto'],
                    'domain' => substr((string) strrchr($address, '@'), 1),
                    'active' => (int) $body['active'],
                ];

                return [$this->success('alias_added', $address)];
            case '/edit/alias':
                foreach ($body['items'] as $id) {
                    $this->aliases[(int) $id] = array_merge($this->aliases[(int) $id], $body['attr']);
                }

                return [$this->success('alias_modified', $body['items'][0])];
            case '/delete/alias':
                foreach ($body as $id) {
                    unset($this->aliases[(int) $id]);
                }

                return [$this->success('alias_removed', $body[0])];
            case '/add/syncjob':
                $id = $this->nextId++;
                $this->syncjobs[$id] = [
                    'id' => $id,
                    'user2' => $body['username'],
                    'host1' => $body['host1'],
                    'port1' => $body['port1'],
                    'user1' => $body['user1'],
                    'enc1' => $body['enc1'],
                    'active' => 1,
                    'is_running' => 0,
                    'last_run' => null,
                    'success' => null,
                    'exit_status' => null,
                ];

                return [$this->success('mailbox_modified', $body['username'])];
            case '/delete/syncjob':
                foreach ($body as $id) {
                    unset($this->syncjobs[(int) $id]);
                }

                return [$this->success('deleted_syncjob', $body[0])];
            case '/add/filter':
                $id = $this->nextId++;
                $this->filters[$id] = ['id' => $id] + $body;

                return [$this->success('filter_added', $body['username'])];
            case '/edit/filter':
                foreach ($body['items'] as $id) {
                    $this->filters[(int) $id] = array_merge($this->filters[(int) $id], $body['attr']);
                }

                return [$this->success('filter_modified', $body['items'][0])];
            case '/delete/filter':
                foreach ($body as $id) {
                    unset($this->filters[(int) $id]);
                }

                return [$this->success('filter_removed', $body[0])];
        }

        return [$this->danger('unknown_path', $path)];
    }

    /**
     * @return array<string, mixed>
     */
    private function dkimRow(): array
    {
        return [
            'dkim_txt' => 'v=DKIM1;k=rsa;t=s;s=email;p=MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAtestkey',
            'dkim_selector' => 'dkim',
            'length' => '2048',
            'pubkey' => 'MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAtestkey',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function success(string $key, string $arg): array
    {
        return ['type' => 'success', 'log' => [], 'msg' => [$key, $arg]];
    }

    /**
     * @return array<string, mixed>
     */
    private function danger(string $key, string $arg): array
    {
        return ['type' => 'danger', 'log' => [], 'msg' => [$key, $arg]];
    }
}
