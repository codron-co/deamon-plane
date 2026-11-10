<?php

namespace App\Services\Themes;

use App\Models\Theme;

/**
 * What a theme installation's `ref` means (codron-co/deamon-plane#50):
 * the catalog's default branch (follows `latest_sha`), another branch or tag
 * (follows its own head), or a commit (fixed; the CMS clones the default
 * branch and checks the commit out — `git clone --branch` takes no SHA).
 */
final class ThemeRef
{
    public static function defaultRef(Theme $theme): string
    {
        $ref = trim((string) $theme->default_ref);

        return $ref !== '' ? $ref : 'main';
    }

    /** A full 40-character commit SHA. */
    public static function isCommit(string $ref): bool
    {
        return (bool) preg_match('/^[0-9a-f]{40}$/i', $ref);
    }

    /** 7–39 hex characters: an abbreviated commit that must be expanded before install. */
    public static function isShortCommit(string $ref): bool
    {
        return (bool) preg_match('/^[0-9a-f]{7,39}$/i', $ref);
    }

    public static function tracksDefault(Theme $theme, ?string $ref): bool
    {
        $ref = trim((string) $ref);

        return $ref === '' || $ref === self::defaultRef($theme);
    }

    /** The ref the CMS passes to `git clone --branch`. */
    public static function cloneRef(Theme $theme, ?string $ref): string
    {
        $ref = trim((string) $ref);

        return $ref === '' || self::isCommit($ref) ? self::defaultRef($theme) : $ref;
    }
}
