<?php

namespace App\Services\Comail;

/**
 * One mailbox change for the bulk actions (CMS `POST /mailboxes/bulk` and the
 * Super Admin mailbox page): activate, deactivate, password, delete. A
 * password may come with `force_pw_update` ("must change on next webmail
 * login", docs: CONTRACT-bulk-mailboxes). Passwords are never logged here.
 */
class ComailMailboxActions
{
    public const ACTIVATE = 'activate';

    public const DEACTIVATE = 'deactivate';

    public const PASSWORD = 'password';

    public const DELETE = 'delete';

    public const ACTIONS = [self::ACTIVATE, self::DEACTIVATE, self::PASSWORD, self::DELETE];

    /** Most addresses one bulk request may touch. */
    public const MAX_ITEMS = 100;

    /** Readable alphabet: no 0/O, 1/l/I. */
    private const LETTERS = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ';

    private const DIGITS = '23456789';

    /**
     * Turkish reason the password is refused, null when it is fine (same rule
     * as a single mailbox: 8-128 characters, a letter and a digit).
     */
    public static function passwordProblem(string $password): ?string
    {
        $length = mb_strlen($password);
        if ($length < 8) {
            return 'Parola en az 8 karakter olmalı.';
        }
        if ($length > 128) {
            return 'Parola en fazla 128 karakter olabilir.';
        }
        if (preg_match('/\pL/u', $password) !== 1 || preg_match('/\d/', $password) !== 1) {
            return 'Parola en az bir harf ve bir rakam içermeli.';
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
     *
     * @throws MailcowException
     */
    public function apply(MailcowClient $client, string $username, string $action, ?string $password = null, ?bool $forceChange = null): void
    {
        match ($action) {
            self::ACTIVATE => $client->editMailbox($username, ['active' => 1]),
            self::DEACTIVATE => $client->editMailbox($username, ['active' => 0]),
            self::PASSWORD => $client->editMailbox($username, self::passwordAttributes((string) $password, $forceChange)),
            self::DELETE => $client->deleteMailbox($username),
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
