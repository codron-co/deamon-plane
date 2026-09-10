<?php

namespace App\Support;

class IdentityMark
{
    public static function letter(?string $value): string
    {
        $name = trim((string) $value);
        if ($name === '') {
            return '?';
        }

        $letter = mb_substr($name, 0, 1, 'UTF-8');
        if ($letter === '' || $letter === "\u{FFFD}") {
            return '?';
        }

        $upper = mb_strtoupper($letter, 'UTF-8');

        return $upper !== '' ? $upper : '?';
    }
}
