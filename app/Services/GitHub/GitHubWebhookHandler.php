<?php

namespace App\Services\GitHub;

use App\Enums\ThemeGitSelectionMode;
use App\Enums\ThemeInstallationStatus;
use App\Models\SiteThemeInstallation;
use App\Models\Theme;
use App\Models\ThemeGitConnection;
use App\Services\Themes\ThemeRolloutService;
use Illuminate\Support\Carbon;

class GitHubWebhookHandler
{
    public function __construct(
        private readonly ThemeRolloutService $rollout = new ThemeRolloutService,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok: true, event: string, updated: bool, fanout: int, skipped: int}
     */
    public function handle(string $event, array $payload): array
    {
        if ($event === 'ping') {
            return ['ok' => true, 'event' => 'ping', 'updated' => false, 'fanout' => 0, 'skipped' => 0];
        }

        $repo = $this->repoFullName($payload);
        if ($repo === null) {
            return ['ok' => true, 'event' => $event, 'updated' => false, 'fanout' => 0, 'skipped' => 0];
        }

        $connection = $this->connectionFromPayload($payload);
        if ($connection !== null
            && $connection->selection_mode === ThemeGitSelectionMode::Selected
            && ! $connection->repos()->where('repo_full_name', $repo)->where('included', true)->exists()) {
            return ['ok' => true, 'event' => $event, 'updated' => false, 'fanout' => 0, 'skipped' => 0];
        }

        $theme = $this->themeForRepo($repo, $connection);
        if ($theme === null) {
            return ['ok' => true, 'event' => $event, 'updated' => false, 'fanout' => 0, 'skipped' => 0];
        }

        $sha = $this->commitSha($payload);
        $tag = $this->releaseTag($payload);

        if ($sha !== null) {
            $theme->latest_sha = $sha;
        }
        if ($tag !== null) {
            $theme->latest_tag = $tag;
        }
        $theme->last_synced_at = Carbon::now();
        $theme->save();

        $fanout = 0;
        $skipped = 0;
        $concurrency = max(1, (int) config('ops.themes.fanout_concurrency', 3));
        $index = 0;

        $installations = SiteThemeInstallation::query()
            ->where('theme_id', $theme->id)
            ->where('auto_update', true)
            ->whereIn('status', [
                ThemeInstallationStatus::Active->value,
                ThemeInstallationStatus::Error->value,
            ])
            ->get();

        foreach ($installations as $installation) {
            if ($sha !== null && $installation->ref !== '' && ! $this->refMatchesPush($payload, $installation->ref, $theme)) {
                $skipped++;

                continue;
            }

            $delay = (int) floor($index / $concurrency) * 10;
            $this->rollout->updateToLatest($installation, null, null, fromWebhook: true, delaySeconds: $delay);
            $fanout++;
            $index++;

            if ($index >= 200) {
                break;
            }
        }

        return [
            'ok' => true,
            'event' => $event,
            'updated' => true,
            'fanout' => $fanout,
            'skipped' => $skipped,
        ];
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

    /**
     * @param  array<string, mixed>  $payload
     */
    private function repoFullName(array $payload): ?string
    {
        $full = $payload['repository']['full_name'] ?? null;
        if (is_string($full) && $full !== '') {
            return $full;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function commitSha(array $payload): ?string
    {
        $sha = $payload['after'] ?? $payload['head_commit']['id'] ?? $payload['release']['target_commitish'] ?? null;

        return is_string($sha) && $sha !== '' && $sha !== '0000000000000000000000000000000000000000'
            ? $sha
            : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function releaseTag(array $payload): ?string
    {
        $tag = $payload['release']['tag_name'] ?? null;

        return is_string($tag) && $tag !== '' ? $tag : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function refMatchesPush(array $payload, string $installationRef, Theme $theme): bool
    {
        $ref = $payload['ref'] ?? null;
        if (! is_string($ref) || $ref === '') {
            return true;
        }

        $short = str_starts_with($ref, 'refs/heads/')
            ? substr($ref, 11)
            : (str_starts_with($ref, 'refs/tags/') ? substr($ref, 10) : $ref);

        return $short === $installationRef
            || $installationRef === $theme->default_ref
            || $short === $theme->default_ref;
    }
}
