<?php

namespace App\Services\Comail;

use App\Models\MailServer;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin mailcow API v1 client (`{base_url}/api/v1`, header X-API-Key).
 *
 * mailcow answers writes with HTTP 200 and an array of
 * `{type: success|danger|error|warning, msg}`; any danger/error element is a
 * failure, so every write goes through parseResult(). Quotas: MB on write,
 * bytes on read. The API key is never logged or put in an exception.
 */
class MailcowClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly int $timeout = 15,
    ) {}

    public static function forServer(MailServer $server): self
    {
        return new self(
            $server->comailBaseUrl().'/api/v1',
            (string) $server->api_token,
            max(1, (int) config('ops.comail.timeout_seconds', 15)),
        );
    }

    public static function forComail(): self
    {
        $server = MailServer::comail();
        if (! $server instanceof MailServer) {
            throw new MailcowException('comail_not_configured', 'CoMail sunucusu tanımlı değil.', 422);
        }

        return self::forServer($server);
    }

    /**
     * Connection test: `{version: "..."}`.
     */
    public function version(): string
    {
        $json = $this->get('/get/status/version');

        return is_string($json['version'] ?? null) ? $json['version'] : '';
    }

    // ── Domains ─────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>|null null when the domain does not exist
     */
    public function domain(string $domain): ?array
    {
        return $this->single($this->get('/get/domain/'.rawurlencode($domain)));
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return list<array<string, mixed>>
     */
    public function addDomain(string $domain, array $attributes): array
    {
        return $this->write('/add/domain', ['domain' => $domain] + $attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return list<array<string, mixed>>
     */
    public function editDomain(string $domain, array $attributes): array
    {
        return $this->write('/edit/domain', ['items' => [$domain], 'attr' => $attributes]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function deleteDomain(string $domain): array
    {
        return $this->write('/delete/domain', [$domain]);
    }

    // ── DKIM ────────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>|null {dkim_txt, dkim_selector, length, pubkey}; null when missing
     */
    public function dkim(string $domain): ?array
    {
        $row = $this->single($this->get('/get/dkim/'.rawurlencode($domain)));
        if ($row === null || ! is_string($row['dkim_txt'] ?? null) || trim($row['dkim_txt']) === '') {
            return null;
        }

        return $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function addDkim(string $domain, string $selector = 'dkim', int $keySize = 2048): array
    {
        return $this->write('/add/dkim', [
            'domains' => $domain,
            'dkim_selector' => $selector,
            'key_size' => $keySize,
        ]);
    }

    // ── Mail security (v3): rate limit, MTA-STS, spam filter, quarantine ─

    /**
     * Outbound rate limit of a domain: `{value: "300", frame: "h"}`, `{}` when none.
     *
     * @return array{value: int|null, frame: string|null}
     */
    public function domainRateLimit(string $domain): array
    {
        $row = $this->single($this->get('/get/rl-domain/'.rawurlencode($domain))) ?? [];
        $value = $row['value'] ?? null;

        return [
            'value' => is_numeric($value) && (int) $value > 0 ? (int) $value : null,
            'frame' => is_string($row['frame'] ?? null) && $row['frame'] !== '' ? $row['frame'] : null,
        ];
    }

    /**
     * mailcow has no read route for MTA-STS; an existing policy (primary key = domain)
     * answers danger with the database's duplicate-entry text.
     *
     * @param  array{version: string, mode: string, mx: string, max_age: string, active: string}  $attributes
     * @return list<array<string, mixed>>
     */
    public function addMtaSts(string $domain, array $attributes): array
    {
        return $this->write('/add/mta-sts', ['domain' => $domain] + $attributes);
    }

    /**
     * Mailbox spam thresholds "low,high" (`{score: "8,15"}`; the server default
     * when the mailbox has none of its own).
     */
    public function spamScore(string $address): ?string
    {
        $row = $this->single($this->get('/get/spam-score/'.rawurlencode($address))) ?? [];
        $score = $row['score'] ?? $row['spam_score'] ?? null;

        return is_string($score) && str_contains($score, ',') ? str_replace(' ', '', $score) : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function editSpamScore(string $address, string $score): array
    {
        return $this->write('/edit/spam-score', ['items' => [$address], 'attr' => ['spam_score' => $score]]);
    }

    /**
     * Allow (`wl`) or block (`bl`) list of a mailbox: `[{object, value, prefid}]`.
     * mailcow also returns the domain-wide entries (object = domain).
     *
     * @param  'wl'|'bl'  $list
     * @return list<array<string, mixed>>
     */
    public function mailboxPolicies(string $address, string $list): array
    {
        return $this->rows($this->get('/get/policy_'.$list.'_mailbox/'.rawurlencode($address)));
    }

    /**
     * @param  'wl'|'bl'  $list
     * @return list<array<string, mixed>>
     */
    public function addMailboxPolicy(string $address, string $list, string $from): array
    {
        return $this->write('/add/mailbox-policy', [
            'username' => $address,
            'object_list' => $list,
            'object_from' => $from,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function deleteMailboxPolicy(int $prefid): array
    {
        return $this->write('/delete/mailbox-policy', [(string) $prefid]);
    }

    /**
     * Every quarantined message on the server (filter by rcpt client side):
     * `[{id, qid, subject, virus_flag, score, rcpt, sender, action, created (unix), notified}]`.
     *
     * @return list<array<string, mixed>>
     */
    public function quarantine(): array
    {
        return $this->rows($this->get('/get/quarantine/all'));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function releaseQuarantineItem(int $id): array
    {
        return $this->write('/edit/qitem', ['items' => [(string) $id], 'attr' => ['action' => 'release']]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function deleteQuarantineItem(int $id): array
    {
        return $this->write('/delete/qitem', [(string) $id]);
    }

    // ── Mailboxes ───────────────────────────────────────────────────────

    /**
     * @return list<array<string, mixed>>
     */
    public function mailboxes(string $domain): array
    {
        return $this->rows($this->get('/get/mailbox/all/'.rawurlencode($domain)));
    }

    /**
     * Every mailbox on the server, sites or not (the Super Admin mailbox page).
     *
     * @return list<array<string, mixed>>
     */
    public function allMailboxes(): array
    {
        return $this->rows($this->get('/get/mailbox/all'));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function mailbox(string $address): ?array
    {
        $row = $this->single($this->get('/get/mailbox/'.rawurlencode($address)));

        return $row !== null && is_string($row['username'] ?? null) ? $row : null;
    }

    /**
     * `sogo_access` is always 0: CoMail's webmail is Roundcube at the web root,
     * so a login must land there (or in the user panel), never in SOGo.
     *
     * @param  array<string, mixed>  $attributes  local_part, domain, name, quota (MB), password
     * @return list<array<string, mixed>>
     */
    public function addMailbox(array $attributes): array
    {
        return $this->write('/add/mailbox', ['sogo_access' => 0] + $attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return list<array<string, mixed>>
     */
    public function editMailbox(string $address, array $attributes): array
    {
        return $this->write('/edit/mailbox', ['items' => [$address], 'attr' => $attributes]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function deleteMailbox(string $address): array
    {
        return $this->write('/delete/mailbox', [$address]);
    }

    // ── Aliases ─────────────────────────────────────────────────────────

    /**
     * Every alias on the server (filter by domain client side).
     *
     * @return list<array<string, mixed>>
     */
    public function aliases(): array
    {
        return $this->rows($this->get('/get/alias/all'));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function addAlias(string $address, string $goto, bool $active = true): array
    {
        return $this->write('/add/alias', [
            'address' => $address,
            'goto' => $goto,
            'active' => $active ? 1 : 0,
            'sogo_visible' => 1,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return list<array<string, mixed>>
     */
    public function editAlias(int $id, array $attributes): array
    {
        return $this->write('/edit/alias', ['items' => [(string) $id], 'attr' => $attributes]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function deleteAlias(int $id): array
    {
        return $this->write('/delete/alias', [(string) $id]);
    }

    // ── Sync jobs (IMAP migration) ──────────────────────────────────────

    /**
     * @return list<array<string, mixed>>
     */
    public function syncJobs(): array
    {
        return $this->rows($this->get('/get/syncjobs/all/no_log'));
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return list<array<string, mixed>>
     */
    public function addSyncJob(array $attributes): array
    {
        return $this->write('/add/syncjob', $attributes);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function deleteSyncJob(int $id): array
    {
        return $this->write('/delete/syncjob', [(string) $id]);
    }

    // ── Sieve filters ───────────────────────────────────────────────────

    /**
     * @return list<array<string, mixed>>
     */
    public function filters(): array
    {
        return $this->rows($this->get('/get/filters/all'));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function addFilter(string $username, string $description, string $script, bool $active): array
    {
        return $this->write('/add/filter', [
            'username' => $username,
            'filter_type' => 'prefilter',
            'script_desc' => $description,
            'script_data' => $script,
            'active' => $active ? 1 : 0,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return list<array<string, mixed>>
     */
    public function editFilter(int $id, array $attributes): array
    {
        return $this->write('/edit/filter', ['items' => [(string) $id], 'attr' => $attributes]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function deleteFilter(int $id): array
    {
        return $this->write('/delete/filter', [(string) $id]);
    }

    // ── App passwords (webmail SSO) ─────────────────────────────────────

    /**
     * @return list<array<string, mixed>> {id, name, mailbox, active, created, ...}
     */
    public function appPasswords(string $mailbox): array
    {
        return $this->rows($this->get('/get/app-passwd/all/'.rawurlencode($mailbox)));
    }

    /**
     * @param  list<string>  $protocols
     * @return list<array<string, mixed>>
     */
    public function addAppPassword(string $mailbox, string $name, string $password, array $protocols): array
    {
        return $this->write('/add/app-passwd', [
            'username' => $mailbox,
            'app_name' => $name,
            'app_passwd' => $password,
            'app_passwd2' => $password,
            'active' => 1,
            'protocols' => $protocols,
        ]);
    }

    /**
     * @param  list<int>  $ids
     * @return list<array<string, mixed>>
     */
    public function deleteAppPasswords(array $ids): array
    {
        return $this->write('/delete/app-passwd', array_map('strval', $ids));
    }

    // ── Transport ───────────────────────────────────────────────────────

    /**
     * @return array<int|string, mixed>
     */
    private function get(string $path): array
    {
        try {
            $response = $this->http()->get($this->baseUrl.$path);
        } catch (ConnectionException) {
            $this->logFailure('GET', $path, null);

            throw MailcowException::unreachable();
        }

        $json = $this->decode('GET', $path, $response);
        if (! array_is_list($json) && $this->isFailureElement($json)) {
            throw $this->failure('GET', $path, $json);
        }

        return $json;
    }

    /**
     * @param  array<int|string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function write(string $path, array $payload): array
    {
        try {
            $response = $this->http()->post($this->baseUrl.$path, $payload);
        } catch (ConnectionException) {
            $this->logFailure('POST', $path, null);

            throw MailcowException::unreachable();
        }

        return $this->parseResult('POST', $path, $this->decode('POST', $path, $response));
    }

    /**
     * @param  array<int|string, mixed>  $json
     * @return list<array<string, mixed>>
     */
    private function parseResult(string $method, string $path, array $json): array
    {
        $elements = array_is_list($json) ? $json : [$json];
        $out = [];
        foreach ($elements as $element) {
            if (! is_array($element)) {
                continue;
            }
            if ($this->isFailureElement($element)) {
                throw $this->failure($method, $path, $element);
            }
            $out[] = $element;
        }

        if ($out === []) {
            $this->logFailure($method, $path, 200, 'empty_result');

            throw new MailcowException('provider_error', 'mailcow returned no result.', 502);
        }

        return $out;
    }

    /**
     * @return array<int|string, mixed>
     */
    private function decode(string $method, string $path, Response $response): array
    {
        if ($response->status() === 401 || $response->status() === 403) {
            $this->logFailure($method, $path, $response->status(), 'unauthorized');

            throw new MailcowException('provider_error', 'mailcow rejected the API key.', 502, 'access_denied');
        }

        if ($response->failed()) {
            $this->logFailure($method, $path, $response->status());

            throw new MailcowException('provider_error', 'mailcow returned HTTP '.$response->status().'.', 502);
        }

        $json = $response->json();
        if (! is_array($json)) {
            $this->logFailure($method, $path, $response->status(), 'not_json');

            throw new MailcowException('provider_error', 'mailcow returned an unreadable answer.', 502);
        }

        return $json;
    }

    /**
     * @param  array<int|string, mixed>  $element
     */
    private function isFailureElement(array $element): bool
    {
        $type = $element['type'] ?? null;

        return is_string($type) && in_array(strtolower($type), ['danger', 'error'], true);
    }

    /**
     * @param  array<int|string, mixed>  $element
     */
    private function failure(string $method, string $path, array $element): MailcowException
    {
        $key = self::messageKey($element['msg'] ?? null);
        $this->logFailure($method, $path, 200, $key ?? 'error');

        return new MailcowException('provider_error', 'mailcow: '.($key ?? 'error'), 422, $key);
    }

    /**
     * `msg` is a translation key, a [key, arg…] list, or free text.
     */
    public static function messageKey(mixed $msg): ?string
    {
        if (is_array($msg)) {
            $msg = $msg[0] ?? null;
        }

        if (! is_string($msg)) {
            return null;
        }

        $msg = trim($msg);
        if ($msg === '') {
            return null;
        }

        return preg_match('/\A[a-z0-9_]{1,80}\z/', $msg) === 1 ? $msg : mb_substr($msg, 0, 120);
    }

    /**
     * A GET for one object answers the object, `{}`, `[]`, or a one-element list.
     *
     * @param  array<int|string, mixed>  $json
     * @return array<string, mixed>|null
     */
    private function single(array $json): ?array
    {
        if ($json === []) {
            return null;
        }

        if (array_is_list($json)) {
            $first = $json[0] ?? null;

            return is_array($first) && $first !== [] ? $first : null;
        }

        return $json;
    }

    /**
     * A GET for a collection answers a list, `{}` when empty, or a lone object.
     *
     * @param  array<int|string, mixed>  $json
     * @return list<array<string, mixed>>
     */
    private function rows(array $json): array
    {
        if ($json === []) {
            return [];
        }

        if (! array_is_list($json)) {
            return [$json];
        }

        return array_values(array_filter($json, static fn (mixed $row): bool => is_array($row) && $row !== []));
    }

    private function http(): PendingRequest
    {
        return Http::timeout($this->timeout)
            ->connectTimeout(min(5, $this->timeout))
            ->acceptJson()
            ->asJson()
            ->withHeaders(['X-API-Key' => $this->apiKey]);
    }

    private function logFailure(string $method, string $path, ?int $status, ?string $reason = null): void
    {
        Log::warning('comail.mailcow_request_failed', array_filter([
            'method' => $method,
            // Paths carry addresses at most, never the key or a password.
            'path' => preg_replace('~/[^/]*@[^/]*~', '/{address}', $path),
            'http_status' => $status,
            'reason' => $reason ?? ($status === null ? 'unreachable' : 'http_error'),
        ], static fn (mixed $value): bool => $value !== null));
    }
}
