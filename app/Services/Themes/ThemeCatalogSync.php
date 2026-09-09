<?php

namespace App\Services\Themes;

use App\Enums\ThemeVisibility;
use App\Models\Theme;
use App\Services\GitHub\GitHubAppClient;
use App\Services\GitHub\ThemeManifest;
use Illuminate\Support\Carbon;

class ThemeCatalogSync
{
    public function __construct(
        private readonly GitHubAppClient $github = new GitHubAppClient,
    ) {}

    /**
     * @return array{synced: int, skipped: int, created: int, updated: int}
     */
    public function sync(): array
    {
        $repos = $this->github->listThemeRepos();
        $created = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($repos as $repo) {
            $themeId = $this->github->themeIdFromRepo($repo['full_name']);
            if ($themeId === '') {
                $skipped++;

                continue;
            }

            $ref = $repo['default_branch'] !== '' ? $repo['default_branch'] : 'main';
            $manifest = $this->github->fetchThemeManifest($repo['full_name'], $ref);
            $sha = $this->github->latestCommitSha($repo['full_name'], $ref);

            $theme = Theme::query()->where('repo_full_name', $repo['full_name'])->first()
                ?? Theme::query()->where('theme_id', $manifest?->themeId ?? $themeId)->first();

            $wasNew = $theme === null;
            $theme ??= new Theme;

            $this->applyRepo($theme, $repo, $manifest, $themeId, $ref, $sha);
            $theme->save();

            if ($wasNew) {
                $created++;
            } else {
                $updated++;
            }
        }

        return [
            'synced' => $created + $updated,
            'skipped' => $skipped,
            'created' => $created,
            'updated' => $updated,
        ];
    }

    /**
     * @param  array{name: string, full_name: string, default_branch: string, private: bool, html_url: string}  $repo
     */
    private function applyRepo(
        Theme $theme,
        array $repo,
        ?ThemeManifest $manifest,
        string $fallbackThemeId,
        string $ref,
        ?string $sha,
    ): void {
        $theme->theme_id = $manifest?->themeId ?? $fallbackThemeId;
        $theme->name = $manifest?->name ?? $theme->name ?? $theme->theme_id;
        $theme->repo_full_name = $repo['full_name'];
        $theme->default_ref = $ref;
        $theme->minimum_deamon_version = $manifest?->minimumDeamonVersion ?? $theme->minimum_deamon_version;
        $theme->description = $manifest?->description ?? $theme->description;
        $theme->latest_sha = $sha ?? $theme->latest_sha;
        $theme->last_synced_at = Carbon::now();

        if ($theme->visibility === null) {
            $theme->visibility = ThemeVisibility::Private;
        }
    }
}
