<?php

namespace App\Services\Comail;

use App\Models\MailServer;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

/**
 * One-click webmail login. Plane creates a mailcow app password for the
 * mailbox (replacing the previous one-click password), keeps
 * `{address, password}` encrypted in the cache under a random 64-hex token for
 * 120 s, and the webmail server redeems it exactly once over a signed call.
 * The mailbox password itself is never known to Plane.
 */
class ComailWebmailSso
{
    public const APP_NAME = 'CoMail tek tık giriş';

    public const TTL_SECONDS = 120;

    public const SKEW_SECONDS = 60;

    private const PROTOCOLS = ['imap_access', 'smtp_access'];

    /**
     * @return array{token: string, url: string, expires_in: int}
     */
    public function issue(MailServer $server, MailcowClient $client, string $address): array
    {
        $address = strtolower($address);

        $stale = [];
        foreach ($client->appPasswords($address) as $row) {
            if ((string) ($row['name'] ?? '') === self::APP_NAME && is_numeric($row['id'] ?? null)) {
                $stale[] = (int) $row['id'];
            }
        }
        if ($stale !== []) {
            $client->deleteAppPasswords($stale);
        }

        $password = self::password();
        $client->addAppPassword($address, self::APP_NAME, $password, self::PROTOCOLS);

        $token = bin2hex(random_bytes(32));
        Cache::put(self::cacheKey($token), Crypt::encryptString((string) json_encode([
            'address' => $address,
            'password' => $password,
        ])), self::TTL_SECONDS);

        return [
            'token' => $token,
            'url' => $server->comailBaseUrl().'/webmail/?'.http_build_query(['_task' => 'login', '_comail_sso' => $token]),
            'expires_in' => self::TTL_SECONDS,
        ];
    }

    /**
     * Returns the credentials once and forgets them; null when unknown or expired.
     *
     * @return array{username: string, password: string}|null
     */
    public function redeem(string $token): ?array
    {
        if (preg_match('/\A[a-f0-9]{64}\z/', $token) !== 1) {
            return null;
        }

        $stored = Cache::pull(self::cacheKey($token));
        if (! is_string($stored) || $stored === '') {
            return null;
        }

        try {
            $data = json_decode(Crypt::decryptString($stored), true);
        } catch (DecryptException) {
            return null;
        }

        if (! is_array($data) || ! is_string($data['address'] ?? null) || ! is_string($data['password'] ?? null)) {
            return null;
        }

        return ['username' => $data['address'], 'password' => $data['password']];
    }

    /**
     * hex HMAC-SHA256 of `{timestamp}.{rawBody}` with the server's sso_secret, ±60 s.
     */
    public static function validSignature(string $secret, string $timestamp, string $signature, string $rawBody, ?int $now = null): bool
    {
        if ($secret === '' || $signature === '' || preg_match('/\A\d{1,12}\z/', $timestamp) !== 1) {
            return false;
        }

        if (abs(($now ?? time()) - (int) $timestamp) > self::SKEW_SECONDS) {
            return false;
        }

        return hash_equals(self::signature($secret, $timestamp, $rawBody), strtolower($signature));
    }

    public static function signature(string $secret, string $timestamp, string $rawBody): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);
    }

    private static function cacheKey(string $token): string
    {
        return 'comail-sso:'.hash('sha256', $token);
    }

    /**
     * 32 characters, upper, lower, digit (mailcow's password policy accepts it).
     */
    private static function password(): string
    {
        $upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        $lower = 'abcdefghijkmnopqrstuvwxyz';
        $digits = '23456789';
        $all = $upper.$lower.$digits;

        $chars = [
            $upper[random_int(0, strlen($upper) - 1)],
            $lower[random_int(0, strlen($lower) - 1)],
            $digits[random_int(0, strlen($digits) - 1)],
        ];
        while (count($chars) < 32) {
            $chars[] = $all[random_int(0, strlen($all) - 1)];
        }
        for ($i = count($chars) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }

        return implode('', $chars);
    }
}
