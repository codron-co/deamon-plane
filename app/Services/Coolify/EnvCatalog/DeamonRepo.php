<?php

namespace App\Services\Coolify\EnvCatalog;

use App\Models\GithubSetting;
use App\Services\GitHub\GitHubAppClient;

/**
 * The CMS git repository (`config('ops.deamon.repository')`, e.g. https://github.com/codron-co/deamon.git)
 * and a GitHub client able to read files from it.
 *
 * Credentials come only from Settings → Deamon Git (`github_settings` installation / PAT),
 * never from Themes `theme_git_connections`.
 */
final class DeamonRepo
{
    public const ENV_EXAMPLE_PATH = '.env.production.example';

    /**
     * `owner/name` derived from the configured repository URL, or null when it is not a github.com URL.
     */
    public static function fullName(): ?string
    {
        $repository = trim((string) config('ops.deamon.repository', ''));
        if ($repository === '') {
            return null;
        }

        if (preg_match('~github\.com[:/]([^/\s]+)/([^/\s]+?)(?:\.git)?/?$~i', $repository, $match) === 1) {
            return $match[1].'/'.$match[2];
        }

        if (preg_match('~^([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+?)(?:\.git)?$~', $repository, $match) === 1) {
            return $match[1].'/'.$match[2];
        }

        return null;
    }

    public static function owner(): ?string
    {
        $full = self::fullName();

        return $full !== null ? explode('/', $full, 2)[0] : null;
    }

    /**
     * A GitHub client with Deamon Git credentials (Settings installation or PAT).
     * Null when Plane has no CMS-repo credentials.
     */
    public static function client(): ?GitHubAppClient
    {
        $settings = GithubSetting::current();
        if (! $settings->hasDeamonGitCredentials()) {
            return null;
        }

        return GitHubAppClient::fromSettings($settings);
    }

    public static function hasCredentials(): bool
    {
        return self::client() !== null;
    }
}
