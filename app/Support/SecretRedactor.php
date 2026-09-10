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

    /**
     * @param  list<string>  $secrets
     */
    public static function redactSensitive(string $text, array $secrets = []): string
    {
        $text = self::redact($text, $secrets);

        $replacements = [
            '/Bearer\s+\S+/i' => 'Bearer [redacted]',
            '/\bAPP_KEY\s*=\s*\S+/i' => 'APP_KEY=[redacted]',
            '/\bCONTROL_PLANE_AGENT_SECRET\s*=\s*\S+/i' => 'CONTROL_PLANE_AGENT_SECRET=[redacted]',
            '/\bbase64:[A-Za-z0-9+\/]{20,}={0,2}/' => 'base64:[redacted]',
        ];

        foreach ($replacements as $pattern => $replacement) {
            $redacted = preg_replace($pattern, $replacement, $text);
            if (is_string($redacted)) {
                $text = $redacted;
            }
        }

        return $text;
    }
}
