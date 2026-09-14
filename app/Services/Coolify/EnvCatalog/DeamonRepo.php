<?php

namespace App\Services\Coolify\EnvCatalog;

use App\Enums\ThemeGitConnectionStatus;
use App\Models\GithubSetting;
use App\Models\ThemeGitConnection;
use App\Services\GitHub\GitHubAppClient;

/**
 * The CMS git repository (`config('ops.deamon.repository')`, e.g. https://github.com/codron-co/deamon.git)
 * and a GitHub client able to read files from it.
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
     * A GitHub client with credentials that can read the CMS repo, preferring a Themes
     * connection on the same account, then the legacy Settings PAT / App installation.
     * Null when Plane has no GitHub credentials at all.
     */
    public static function client(): ?GitHubAppClient
    {
        $owner = self::owner();

        if ($owner !== null) {
            $connection = ThemeGitConnection::query()
                ->where('status', ThemeGitConnectionStatus::Connected->value)
                ->get()
                ->first(static fn (ThemeGitConnection $row): bool => strcasecmp((string) $row->account_login, $owner) === 0);

            if ($connection instanceof ThemeGitConnection) {
                return GitHubAppClient::fromConnection($connection);
            }
        }

        $settings = GithubSetting::current();
        if ($settings->hasAnyCredentials()) {
            return GitHubAppClient::fromSettings($settings);
        }

        return null;
    }

    public static function hasCredentials(): bool
    {
        return self::client() !== null;
    }
}
