<?php

namespace App\Services\GitHub;

use App\Enums\Channel;
use App\Enums\ThemeGitSelectionMode;
use App\Enums\ThemeInstallationStatus;
use App\Jobs\SyncCoolifyEnvCatalogJob;
use App\Models\SiteThemeInstallation;
use App\Models\Theme;
use App\Models\ThemeGitConnection;
use App\Services\Coolify\EnvCatalog\DeamonRepo;
use App\Services\Themes\ThemeRolloutService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class GitHubWebhookHandler
{
    public function __construct(
        private readonly ThemeRolloutService $rollout = new ThemeRolloutService,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok: true, event: string, updated: bool, fanout: int, skipped: int}
     */
    public function handle(string $event, array $payload, ?string $deliveryId = null): array
    {
        if ($event === 'ping') {
            return ['ok' => true, 'event' => 'ping', 'updated' => false, 'fanout' => 0, 'skipped' => 0];
        }

        // GitHub "Redeliver" resends a delivery with its original id. Handling an
        // old push again would move every opted-in site back to that commit.
        if (! $this->firstDelivery($deliveryId)) {
            Log::info('github.webhook_duplicate_delivery', ['event' => $event]);

            return ['ok' => true, 'event' => $event, 'updated' => false, 'fanout' => 0, 'skipped' => 0];
        }

        $repo = $this->repoFullName($payload);
        if ($repo === null) {
            return ['ok' => true, 'event' => $event, 'updated' => false, 'fanout' => 0, 'skipped' => 0];
        }

        // CMS repo push → refresh that branch's Coolify env catalog (.env.production.example).
        $catalogChannel = $this->envCatalogChannel($event, $repo, $payload);
        if ($catalogChannel !== null) {
            SyncCoolifyEnvCatalogJob::dispatch($catalogChannel->value);

            return ['ok' => true, 'event' => $event, 'updated' => true, 'fanout' => 0, 'skipped' => 0];
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

        $defaultRef = $this->defaultRef($theme, $payload);
        $sha = null;
        $tag = $this->releaseTag($payload);

        if ($event === 'push') {
            // Only the catalog branch moves the catalog. A push to a feature
            // branch or a tag must never reach sites that follow the theme.
            if (! $this->isPushTo($payload, $defaultRef)) {
                return ['ok' => true, 'event' => $event, 'updated' => false, 'fanout' => 0, 'skipped' => 0];
            }

            $sha = $this->commitSha($payload);
            if ($sha === null || $sha === $theme->latest_sha) {
                return ['ok' => true, 'event' => $event, 'updated' => false, 'fanout' => 0, 'skipped' => 0];
            }
        } elseif ($tag === null) {
            return ['ok' => true, 'event' => $event, 'updated' => false, 'fanout' => 0, 'skipped' => 0];
        }

        if ($sha !== null) {
            $theme->latest_sha = $sha;
        }
        if ($tag !== null) {
            $theme->latest_tag = $tag;
        }
        $theme->last_synced_at = Carbon::now();
        $theme->save();

        // A release only names a tag; its target is a branch name, not a commit,
        // so it updates the catalog label and leaves installations alone.
        if ($sha === null) {
            return ['ok' => true, 'event' => $event, 'updated' => true, 'fanout' => 0, 'skipped' => 0];
        }

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
            if ($installation->ref !== '' && $installation->ref !== $defaultRef) {
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
    /**
     * A `push` to an allowlisted branch of the CMS repo (config ops.deamon.repository).
     *
     * @param  array<string, mixed>  $payload
     */
    private function envCatalogChannel(string $event, string $repo, array $payload): ?Channel
    {
        if ($event !== 'push') {
            return null;
        }

        $cms = DeamonRepo::fullName();
        if ($cms === null || strcasecmp($cms, $repo) !== 0) {
            return null;
        }

        $ref = $payload['ref'] ?? null;
        if (! is_string($ref) || ! str_starts_with($ref, 'refs/heads/')) {
            return null;
        }

        $channel = Channel::tryFrom(substr($ref, 11));

        return $channel !== null && $channel->isAllowed() ? $channel : null;
    }

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
        $sha = $payload['after'] ?? $payload['head_commit']['id'] ?? null;

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
    private function isPushTo(array $payload, string $branch): bool
    {
        $ref = $payload['ref'] ?? null;

        return is_string($ref) && $ref === 'refs/heads/'.$branch;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function defaultRef(Theme $theme, array $payload): string
    {
        $ref = trim((string) $theme->default_ref);
        if ($ref !== '') {
            return $ref;
        }

        $branch = $payload['repository']['default_branch'] ?? null;

        return is_string($branch) && $branch !== '' ? $branch : 'main';
    }

    private function firstDelivery(?string $deliveryId): bool
    {
        $deliveryId = trim((string) $deliveryId);
        if ($deliveryId === '') {
            return true;
        }

        return Cache::add('github-webhook-delivery:'.sha1($deliveryId), true, now()->addDays(7));
    }
}
