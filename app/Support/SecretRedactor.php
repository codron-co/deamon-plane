<?php

namespace App\Support;

final class SecretRedactor
{
    /**
     * @param  list<string>  $secrets
     */
    public static function redact(string $text, array $secrets): string
    {
        foreach ($secrets as $secret) {
            if ($secret === '') {
                continue;
            }

            $text = str_replace($secret, '[redacted]', $text);
        }

        return $text;
    }

    /**
     * @param  list<string>  $secrets
     */
    public static function containsSecret(string $text, array $secrets): bool
    {
        foreach ($secrets as $secret) {
            if ($secret !== '' && str_contains($text, $secret)) {
                return true;
            }
        }

        return false;
    }
}
