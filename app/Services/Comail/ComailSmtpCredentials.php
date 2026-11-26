<?php

namespace App\Services\Comail;

use App\Models\MailServer;
use App\Models\Site;

/**
 * Site SMTP from a CoMail mailbox without its password: Plane creates a
 * mailcow app password that may only send (`smtp_access`) and hands it to the
 * site once, in the response of the signed CMS call. The CMS stores it
 * encrypted in its mail settings.
 *
 * Names: "<primary domain> site SMTP (Deamon) <site id>.<6 random>". The site
 * id token makes every password traceable to its site even after the primary
 * domain changes; the random part makes each one unique. Rotation is
 * "add new → CMS stores it → CMS asks to revoke the rest, keeping the new
 * name", so a failed or timed-out save never leaves the site on a dead
 * password. Older names without a token ("<domain> site SMTP (Deamon)") count
 * as the site's when they equal its legacy name.
 *
 * Sender addresses (`send_as`): the mailbox itself, then the same local part
 * on every active alias domain of the mailbox's domain (b2b@beyazogluotomotiv.com
 * → b2b@beyazoglu.tr). mailcow's sender ACL lets the logged-in mailbox send as
 * those (postfix `mysql_virtual_sender_acl.cf`, last UNION on `alias_domain`).
 *
 * The password is never logged, audited or cached.
 */
class ComailSmtpCredentials
{
    public const NAME_SUFFIX = ' site SMTP (Deamon)';

    public const PORT = 465;

    public const ENCRYPTION = 'ssl';

    private const PROTOCOLS = ['smtp_access'];

    /** "<anything> site SMTP (Deamon)[ <ULID>.<6>]" */
    private const NAME_PATTERN = '/ site SMTP \(Deamon\)(?: ([0-9A-HJKMNP-TV-Z]{26})\.[a-z0-9]{6})?\z/i';

    /**
     * The mailbox address first, then local@alias for each active alias domain
     * whose target is the mailbox's domain (sorted).
     *
     * @param  list<array<string, mixed>>  $aliasDomains  MailcowClient::aliasDomains()
     * @return list<string>
     */
    public static function sendAs(string $address, array $aliasDomains): array
    {
        $address = strtolower(trim($address));
        $at = strrpos($address, '@');
        if ($at === false) {
            return [];
        }
        $local = substr($address, 0, $at);
        $domain = substr($address, $at + 1);

        $extra = [];
        foreach ($aliasDomains as $row) {
            $alias = strtolower(trim((string) ($row['alias_domain'] ?? '')));
            $target = strtolower(trim((string) ($row['target_domain'] ?? '')));
            $active = (int) ($row['active_int'] ?? $row['active'] ?? 0) === 1;
            if ($active && $alias !== '' && $alias !== $domain && $target === $domain) {
                $extra[] = $local.'@'.$alias;
            }
        }
        $extra = array_values(array_unique($extra));
        sort($extra);

        return array_merge([$address], $extra);
    }

    /** Name without the unique part (also the name used before 2026-11-26). */
    public static function legacyName(Site $site): string
    {
        $host = strtolower(trim((string) $site->primary_domain));
        if ($host === '') {
            $host = (string) ($site->slug ?: $site->id);
        }

        return $host.self::NAME_SUFFIX;
    }

    /** A fresh, unique name for a new password of this site. */
    public static function newName(Site $site): string
    {
        return self::legacyName($site).' '.strtoupper((string) $site->id).'.'.substr(bin2hex(random_bytes(4)), 0, 6);
    }

    /** Is this a Deamon site SMTP password at all (any site)? */
    public static function isSmtpName(string $name): bool
    {
        return preg_match(self::NAME_PATTERN, $name) === 1;
    }

    /**
     * Does the name belong to the site: its id token, or (no token) its legacy name.
     */
    public static function ownedBy(string $name, string $siteId, string $legacyName): bool
    {
        if (preg_match(self::NAME_PATTERN, $name, $m) !== 1) {
            return false;
        }

        return isset($m[1])
            ? strtoupper($m[1]) === strtoupper($siteId)
            : $name === $legacyName;
    }

    /**
     * Adds a new SMTP app password; the site's older ones stay until revoke().
     *
     * @return array{host: string, port: int, encryption: string, username: string, password: string, app_name: string}
     */
    public function issue(Site $site, MailServer $server, MailcowClient $client, string $address): array
    {
        $address = strtolower(trim($address));
        $name = self::newName($site);
        $password = self::password();
        $client->addAppPassword($address, $name, $password, self::PROTOCOLS);

        return [
            'host' => $server->comailHostname(),
            'port' => self::PORT,
            'encryption' => self::ENCRYPTION,
            'username' => $address,
            'password' => $password,
            'app_name' => $name,
        ];
    }

    /**
     * Deletes the site's SMTP app passwords on the mailbox, except `$keep`; returns how many.
     */
    public function revoke(Site $site, MailcowClient $client, string $address, ?string $keep = null): int
    {
        return $this->revokeOwned($client, $address, (string) $site->id, self::legacyName($site), $keep);
    }

    /**
     * Same for a site that may be gone (share group left, archived, purged).
     */
    public function revokeOwned(MailcowClient $client, string $address, string $siteId, string $legacyName, ?string $keep = null): int
    {
        return $this->deleteWhere($client, $address, static fn (string $name): bool => $name !== $keep && self::ownedBy($name, $siteId, $legacyName));
    }

    /**
     * Deletes Deamon site SMTP passwords on the mailbox that are NOT the site's
     * (left by a previous owner of the domain); returns how many.
     */
    public function revokeForeign(Site $site, MailcowClient $client, string $address): int
    {
        $siteId = (string) $site->id;
        $legacy = self::legacyName($site);

        return $this->deleteWhere($client, $address, static fn (string $name): bool => self::isSmtpName($name) && ! self::ownedBy($name, $siteId, $legacy));
    }

    /**
     * @param  callable(string): bool  $match
     */
    private function deleteWhere(MailcowClient $client, string $address, callable $match): int
    {
        $ids = [];
        foreach ($client->appPasswords(strtolower(trim($address))) as $row) {
            if ($match((string) ($row['name'] ?? '')) && is_numeric($row['id'] ?? null)) {
                $ids[] = (int) $row['id'];
            }
        }

        if ($ids !== []) {
            $client->deleteAppPasswords($ids);
        }

        return count($ids);
    }

    /**
     * 32 characters, upper, lower, digit: no symbols, so no SMTP client or
     * .env quoting can mangle it (mailcow's password policy accepts it).
     */
    public static function password(): string
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
