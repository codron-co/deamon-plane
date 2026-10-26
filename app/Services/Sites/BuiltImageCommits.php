<?php

namespace App\Services\Sites;

use App\Enums\Channel;
use App\Models\Site;
use App\Services\Coolify\CoolifyApiException;
use App\Services\Coolify\CoolifyApplicationService;
use App\Services\Coolify\Dto\CoolifyDeployment;
use Illuminate\Support\Facades\Cache;

/**
 * Build once, run many (CMS 1.2.85). Deamon sites no longer build: one Coolify
 * "builder" app per channel builds `deamon-cms:<sha>` once per commit and every
 * site's compose runs `image: deamon-cms:${SOURCE_COMMIT}` with `pull_policy: never`.
 *
 * So a site deploy must target a commit its channel's builder has already
 * finished, with Coolify auto-deploy off (a push would start a commit with no
 * image) and `include_source_commit_in_build` on (otherwise SOURCE_COMMIT is
 * empty). This service answers which commit that is; `pinBody()` is the PATCH
 * that puts a site on it.
 *
 * A channel without a configured builder (`ops.deamon.image_builders`) keeps
 * the old behaviour: the site builds its branch itself.
 */
class BuiltImageCommits
{
    /** Builder deployments read per lookup (Coolify default page is 10). */
    private const SCAN = 20;

    public function builderFor(Channel $channel): ?string
    {
        $builders = (array) config('ops.deamon.image_builders', []);

        // `main` is kept equal to `beta`, so it runs the beta builder's images.
        $uuid = $builders[$channel->value]
            ?? ($channel === Channel::Main ? ($builders[Channel::Beta->value] ?? null) : null);
        $uuid = trim((string) $uuid);

        return $uuid === '' ? null : $uuid;
    }

    public function appliesTo(Site $site): bool
    {
        return $this->appliesToChannel(self::channelOf($site));
    }

    public function appliesToChannel(Channel $channel): bool
    {
        return $this->builderFor($channel) !== null;
    }

    /**
     * Newest commit the channel's builder finished (and the host had time to tag).
     *
     * @throws BuiltImageException
     */
    public function latest(Channel $channel, CoolifyApplicationService $coolify): string
    {
        $builds = $this->builds($channel, $coolify);

        foreach ($builds as $build) {
            if ($this->isTagged($build)) {
                return $build['sha'];
            }
        }

        if ($builds !== []) {
            throw BuiltImageException::pending(__('site_ops.image.tagging', [
                'channel' => $channel->value,
                'sha' => substr($builds[0]['sha'], 0, 8),
            ]));
        }

        throw new BuiltImageException(__('site_ops.image.no_build', ['channel' => $channel->value]));
    }

    /**
     * Full sha of a built commit matching `$ref` (a full or abbreviated sha).
     * Tags, branch names and HEAD are refused: only a built commit has an image.
     *
     * @throws BuiltImageException
     */
    public function resolve(Channel $channel, string $ref, CoolifyApplicationService $coolify): string
    {
        $ref = strtolower(trim($ref));
        if (preg_match('/^[0-9a-f]{7,40}$/', $ref) !== 1) {
            throw new BuiltImageException(__('site_ops.image.not_a_commit', ['ref' => $ref]));
        }

        // A build that finished after the cached read is looked up once more, live.
        $build = $this->find($this->builds($channel, $coolify), $ref)
            ?? $this->find($this->builds($channel, $coolify, fresh: true), $ref);

        if ($build === null) {
            throw BuiltImageException::pending(__('site_ops.image.not_built', [
                'sha' => substr($ref, 0, 8),
                'channel' => $channel->value,
            ]));
        }

        if (! $this->isTagged($build)) {
            throw BuiltImageException::pending(__('site_ops.image.tagging', [
                'channel' => $channel->value,
                'sha' => substr($build['sha'], 0, 8),
            ]));
        }

        return $build['sha'];
    }

    /**
     * What a rebuild of the site runs: the commit it is pinned to when that one
     * is a built image of its channel, else the channel's newest built commit.
     *
     * @throws BuiltImageException|CoolifyApiException
     */
    public function currentOrLatest(Site $site, CoolifyApplicationService $coolify): string
    {
        $channel = self::channelOf($site);
        $current = strtolower(trim((string) $coolify->getApp((string) $site->coolify_app_uuid)->gitCommitSha()));

        if (preg_match('/^[0-9a-f]{40}$/', $current) === 1) {
            $build = $this->find($this->builds($channel, $coolify), $current);
            if ($build !== null && $this->isTagged($build)) {
                return $build['sha'];
            }
        }

        return $this->latest($channel, $coolify);
    }

    /**
     * The Coolify application PATCH that puts a site on a built commit.
     *
     * @return array{git_commit_sha: string, is_auto_deploy_enabled: false, include_source_commit_in_build: true}
     */
    public static function pinBody(string $sha): array
    {
        return [
            'git_commit_sha' => $sha,
            'is_auto_deploy_enabled' => false,
            'include_source_commit_in_build' => true,
        ];
    }

    public static function channelOf(Site $site): Channel
    {
        return $site->channel instanceof Channel ? $site->channel : Channel::from((string) $site->channel);
    }

    /**
     * Finished builds of the channel's builder, newest first. Cached briefly so
     * a bulk sweep reads the builder once, not once per site.
     *
     * @return list<array{sha: string, finished_at: ?string}>
     *
     * @throws BuiltImageException
     */
    private function builds(Channel $channel, CoolifyApplicationService $coolify, bool $fresh = false): array
    {
        $builder = $this->builderFor($channel);
        if ($builder === null) {
            throw new BuiltImageException(__('site_ops.image.no_builder', ['channel' => $channel->value]));
        }

        $key = 'deamon-image-builds:'.md5($coolify->client()->credentials()->apiRoot().'|'.$builder);
        $ttl = max(0, (int) config('ops.deamon.image_builds_cache_seconds', 20));

        if (! $fresh && $ttl > 0) {
            $cached = Cache::get($key);
            if (is_array($cached)) {
                return $cached;
            }
        }

        try {
            $rows = $coolify->listAppDeployments($builder, 0, self::SCAN);
        } catch (CoolifyApiException $exception) {
            throw new BuiltImageException(__('site_ops.image.lookup_failed', [
                'channel' => $channel->value,
                'error' => $exception->getMessage(),
            ]), $exception->status, $exception);
        }

        $builds = $rows
            ->filter(static fn (CoolifyDeployment $deployment): bool => strtolower(trim((string) $deployment->status)) === 'finished'
                && preg_match('/^[0-9a-f]{40}$/', strtolower(trim((string) $deployment->commit))) === 1
                && (int) ($deployment->raw['pull_request_id'] ?? 0) === 0)
            ->sort(static fn (CoolifyDeployment $a, CoolifyDeployment $b): int => [
                (int) ($b->raw['id'] ?? 0),
                $b->startedAt()?->getTimestamp() ?? 0,
            ] <=> [
                (int) ($a->raw['id'] ?? 0),
                $a->startedAt()?->getTimestamp() ?? 0,
            ])
            ->map(static fn (CoolifyDeployment $deployment): array => [
                'sha' => strtolower(trim((string) $deployment->commit)),
                'finished_at' => $deployment->finishedAt()?->toIso8601String(),
            ])
            ->values()
            ->all();

        if ($ttl > 0) {
            Cache::put($key, $builds, $ttl);
        }

        return $builds;
    }

    /**
     * @param  list<array{sha: string, finished_at: ?string}>  $builds
     * @return array{sha: string, finished_at: ?string}|null
     */
    private function find(array $builds, string $ref): ?array
    {
        foreach ($builds as $build) {
            if (str_starts_with($build['sha'], $ref)) {
                return $build;
            }
        }

        return null;
    }

    /**
     * The host retags a finished build as `deamon-cms:<sha>` on a one-minute
     * cron; a build younger than the grace may not have its tag yet.
     *
     * @param  array{sha: string, finished_at: ?string}  $build
     */
    private function isTagged(array $build): bool
    {
        if ($build['finished_at'] === null) {
            return true;
        }

        $grace = max(0, (int) config('ops.deamon.image_tag_grace_seconds', 90));

        return now()->subSeconds($grace)->gte($build['finished_at']);
    }
}
