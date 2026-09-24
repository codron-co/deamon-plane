<?php

namespace App\Services\GitHub;

use App\Enums\ThemeGitSelectionMode;
use App\Models\Theme;
use App\Models\ThemeGitConnection;

/**
 * Maps a GitHub webhook payload to the catalog theme it concerns: the
 * installation's connection first (and its `selected` repo list), else the
 * legacy global `repo_full_name` match.
 */
class GitHubThemeResolver
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function resolve(string $repo, array $payload): ?Theme
    {
        $connection = $this->connectionFromPayload($payload);
        if ($connection !== null
            && $connection->selection_mode === ThemeGitSelectionMode::Selected
            && ! $connection->repos()->where('repo_full_name', $repo)->where('included', true)->exists()) {
            return null;
        }

        return $this->themeForRepo($repo, $connection);
    }

    /**
     * The catalog branch: the theme's `default_ref`, else the repo default, else `main`.
     *
     * @param  array<string, mixed>  $payload
     */
    public function defaultRef(Theme $theme, array $payload): string
    {
        $ref = trim((string) $theme->default_ref);
        if ($ref !== '') {
            return $ref;
        }

        $branch = $payload['repository']['default_branch'] ?? null;

        return is_string($branch) && $branch !== '' ? $branch : 'main';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function connectionFromPayload(array $payload): ?ThemeGitConnection
    {
        $installationId = $payload['installation']['id'] ?? null;
        if ($installationId === null || $installationId === '') {
            return null;
        }

        return ThemeGitConnection::query()
            ->where('installation_id', (string) $installationId)
            ->first();
    }

    private function themeForRepo(string $repo, ?ThemeGitConnection $connection): ?Theme
    {
        if ($connection !== null) {
            $owned = Theme::query()
                ->where('theme_git_connection_id', $connection->id)
                ->where('repo_full_name', $repo)
                ->first();
            if ($owned !== null) {
                return $owned;
            }
        }

        return Theme::query()->where('repo_full_name', $repo)->first();
    }
}
