<?php

namespace App\Services\Comail;

use Illuminate\Support\Carbon;
use Throwable;

/**
 * Spam filter of one mailbox (level + allow/block lists) and the site's
 * quarantine, on top of mailcow. Levels are mailcow spam score pairs
 * "low,high": low marks the message as spam (Junk), high rejects it.
 * Every list entry and quarantine item is checked against the site.
 */
class ComailSpam
{
    /** @var array<string, array{0: float, 1: float}> */
    public const LEVELS = [
        'relaxed' => [10.0, 20.0],
        'normal' => [8.0, 15.0],
        'strict' => [5.0, 10.0],
    ];

    /** Allow/block entries per mailbox (mailcow has no limit; the UI does not need more). */
    public const LIST_LIMIT = 200;

    public const QUARANTINE_LIMIT = 200;

    /**
     * `{level, score{low,high}, allow[{id,value}], block[{id,value}]}`.
     *
     * @return array{level: string, score: array{low: float, high: float}, allow: list<array{id: int, value: string}>, block: list<array{id: int, value: string}>}
     */
    public function state(MailcowClient $client, string $address): array
    {
        [$low, $high] = self::parseScore($client->spamScore($address));

        return [
            'level' => self::levelOf($low, $high),
            'score' => ['low' => $low, 'high' => $high],
            'allow' => $this->entries($client, $address, 'wl'),
            'block' => $this->entries($client, $address, 'bl'),
        ];
    }

    public function setLevel(MailcowClient $client, string $address, string $level): void
    {
        if (! isset(self::LEVELS[$level])) {
            throw ComailException::validation('Seviye relaxed, normal ya da strict olmalı.');
        }

        [$low, $high] = self::LEVELS[$level];
        $client->editSpamScore($address, self::number($low).','.self::number($high));
    }

    /**
     * @param  'wl'|'bl'  $list
     */
    public function add(MailcowClient $client, string $address, string $list, string $value): string
    {
        $value = self::normaliseSender($value);
        if ($value === null) {
            throw ComailException::validation('Bir e-posta adresi (ad@ornek.com) ya da bir alan adının tamamı (*@ornek.com) yazın.');
        }

        $entries = $this->entries($client, $address, $list);
        foreach ($entries as $entry) {
            if ($entry['value'] === $value) {
                throw ComailException::validation('Bu adres listede zaten var.');
            }
        }
        if (count($entries) >= self::LIST_LIMIT) {
            throw ComailException::validation('Listede en fazla '.self::LIST_LIMIT.' adres olabilir.');
        }

        $other = $list === 'wl' ? 'bl' : 'wl';
        foreach ($this->entries($client, $address, $other) as $entry) {
            if ($entry['value'] === $value) {
                throw ComailException::validation($list === 'wl'
                    ? 'Bu adres engellenenler listesinde; önce oradan kaldırın.'
                    : 'Bu adres izin verilenler listesinde; önce oradan kaldırın.');
            }
        }

        $client->addMailboxPolicy($address, $list, $value);

        return $value;
    }

    /**
     * @param  'wl'|'bl'  $list
     * @return string|null the removed value; null when the id is not on this mailbox's list
     */
    public function remove(MailcowClient $client, string $address, string $list, string $id): ?string
    {
        $wanted = ctype_digit($id) ? (int) $id : -1;
        foreach ($this->entries($client, $address, $list) as $entry) {
            if ($entry['id'] === $wanted) {
                $client->deleteMailboxPolicy($wanted);

                return $entry['value'];
            }
        }

        return null;
    }

    /**
     * Quarantined messages addressed to the site's CoMail domains, newest first.
     *
     * @return list<array{id: int, mailbox: string, sender: string, subject: string, score: float, virus: bool, created_at: string|null}>
     */
    public function quarantine(ComailDirectory $directory): array
    {
        $items = [];
        foreach ($this->ownedQuarantine($directory) as $row) {
            $items[] = [
                'id' => (int) ($row['id'] ?? 0),
                'mailbox' => strtolower((string) ($row['rcpt'] ?? '')),
                'sender' => (string) ($row['sender'] ?? ''),
                'subject' => mb_substr((string) ($row['subject'] ?? ''), 0, 300),
                'score' => round((float) ($row['score'] ?? 0), 2),
                // mailcow: LOCATE("VIRUS_FOUND", symbols) → position, 0 when clean.
                'virus' => (int) ($row['virus_flag'] ?? 0) > 0,
                'created_at' => self::isoDate($row['created'] ?? null),
            ];
        }

        usort($items, static fn (array $a, array $b): int => [$b['created_at'] ?? '', $b['id']] <=> [$a['created_at'] ?? '', $a['id']]);

        return array_slice($items, 0, self::QUARANTINE_LIMIT);
    }

    /**
     * The quarantine row with this id when its recipient belongs to the site.
     *
     * @return array<string, mixed>|null
     */
    public function quarantineItem(ComailDirectory $directory, string $id): ?array
    {
        $wanted = ctype_digit($id) ? (int) $id : -1;
        foreach ($this->ownedQuarantine($directory) as $row) {
            if ((int) ($row['id'] ?? 0) === $wanted) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function ownedQuarantine(ComailDirectory $directory): array
    {
        return array_values(array_filter(
            $directory->client->quarantine(),
            static fn (array $row): bool => $directory->ownsAddress(strtolower((string) ($row['rcpt'] ?? ''))),
        ));
    }

    /**
     * Entries of the mailbox itself (mailcow also lists domain-wide ones).
     *
     * @param  'wl'|'bl'  $list
     * @return list<array{id: int, value: string}>
     */
    private function entries(MailcowClient $client, string $address, string $list): array
    {
        $out = [];
        foreach ($client->mailboxPolicies($address, $list) as $row) {
            if (strtolower((string) ($row['object'] ?? '')) !== strtolower($address)) {
                continue;
            }
            $out[] = ['id' => (int) ($row['prefid'] ?? 0), 'value' => strtolower((string) ($row['value'] ?? ''))];
        }

        return $out;
    }

    /**
     * `user@example.com` or `*@example.com` (`@example.com` and `example.com`
     *
     * become `*@example.com`); null when it is neither. mailcow accepts letters,
     * digits and `@ _ . - *` only.
     */
    public static function normaliseSender(string $value): ?string
    {
        $value = strtolower(trim($value));
        if ($value !== '' && ! str_contains($value, '@')) {
            $value = '*@'.$value;
        } elseif (str_starts_with($value, '@')) {
            $value = '*'.$value;
        }

        $domain = '(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}';
        $local = '(?:\*|[a-z0-9_](?:[a-z0-9._-]{0,62}[a-z0-9_])?)';

        return strlen($value) <= 254 && preg_match('/\A'.$local.'@'.$domain.'\z/', $value) === 1 ? $value : null;
    }

    /**
     * @return array{0: float, 1: float}
     */
    public static function parseScore(?string $score): array
    {
        $parts = array_map('trim', explode(',', (string) $score));
        if (count($parts) === 2 && is_numeric($parts[0]) && is_numeric($parts[1])) {
            return [(float) $parts[0], (float) $parts[1]];
        }

        return self::parseScoreDefault();
    }

    public static function levelOf(float $low, float $high): string
    {
        foreach (self::LEVELS as $level => [$levelLow, $levelHigh]) {
            if (abs($low - $levelLow) < 0.01 && abs($high - $levelHigh) < 0.01) {
                return $level;
            }
        }

        return 'custom';
    }

    /**
     * @return array{0: float, 1: float}
     */
    private static function parseScoreDefault(): array
    {
        $parts = explode(',', (string) config('ops.comail.spam_default_score', '8,15'));

        return [(float) $parts[0], (float) ($parts[1] ?? 15)];
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');
    }

    private static function isoDate(mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === 0 || $value === '0') {
            return null;
        }

        try {
            return is_numeric($value)
                ? Carbon::createFromTimestamp((int) $value)->toIso8601String()
                : Carbon::parse((string) $value)->toIso8601String();
        } catch (Throwable) {
            return null;
        }
    }
}
