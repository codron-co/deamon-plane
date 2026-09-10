<?php

namespace App\Support;

use Illuminate\Http\Request;
use RuntimeException;

final class ThemeGitOAuthState
{
    public const SESSION_KEY = 'theme_git.oauth_state';

    public static function put(string $purpose): string
    {
        $state = bin2hex(random_bytes(20));

        session()->put(self::SESSION_KEY, [
            'hash' => self::hash($state),
            'purpose' => $purpose,
            'expires_at' => now()->addMinutes(30)->timestamp,
        ]);

        return $state;
    }

    /**
     * @throws RuntimeException
     */
    public static function assert(Request $request, string $purpose, bool $allowMissingQuery = false): void
    {
        $stored = $request->session()->pull(self::SESSION_KEY);
        $given = trim((string) $request->query('state', ''));

        if (! is_array($stored)
            || ($stored['purpose'] ?? '') !== $purpose
            || ! is_string($stored['hash'] ?? null)
            || (int) ($stored['expires_at'] ?? 0) < time()) {
            throw new RuntimeException('invalid');
        }

        if ($given === '') {
            if ($allowMissingQuery) {
                return;
            }

            throw new RuntimeException('missing');
        }

        if (! hash_equals((string) $stored['hash'], self::hash($given))) {
            throw new RuntimeException('mismatch');
        }
    }

    private static function hash(string $state): string
    {
        return hash_hmac('sha256', $state, (string) config('app.key'));
    }
}
