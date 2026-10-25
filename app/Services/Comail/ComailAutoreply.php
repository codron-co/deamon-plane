<?php

namespace App\Services\Comail;

/**
 * Auto-reply as a mailcow sieve prefilter (`script_desc` = comail-autoreply).
 *
 * The script is built here, never from customer sieve: user text only ever
 * lands inside quoted strings with `\` and `"` escaped. The settings the CMS
 * reads back travel in a base64 JSON comment on the first line, so reading
 * never has to parse sieve.
 */
final class ComailAutoreply
{
    public const DESCRIPTION = 'comail-autoreply';

    private const META_PREFIX = '# comail-meta: ';

    /**
     * @param  array{subject: string, body: string, starts_on?: string|null, ends_on?: string|null}  $settings
     */
    public static function script(array $settings): string
    {
        $subject = self::clean($settings['subject'], singleLine: true);
        $body = self::clean($settings['body'], singleLine: false);
        $startsOn = self::date($settings['starts_on'] ?? null);
        $endsOn = self::date($settings['ends_on'] ?? null);

        $meta = base64_encode((string) json_encode([
            'subject' => $subject,
            'body' => $body,
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $vacation = 'vacation :days 1 :subject "'.self::quote($subject).'" "'.self::quote($body).'";';

        $conditions = [];
        if ($startsOn !== null) {
            $conditions[] = 'currentdate :value "ge" "date" "'.$startsOn.'"';
        }
        if ($endsOn !== null) {
            $conditions[] = 'currentdate :value "le" "date" "'.$endsOn.'"';
        }

        $lines = [
            self::META_PREFIX.$meta,
            'require ["vacation","date","relational"];',
        ];
        if ($conditions === []) {
            $lines[] = $vacation;
        } else {
            $lines[] = 'if allof('.implode(', ', $conditions).') {';
            $lines[] = '  '.$vacation;
            $lines[] = '}';
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @return array{subject: string, body: string, starts_on: string|null, ends_on: string|null}
     */
    public static function read(string $script): array
    {
        $empty = ['subject' => '', 'body' => '', 'starts_on' => null, 'ends_on' => null];
        $first = strtok($script, "\n");
        if (! is_string($first) || ! str_starts_with($first, self::META_PREFIX)) {
            return $empty;
        }

        $decoded = base64_decode(trim(substr($first, strlen(self::META_PREFIX))), true);
        $meta = is_string($decoded) ? json_decode($decoded, true) : null;
        if (! is_array($meta)) {
            return $empty;
        }

        return [
            'subject' => is_string($meta['subject'] ?? null) ? $meta['subject'] : '',
            'body' => is_string($meta['body'] ?? null) ? $meta['body'] : '',
            'starts_on' => self::date($meta['starts_on'] ?? null),
            'ends_on' => self::date($meta['ends_on'] ?? null),
        ];
    }

    /**
     * Sieve quoted-string escaping (RFC 5228 §2.4.2): only `\` and `"`.
     */
    public static function quote(string $text): string
    {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $text);
    }

    private static function clean(string $text, bool $singleLine): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        // Control characters other than newline and tab never reach the script.
        $text = (string) preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/u', '', $text);
        if ($singleLine) {
            $text = str_replace(["\n", "\t"], ' ', $text);
        }

        return trim($text);
    }

    private static function date(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $value) !== 1) {
            return null;
        }

        [$year, $month, $day] = array_map('intval', explode('-', $value));

        return checkdate($month, $day, $year) ? $value : null;
    }
}
