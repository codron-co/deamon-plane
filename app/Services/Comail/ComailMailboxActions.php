<?php

namespace App\Services\Comail;

/**
 * One mailbox change for the bulk actions (CMS `POST /mailboxes/bulk` and the
 * Super Admin mailbox page): activate, deactivate, password, delete, and
 * (CMS only, after the package check in the controller) quota. A password may
 * come with `force_pw_update` ("must change on next webmail login", docs:
 * CONTRACT-bulk-mailboxes). Passwords are never logged here.
 */
class ComailMailboxActions
{
    public const ACTIVATE = 'activate';

    public const DEACTIVATE = 'deactivate';

    public const PASSWORD = 'password';

    public const DELETE = 'delete';

    /** New quota in MB; the caller checks it against the package first. */
    public const QUOTA = 'quota';

    public const ACTIONS = [self::ACTIVATE, self::DEACTIVATE, self::PASSWORD, self::DELETE, self::QUOTA];

    /**
     * The Super Admin page's actions: no quota there, since a quota is checked
     * against one site's package and that page spans every domain.
     */
    public const OPS_ACTIONS = [self::ACTIVATE, self::DEACTIVATE, self::PASSWORD, self::DELETE];

    /** Most addresses one bulk request may touch. */
    public const MAX_ITEMS = 100;

    /** Readable alphabet: no 0/O, 1/l/I. */
    private const LETTERS = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ';

    private const DIGITS = '23456789';

    /**
     * Turkish reason the password is refused, null when it is fine (same rule
     * as a single mailbox: 6-128 characters, no complexity requirement; 6 is
     * mailcow's own minimum).
     */
    public static function passwordProblem(string $password): ?string
    {
        $length = mb_strlen($password);
        if ($length < 6) {
            return 'Parola en az 6 karakter olmalı.';
        }
        if ($length > 128) {
            return 'Parola en fazla 128 karakter olabilir.';
        }

        return null;
    }

    /**
     * A temporary password to hand over: 12 readable characters with at least
     * one upper-case letter, one lower-case letter and two digits.
     */
    public static function generatePassword(int $length = 12): string
    {
        $all = self::LETTERS.self::DIGITS;
        $chars = [
            self::pick('ABCDEFGHJKLMNPQRSTUVWXYZ'),
            self::pick('abcdefghijkmnpqrstuvwxyz'),
            self::pick(self::DIGITS),
            self::pick(self::DIGITS),
        ];
        while (count($chars) < $length) {
            $chars[] = self::pick($all);
        }

        for ($i = count($chars) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }

        return implode('', $chars);
    }

    /**
     * @param  bool|null  $forceChange  null leaves mailcow's force_pw_update as it is
     * @param  int|null  $quotaMb  required for QUOTA
     *
     * @throws MailcowException
     */
    public function apply(MailcowClient $client, string $username, string $action, ?string $password = null, ?bool $forceChange = null, ?int $quotaMb = null): void
    {
        if ($action === self::QUOTA && ($quotaMb === null || $quotaMb < 1)) {
            throw new \InvalidArgumentException('A quota action needs a quota of at least 1 MB.');
        }

        match ($action) {
            self::ACTIVATE => $client->editMailbox($username, ['active' => 1]),
            self::DEACTIVATE => $client->editMailbox($username, ['active' => 0]),
            self::PASSWORD => $client->editMailbox($username, self::passwordAttributes((string) $password, $forceChange)),
            self::DELETE => $client->deleteMailbox($username),
            self::QUOTA => $client->editMailbox($username, ['quota' => (int) $quotaMb]),
            default => throw new \InvalidArgumentException('Unknown mailbox action.'),
        };
    }

    /**
     * @return array<string, mixed>
     */
    public static function passwordAttributes(string $password, ?bool $forceChange): array
    {
        $attributes = ['password' => $password, 'password2' => $password];
        if ($forceChange !== null) {
            $attributes['force_pw_update'] = $forceChange ? 1 : 0;
        }

        return $attributes;
    }

    private static function pick(string $alphabet): string
    {
        return $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
}
