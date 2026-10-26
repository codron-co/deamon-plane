<?php

namespace App\Services\Comail;

use App\Models\MailServer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Picks the webmail host for a mailbox: the customer's own `https://mail.<domain>`
 * when it is usable, else the mail server's base_url (`https://mail.codron.co`).
 *
 * Every `mail.<domain>` that points at the mailcow server serves the same
 * Roundcube (Traefik TLS passthrough), so a one-time SSO token works on any of
 * them. "Usable" means:
 *  - every A record of `mail.<domain>` (the system resolver follows a CNAME
 *    chain) is one of the IPs the base_url host resolves to, and
 *  - `GET https://mail.<domain>/webmail/` with certificate verification answers
 *    200 or 302 within PROBE_TIMEOUT seconds (no redirects followed).
 *
 * The answer is cached per server and domain (usable 30 min, not usable 10 min)
 * so the redirect path stays fast. A failed probe never throws: it falls back.
 */
class ComailWebmailHost
{
    public const POSITIVE_TTL = 1800;

    public const NEGATIVE_TTL = 600;

    public const PROBE_TIMEOUT = 4;

    private const HOSTNAME = '/\A(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}\z/';

    public function __construct(private readonly DnsResolver $resolver) {}

    /**
     * Base URL (no trailing slash) for the mailbox `$address`.
     */
    public function forAddress(MailServer $server, string $address): string
    {
        $at = strrpos($address, '@');

        return $this->forDomain($server, $at === false ? '' : substr($address, $at + 1));
    }

    /**
     * Base URL (no trailing slash) for mailboxes on `$domain`.
     */
    public function forDomain(MailServer $server, string $domain): string
    {
        $base = $server->comailBaseUrl();
        $own = self::ownHost($domain);
        if ($own === null || $own === self::hostOf($base)) {
            return $base;
        }

        return $this->usable($server, $own) ? 'https://'.$own : $base;
    }

    /**
     * Cached usability of `$host` (`mail.<domain>`) for this server.
     */
    private function usable(MailServer $server, string $host): bool
    {
        $key = 'comail-webmail-host:'.hash('sha256', $server->comailBaseUrl().'|'.$host);

        $cached = Cache::get($key);
        if ($cached === 'ok' || $cached === 'no') {
            return $cached === 'ok';
        }

        try {
            $usable = $this->probe($server, $host);
        } catch (Throwable) {
            $usable = false;
        }

        try {
            Cache::put($key, $usable ? 'ok' : 'no', $usable ? self::POSITIVE_TTL : self::NEGATIVE_TTL);
        } catch (Throwable) {
            // A cache outage only costs the next caller another probe.
        }

        return $usable;
    }

    private function probe(MailServer $server, string $host): bool
    {
        $serverHost = self::hostOf($server->comailBaseUrl());
        if ($serverHost === '') {
            return false;
        }

        $serverIps = $this->ips($serverHost);
        $ownIps = $this->ips($host);
        if ($serverIps === [] || $ownIps === [] || array_diff($ownIps, $serverIps) !== []) {
            return false;
        }

        try {
            $status = Http::timeout(self::PROBE_TIMEOUT)
                ->connectTimeout(self::PROBE_TIMEOUT)
                ->withOptions(['verify' => true])
                ->withoutRedirecting()
                ->get('https://'.$host.'/webmail/')
                ->status();
        } catch (Throwable $exception) {
            Log::info('comail.webmail_host_probe_failed', ['host' => $host, 'error' => mb_substr($exception->getMessage(), 0, 200)]);

            return false;
        }

        if ($status !== 200 && $status !== 302) {
            Log::info('comail.webmail_host_probe_failed', ['host' => $host, 'status' => $status]);

            return false;
        }

        return true;
    }

    /**
     * @return list<string>
     */
    private function ips(string $host): array
    {
        $ips = [];
        foreach ($this->resolver->lookup($host, 'A') as $record) {
            $ip = trim((string) ($record['ip'] ?? ''));
            if ($ip !== '') {
                $ips[] = $ip;
            }
        }

        return array_values(array_unique($ips));
    }

    private static function ownHost(string $domain): ?string
    {
        $domain = strtolower(rtrim(trim($domain), '.'));
        if ($domain === '' || strlen($domain) > 248 || preg_match(self::HOSTNAME, $domain) !== 1) {
            return null;
        }

        return 'mail.'.$domain;
    }

    private static function hostOf(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) ? strtolower($host) : '';
    }
}
