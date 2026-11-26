<?php

namespace App\Services\Comail;

use App\Models\MailServer;
use App\Models\Site;

/**
 * Site SMTP from a CoMail mailbox without its password: Plane creates a
 * mailcow app password that may only send (`smtp_access`) and hands it to the
 * site once, in the response of the signed CMS call. The CMS stores it
 * encrypted in its mail settings. Every site has its own app name
 * ("<primary domain> site SMTP (Deamon)"), so issuing again replaces only this
 * site's previous password (rotation) and revoking removes only it; the
 * mailbox password, the one-click webmail password and other sites' SMTP
 * passwords stay. The password is never logged, audited or cached.
 *
 * Sender addresses (`send_as`): the mailbox itself, then the same local part
 * on every active alias domain of the mailbox's domain (b2b@beyazogluotomotiv.com
 * → b2b@beyazoglu.tr). mailcow's sender ACL lets the logged-in mailbox send as
 * those (postfix `mysql_virtual_sender_acl.cf`, last UNION on `alias_domain`).
 */
class ComailSmtpCredentials
{
    public const NAME_SUFFIX = ' site SMTP (Deamon)';

    public const PORT = 465;

    public const ENCRYPTION = 'ssl';

    private const PROTOCOLS = ['smtp_access'];

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

    public static function appName(Site $site): string
    {
        $host = strtolower(trim((string) $site->primary_domain));
        if ($host === '') {
            $host = (string) ($site->slug ?: $site->id);
        }

        return $host.self::NAME_SUFFIX;
    }

    /**
     * Replaces this site's previous SMTP app password on the mailbox and returns the new one.
     *
     * @return array{host: string, port: int, encryption: string, username: string, password: string, replaced: int}
     */
    public function issue(Site $site, MailServer $server, MailcowClient $client, string $address): array
    {
        $address = strtolower(trim($address));
        $replaced = $this->revoke($site, $client, $address);

        $password = self::password();
        $client->addAppPassword($address, self::appName($site), $password, self::PROTOCOLS);

        return [
            'host' => $server->comailHostname(),
            'port' => self::PORT,
            'encryption' => self::ENCRYPTION,
            'username' => $address,
            'password' => $password,
            'replaced' => $replaced,
        ];
    }

    /**
     * Deletes this site's SMTP app passwords on the mailbox; returns how many.
     */
    public function revoke(Site $site, MailcowClient $client, string $address): int
    {
        $name = self::appName($site);
        $ids = [];
        foreach ($client->appPasswords(strtolower(trim($address))) as $row) {
            if ((string) ($row['name'] ?? '') === $name && is_numeric($row['id'] ?? null)) {
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
