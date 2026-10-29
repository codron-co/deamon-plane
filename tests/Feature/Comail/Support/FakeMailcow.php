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

    /** @var array<int, array<string, mixed>> app passwords by id (password kept in `app_passwd` for assertions) */
    public array $appPasswords = [];

    /** @var list<array{method: string, path: string, body: mixed}> */
    public array $calls = [];

    /** @var array<string, array<string, mixed>> path => one failure element */
    public array $failOn = [];

    public bool $dkimWithDomain = true;

    /** @var array<string, string> mailbox => "low,high" (none = the server default) */
    public array $spamScores = [];

    /** @var array<int, array{prefid: int, object: string, list: string, value: string}> */
    public array $policies = [];

    /** @var array<int, array<string, mixed>> quarantine rows by id */
    public array $quarantine = [];

    /** @var array<string, array{value: string, frame: string}> */
    public array $rateLimits = [];

    /** @var array<string, array<string, mixed>> MTA-STS policies by domain */
    public array $mtaSts = [];

    /** @var list<int> released quarantine ids */
    public array $released = [];

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
            'attributes' => ['force_pw_update' => '0'],
        ];
        $this->aliases[$this->nextId] = ['id' => $this->nextId, 'address' => $address, 'goto' => $address, 'domain' => $domain, 'active' => 1];
        $this->nextId++;
    }

    public function quarantineItem(int $id, string $rcpt, array $attributes = []): void
    {
        $this->quarantine[$id] = $attributes + [
            'id' => $id,
            'qid' => 'Q'.$id,
            'subject' => 'Fatura '.$id,
            'virus_flag' => 0,
            'score' => 16.5,
            'rcpt' => $rcpt,
            'sender' => 'spam@spam.test',
            'action' => 'reject',
            'created' => 1793000000 + $id,
            'notified' => 0,
        ];
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
        if (preg_match('~^/get/rl-domain/(.+)$~', $path, $m)) {
            return $this->rateLimits[urldecode($m[1])] ?? [];
        }
        if (preg_match('~^/get/spam-score/(.+)$~', $path, $m)) {
            // Live shape (2026-10-26): {"score": "8,15"}, the server default without a custom pair.
            return ['score' => $this->spamScores[urldecode($m[1])] ?? '8,15'];
        }
        if (preg_match('~^/get/policy_(wl|bl)_mailbox/(.+)$~', $path, $m)) {
            $address = urldecode($m[2]);
            $domain = substr((string) strrchr($address, '@'), 1);
            $rows = [];
            foreach ($this->policies as $row) {
                // mailcow lists the mailbox's own entries and the domain-wide ones.
                if ($row['list'] === $m[1] && ($row['object'] === $address || $row['object'] === $domain)) {
                    $rows[] = ['object' => $row['object'], 'value' => $row['value'], 'prefid' => $row['prefid']];
                }
            }

            return $rows;
        }
        if ($path === '/get/quarantine/all') {
            return array_values($this->quarantine);
        }
        if (preg_match('~^/get/domain/(.+)$~', $path, $m)) {
            return $this->domains[urldecode($m[1])] ?? [];
        }
        if (preg_match('~^/get/dkim/(.+)$~', $path, $m)) {
            return $this->dkim[urldecode($m[1])] ?? [];
        }
        if ($path === '/get/mailbox/all') {
            return array_values($this->mailboxes);
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
        if (preg_match('~^/get/app-passwd/all/(.+)$~', $path, $m)) {
            $mailbox = urldecode($m[1]);

            return array_values(array_map(
                static fn (array $row): array => array_diff_key($row, ['app_passwd' => true]),
                array_filter($this->appPasswords, static fn (array $row): bool => $row['mailbox'] === $mailbox),
            ));
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
            case '/add/mta-sts':
                $domain = (string) $body['domain'];
                if (isset($this->mtaSts[$domain])) {
                    // The real server answers the database error text (primary key = domain).
                    return [['type' => 'danger', 'log' => [], 'msg' => "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '".$domain."' for key 'PRIMARY'"]];
                }
                $this->mtaSts[$domain] = $body;

                return [$this->success('mta_sts_added', $domain)];
            case '/edit/spam-score':
                foreach ($body['items'] as $address) {
                    $this->spamScores[$address] = (string) $body['attr']['spam_score'];
                }

                return [$this->success('mailbox_modified', $body['items'][0])];
            case '/add/mailbox-policy':
                foreach ($this->policies as $row) {
                    if ($row['object'] === $body['username'] && $row['value'] === $body['object_from']) {
                        return [$this->danger('policy_list_from_exists', (string) $body['username'])];
                    }
                }
                $id = $this->nextId++;
                $this->policies[$id] = ['prefid' => $id, 'object' => (string) $body['username'], 'list' => (string) $body['object_list'], 'value' => (string) $body['object_from']];

                return [$this->success('mailbox_modified', (string) $body['username'])];
            case '/delete/mailbox-policy':
                foreach ($body as $id) {
                    unset($this->policies[(int) $id]);
                }

                return [$this->success('item_deleted', (string) $body[0])];
            case '/edit/qitem':
                foreach ($body['items'] as $id) {
                    if ($body['attr']['action'] === 'release') {
                        $this->released[] = (int) $id;
                        unset($this->quarantine[(int) $id]);
                    }
                }

                return [$this->success('item_released', (string) $body['items'][0])];
            case '/delete/qitem':
                foreach ($body as $id) {
                    unset($this->quarantine[(int) $id]);
                }

                return [$this->success('item_deleted', (string) $body[0])];
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
                    // mailcow answers it inside `attributes`, as a string.
                    if (isset($attr['force_pw_update'])) {
                        $attr['attributes'] = ['force_pw_update' => (string) $attr['force_pw_update']] + ($this->mailboxes[$address]['attributes'] ?? []);
                        unset($attr['force_pw_update']);
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
            case '/add/app-passwd':
                $mailbox = (string) $body['username'];
                if (! isset($this->mailboxes[$mailbox])) {
                    return [$this->danger('access_denied', $mailbox)];
                }
                if (($body['app_passwd'] ?? null) !== ($body['app_passwd2'] ?? null)) {
                    return [$this->danger('password_mismatch', $mailbox)];
                }
                $id = $this->nextId++;
                $this->appPasswords[$id] = [
                    'id' => $id,
                    'name' => (string) $body['app_name'],
                    'mailbox' => $mailbox,
                    'domain' => substr((string) strrchr($mailbox, '@'), 1),
                    'active' => (int) ($body['active'] ?? 1),
                    'protocols' => $body['protocols'] ?? [],
                    'app_passwd' => (string) $body['app_passwd'],
                ];

                return [$this->success('app_passwd_added', $mailbox)];
            case '/delete/app-passwd':
                foreach ($body as $id) {
                    unset($this->appPasswords[(int) $id]);
                }

                return [$this->success('app_passwd_removed', (string) ($body[0] ?? ''))];
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
