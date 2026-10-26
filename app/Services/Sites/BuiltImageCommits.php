<?php

namespace App\Services\Sites;

use App\Enums\Channel;
use App\Models\Site;
use App\Services\Coolify\CoolifyApiException;
use App\Services\Coolify\CoolifyApplicationService;
use App\Services\Coolify\Dto\CoolifyApplication;
use App\Services\Coolify\Dto\CoolifyDeployment;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

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

    /**
     * Every channel Plane offers has an image builder: fleet-wide controls (the
     * Sites list bulk menus) can describe the built-image behaviour only.
     */
    public function coversFleet(): bool
    {
        foreach (Channel::cases() as $channel) {
            if ($channel->isAllowed() && ! $this->appliesToChannel($channel)) {
                return false;
            }
        }

        return true;
    }

    public function appliesToChannel(Channel $channel): bool
    {
        return $this->builderFor($channel) !== null;
    }

    /**
     * Newest commit the channel's builder finished (and the host had time to tag).
     * `$registry`: the site runs a Docker Image app, so a build whose tag is already
     * in the local registry counts as tagged even inside the grace period.
     *
     * @throws BuiltImageException
     */
    public function latest(Channel $channel, CoolifyApplicationService $coolify, bool $registry = false): string
    {
        $builds = $this->builds($channel, $coolify);

        foreach ($builds as $build) {
            if ($this->isTagged($build) || ($registry && $this->registryHas($build['sha']))) {
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
     * `$registry`: a Docker Image site also accepts a full sha whose tag is in the
     * local registry (an older build beyond the scanned deployments, or one still
     * inside the tag grace).
     *
     * @throws BuiltImageException
     */
    public function resolve(Channel $channel, string $ref, CoolifyApplicationService $coolify, bool $registry = false): string
    {
        $ref = strtolower(trim($ref));
        if (preg_match('/^[0-9a-f]{7,40}$/', $ref) !== 1) {
            throw new BuiltImageException(__('site_ops.image.not_a_commit', ['ref' => $ref]));
        }

        // A build that finished after the cached read is looked up once more, live.
        $build = $this->find($this->builds($channel, $coolify), $ref)
            ?? $this->find($this->builds($channel, $coolify, fresh: true), $ref);

        if ($build === null && $registry && strlen($ref) === 40 && $this->registryHas($ref)) {
            return $ref;
        }

        if ($build === null) {
            throw BuiltImageException::pending(__('site_ops.image.not_built', [
                'sha' => substr($ref, 0, 8),
                'channel' => $channel->value,
            ]));
        }

        if (! $this->isTagged($build) && ! ($registry && $this->registryHas($build['sha']))) {
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
     * `$app` is the site's Coolify app when the caller already read it.
     *
     * @throws BuiltImageException|CoolifyApiException
     */
    public function currentOrLatest(Site $site, CoolifyApplicationService $coolify, ?CoolifyApplication $app = null): string
    {
        $channel = self::channelOf($site);
        $app ??= $coolify->getApp((string) $site->coolify_app_uuid);
        $registry = $app->isDockerImagePack();
        $current = strtolower(trim((string) $app->pinnedCommit()));

        if (preg_match('/^[0-9a-f]{40}$/', $current) === 1) {
            $build = $this->find($this->builds($channel, $coolify), $current);
            if ($build !== null && $this->isTagged($build)) {
                return $build['sha'];
            }
            if ($registry && $this->registryHas($current)) {
                return $current;
            }
        }

        return $this->latest($channel, $coolify, $registry);
    }

    /**
     * Full shas the channel builder finished (newest first, within the scanned
     * deployments). A site pinned to one of them runs a release of its channel.
     *
     * @return list<string>
     *
     * @throws BuiltImageException
     */
    public function builtShas(Channel $channel, CoolifyApplicationService $coolify): array
    {
        return array_values(array_unique(array_map(
            static fn (array $build): string => $build['sha'],
            $this->builds($channel, $coolify),
        )));
    }

    /**
     * Newest builds of the channel for the site page's commit picker (sha and
     * finish time), newest first. Empty when the builder cannot be read.
     *
     * @return list<array{sha: string, finished_at: ?string}>
     */
    public function recentBuilds(Channel $channel, CoolifyApplicationService $coolify): array
    {
        try {
            return $this->builds($channel, $coolify);
        } catch (BuiltImageException) {
            return [];
        }
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

    /**
     * The pin PATCH for this app's build pack. A Docker Image app (deamon-test,
     * `127.0.0.1:5000/deamon-cms:<commit>`) is pinned by its image tag; the image
     * name is left alone. Compose apps keep pinBody().
     *
     * @return array<string, mixed>
     */
    public static function pinBodyFor(?CoolifyApplication $app, string $sha): array
    {
        if ($app?->isDockerImagePack()) {
            return [
                'docker_registry_image_tag' => $sha,
                'is_auto_deploy_enabled' => false,
            ];
        }

        return self::pinBody($sha);
    }

    /**
     * PATCH the app onto a built commit with the field its build pack pins by
     * (compose: `git_commit_sha`, Docker Image: `docker_registry_image_tag`).
     * `$app` is the app when the caller already read it; else one GET first.
     * `$composeExtra` goes along only for a git-based app (a channel switch's
     * `git_branch`: a Docker Image app has no branch, its tag is the whole pin).
     *
     * @param  array<string, mixed>  $composeExtra
     *
     * @throws CoolifyApiException
     */
    public function pinApp(CoolifyApplicationService $coolify, string $uuid, string $sha, ?CoolifyApplication $app = null, array $composeExtra = []): CoolifyApplication
    {
        $app ??= $coolify->getApp($uuid);
        $body = self::pinBodyFor($app, $sha);
        if (! $app->isDockerImagePack()) {
            $body = $composeExtra + $body;
        }

        return $coolify->patchApplication($uuid, $body);
    }

    /**
     * The local registry has `deamon-cms:<sha>` (tag-images.sh pushes every tag it
     * makes). Off when `ops.deamon.image_registry.url` is empty; an unreachable
     * registry (Plane's container may not see the host's 127.0.0.1) is a "no", and
     * the builder check above stays the rule.
     */
    public function registryHas(string $sha): bool
    {
        $url = rtrim(trim((string) config('ops.deamon.image_registry.url', '')), '/');
        $repository = trim((string) config('ops.deamon.image_registry.repository', 'deamon-cms'), '/');
        if ($url === '' || $repository === '' || preg_match('/^[0-9a-f]{40}$/', $sha) !== 1) {
            return false;
        }

        try {
            return Http::timeout(max(1, (int) config('ops.deamon.image_registry.timeout', 3)))
                ->withHeaders(['Accept' => implode(', ', [
                    'application/vnd.oci.image.index.v1+json',
                    'application/vnd.oci.image.manifest.v1+json',
                    'application/vnd.docker.distribution.manifest.list.v2+json',
                    'application/vnd.docker.distribution.manifest.v2+json',
                ])])
                ->head($url.'/v2/'.$repository.'/manifests/'.$sha)
                ->successful();
        } catch (Throwable) {
            return false;
        }
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
