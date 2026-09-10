<?php

namespace App\Services\Themes;

use App\Enums\ThemeGitConnectionStatus;
use App\Enums\ThemeGitSelectionMode;
use App\Enums\ThemeVisibility;
use App\Models\Theme;
use App\Models\ThemeGitConnection;
use App\Services\GitHub\GitHubApiException;
use App\Services\GitHub\GitHubAppClient;
use App\Services\GitHub\GitHubCredentialsException;
use App\Services\GitHub\ThemeManifest;
use Illuminate\Support\Carbon;

class ThemeCatalogSync
{
    /**
     * @return array{synced: int, skipped: int, created: int, updated: int}
     */
    public function sync(): array
    {
        $connections = ThemeGitConnection::query()
            ->where('status', ThemeGitConnectionStatus::Connected)
            ->orderBy('id')
            ->get();

        $created = 0;
        $updated = 0;
        $skipped = 0;

        $errors = [];

        foreach ($connections as $connection) {
            try {
                $result = $this->syncConnection($connection);
                $created += $result['created'];
                $updated += $result['updated'];
                $skipped += $result['skipped'];
            } catch (GitHubCredentialsException|GitHubApiException $exception) {
                $errors[] = $exception;
                $skipped++;
            }
        }

        if ($errors !== [] && $created === 0 && $updated === 0) {
            throw $errors[0];
        }

        return [
            'synced' => $created + $updated,
            'skipped' => $skipped,
            'created' => $created,
            'updated' => $updated,
        ];
    }

    /**
     * @return array{created: int, updated: int, skipped: int}
     */
    public function syncConnection(ThemeGitConnection $connection): array
    {
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $client = GitHubAppClient::fromConnection($connection);

        try {
            $repos = $this->reposForConnection($connection, $client);
        } catch (GitHubCredentialsException|GitHubApiException $exception) {
            $connection->markError($exception->getMessage());

            throw $exception;
        }

        foreach ($repos as $repo) {
            $prefix = $connection->normalizedPrefix();
            $themeId = $client->themeIdFromRepo($repo['full_name'], $prefix);
            if ($themeId === '') {
                $skipped++;

                continue;
            }

            $ref = $repo['default_branch'] !== '' ? $repo['default_branch'] : 'main';
            $manifest = $client->fetchThemeManifest($repo['full_name'], $ref);
            $sha = $client->latestCommitSha($repo['full_name'], $ref);
            $resolvedId = $manifest?->themeId ?? $themeId;

            $theme = Theme::query()->where('repo_full_name', $repo['full_name'])->first();
            if ($theme === null) {
                $existing = Theme::query()->where('theme_id', $resolvedId)->first();
                if ($existing !== null && $existing->repo_full_name !== $repo['full_name']) {
                    $skipped++;

                    continue;
                }

                $theme = $existing;
            }

            $wasNew = $theme === null;
            $theme ??= new Theme;

            $this->applyRepo($theme, $connection, $repo, $manifest, $resolvedId, $ref, $sha);
            $theme->save();

            if ($wasNew) {
                $created++;
            } else {
                $updated++;
            }
        }

        $connection->markConnected();

        return [
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
        ];
    }

    /**
     * @return list<array{name: string, full_name: string, default_branch: string, private: bool, html_url: string}>
     */
    private function reposForConnection(ThemeGitConnection $connection, GitHubAppClient $client): array
    {
        if ($connection->selection_mode === ThemeGitSelectionMode::Selected) {
            return $connection->repos()
                ->where('included', true)
                ->orderBy('repo_full_name')
                ->get()
                ->map(static fn ($row): array => [
                    'name' => $row->shortName(),
                    'full_name' => $row->repo_full_name,
                    'default_branch' => (string) ($row->default_branch ?: 'main'),
                    'private' => (bool) $row->is_private,
                    'html_url' => (string) ($row->html_url ?? ''),
                ])
                ->all();
        }

        $prefix = $connection->normalizedPrefix();
        $repos = [];

        foreach ($client->listAccessibleRepos($connection) as $repo) {
            if ($prefix !== null && ! str_starts_with($repo['name'], $prefix)) {
                continue;
            }

            $repos[] = [
                'name' => $repo['name'],
                'full_name' => $repo['full_name'],
                'default_branch' => $repo['default_branch'],
                'private' => $repo['private'],
                'html_url' => $repo['html_url'],
            ];
        }

        return $repos;
    }

    /**
     * @param  array{name: string, full_name: string, default_branch: string, private: bool, html_url: string}  $repo
     */
    private function applyRepo(
        Theme $theme,
        ThemeGitConnection $connection,
        array $repo,
        ?ThemeManifest $manifest,
        string $themeId,
        string $ref,
        ?string $sha,
    ): void {
        $theme->theme_id = $manifest?->themeId ?? $themeId;
        $theme->name = $manifest?->name ?? $theme->name ?? $theme->theme_id;
        $theme->repo_full_name = $repo['full_name'];
        $theme->default_ref = $ref;
        $theme->minimum_deamon_version = $manifest?->minimumDeamonVersion ?? $theme->minimum_deamon_version;
        $theme->description = $manifest?->description ?? $theme->description;
        $theme->latest_sha = $sha ?? $theme->latest_sha;
        $theme->last_synced_at = Carbon::now();
        $theme->theme_git_connection_id = $connection->id;

        if ($theme->visibility === null) {
            $theme->visibility = ThemeVisibility::Private;
        }
    }
}
