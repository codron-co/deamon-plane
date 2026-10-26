<?php

namespace App\Services\Sites;

use App\Enums\Channel;
use App\Enums\DeployGate;
use App\Enums\DeploymentStatus;
use App\Enums\DeploymentTrigger;
use App\Jobs\PollDeploymentJob;
use App\Models\Deployment;
use App\Models\FleetRollout;
use App\Models\Site;
use App\Models\User;
use App\Services\Coolify\CoolifyApiException;
use App\Services\Coolify\CoolifyApplicationService;
use App\Services\Coolify\CoolifyDeployBusyException;
use App\Services\Coolify\CoolifyDeployGate;
use App\Services\Coolify\Dto\CoolifyApplication;
use App\Services\Coolify\Dto\CoolifyDeployResult;
use App\Services\Ops\PacedFanout;

class CoolifyDeploySettings
{
    /**
     * @return array{build_pack: ?string, is_auto_deploy: ?bool, git_commit_sha: ?string, error: ?string}
     */
    public function snapshot(Site $site): array
    {
        $empty = [
            'build_pack' => null,
            'is_auto_deploy' => null,
            'git_commit_sha' => null,
            'error' => null,
        ];

        if (blank($site->coolify_app_uuid)) {
            return $empty;
        }

        try {
            $app = CoolifyApplicationService::forSite($site)->getApp((string) $site->coolify_app_uuid);
        } catch (CoolifyApiException $exception) {
            $empty['error'] = $exception->getMessage();

            return $empty;
        }

        $this->remember($site, $app->autoDeployState(), $app->pinnedCommit());

        // `git_commit_sha` is the pinned commit: a Docker Image app's image tag.
        return [
            'build_pack' => $app->buildPack,
            'is_auto_deploy' => $app->autoDeployState(),
            'git_commit_sha' => $app->pinnedCommit(),
            'error' => null,
        ];
    }

    public function setAutoDeploy(Site $site, bool $enabled, ?User $actor = null, ?string $ip = null): CoolifyApplication
    {
        $uuid = $this->requireApp($site);
        $runsImages = $this->runsBuiltImages($site);

        // A push would start a commit whose image does not exist yet.
        if ($enabled && $runsImages) {
            throw new BuiltImageException(__('site_ops.image.auto_deploy_blocked', ['name' => $site->name]));
        }

        // A CI-gated site must not build every push again behind Plane's back:
        // turning auto-deploy on goes through the gate switch, never around it.
        if ($enabled && $site->usesCiGate()) {
            throw new ComposePackException(__('rollouts.gate.auto_deploy_blocked', ['name' => $site->name]));
        }

        $app = $this->patchAutoDeploy($site, $uuid, $enabled, $runsImages ? ['include_source_commit_in_build' => true] : []);

        $this->remember($site, $enabled, $app->pinnedCommit());

        $this->audit($site, $actor, $ip, 'site.auto_deploy_updated', [
            'is_auto_deploy' => $enabled,
        ]);

        return $app;
    }

    /**
     * `coolify`: Coolify auto-deploys every push (auto-deploy on).
     * `ci`: auto-deploy off and the app follows the branch HEAD; Plane deploys it
     * after a green CI run. Neither direction starts a deploy.
     * A site that runs built images keeps its pin and auto-deploy stays off either
     * way: its `coolify` gate is a manual release (release.sh or a Plane deploy),
     * i.e. the site only leaves the CI rollouts.
     */
    public function setDeployGate(Site $site, DeployGate $gate, ?User $actor = null, ?string $ip = null): CoolifyApplication
    {
        $uuid = $this->requireApp($site);
        $before = $site->usesCiGate() ? DeployGate::Ci : DeployGate::Coolify;
        $runsImages = $this->runsBuiltImages($site);

        if ($runsImages) {
            $app = $this->patchAutoDeploy($site, $uuid, false, ['include_source_commit_in_build' => true]);
            $this->remember($site, false, $app->pinnedCommit());
        } elseif ($gate === DeployGate::Ci) {
            $app = $this->patchAutoDeploy($site, $uuid, false, [
                'git_commit_sha' => CoolifyApplication::HEAD_REF,
            ]);
            $this->remember($site, false, CoolifyApplication::HEAD_REF);
        } else {
            $app = $this->patchAutoDeploy($site, $uuid, true);
            $this->remember($site, true, $app->pinnedCommit());
        }

        Site::query()->toBase()->where('id', $site->getKey())->update(['deploy_gate' => $gate->value]);
        $site->forceFill(['deploy_gate' => $gate])->syncOriginalAttributes(['deploy_gate']);

        $site->auditLogs()->create([
            'actor_user_id' => $actor?->id,
            'action' => 'site.deploy_gate_updated',
            'before' => ['deploy_gate' => $before->value],
            'after' => [
                'deploy_gate' => $gate->value,
                'is_auto_deploy' => $gate === DeployGate::Coolify && ! $runsImages,
                'git_commit_sha' => $gate === DeployGate::Ci && ! $runsImages ? CoolifyApplication::HEAD_REF : null,
            ],
            'ip' => $ip,
        ]);

        return $app;
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function patchAutoDeploy(Site $site, string $uuid, bool $enabled, array $extra = []): CoolifyApplication
    {
        $coolify = CoolifyApplicationService::forSite($site);

        try {
            $app = $coolify->patchApplication($uuid, array_merge($extra, ['is_auto_deploy_enabled' => $enabled]));
            $verified = $app->autoDeployState();
            if ($verified !== $enabled) {
                $app = $coolify->getApp($uuid);
                $verified = $app->autoDeployState();
            }
            if ($verified !== $enabled) {
                throw new ComposePackException(__('site_ops.auto_deploy.verify_failed', [
                    'name' => $site->name,
                    'expected' => $enabled ? 'on' : 'off',
                ]));
            }
        } catch (CoolifyApiException $exception) {
            throw new ComposePackException($exception->getMessage(), $exception->status, $exception);
        }

        return $app;
    }

    /**
     * `$into` is a Plane `waiting` row the dispatcher is starting: it becomes the
     * live deployment instead of a new row being created.
     */
    public function pin(Site $site, string $ref, ?User $actor = null, ?string $ip = null, ?Deployment $into = null): CoolifyApplication
    {
        $uuid = $this->requireApp($site);
        $this->assertCanStartDeploy($site);
        $coolify = CoolifyApplicationService::forSite($site);
        // An image site may only be pinned to a commit its channel builder finished.
        $built = $this->builtCommit($site, $coolify, fn (BuiltImageCommits $images, CoolifyApplication $app): string => $images->resolve(
            BuiltImageCommits::channelOf($site), $ref, $coolify, $app->isDockerImagePack(),
        ));
        $ref = $built[0] ?? $ref;
        $this->preflight($site, $actor, $ip);

        try {
            $app = $built !== null
                ? app(BuiltImageCommits::class)->pinApp($coolify, $uuid, $built[0], $built[1])
                : $coolify->patchApplication($uuid, [
                    'git_commit_sha' => $ref,
                    'is_auto_deploy_enabled' => false,
                ]);
            $this->recordDeployment($site, $coolify->deploy($uuid), DeploymentTrigger::Manual, $actor, $ip, $into);
        } catch (CoolifyDeployBusyException $exception) {
            throw new ComposePackException($exception->getMessage(), $exception->getCode(), $exception);
        } catch (CoolifyApiException $exception) {
            throw new ComposePackException($exception->getMessage(), $exception->status, $exception);
        }

        // An operator pin holds the version: CI rollouts skip the site until it is released.
        $this->remember($site, false, $ref, held: true);

        $this->audit($site, $actor, $ip, 'site.git_pinned', [
            'git_commit_sha' => $ref,
            'is_auto_deploy' => false,
        ]);

        return $app;
    }

    public function followHead(Site $site, ?User $actor = null, ?string $ip = null, ?Deployment $into = null): CoolifyApplication
    {
        if ($this->runsBuiltImages($site)) {
            return $this->deployLatestBuilt($site, 'site.git_follow_head', $actor, $ip, $into);
        }

        $uuid = $this->requireApp($site);
        $this->assertCanStartDeploy($site);
        $this->preflight($site, $actor, $ip);
        $coolify = CoolifyApplicationService::forSite($site);
        $autoDeploy = ! $site->usesCiGate();

        try {
            $app = $coolify->patchApplication($uuid, [
                'git_commit_sha' => CoolifyApplication::HEAD_REF,
                // A CI-gated site follows HEAD too, but Plane keeps deploying it.
                'is_auto_deploy_enabled' => $autoDeploy,
            ]);
            $this->recordDeployment($site, $coolify->deploy($uuid), DeploymentTrigger::Manual, $actor, $ip, $into);
        } catch (CoolifyDeployBusyException $exception) {
            throw new ComposePackException($exception->getMessage(), $exception->getCode(), $exception);
        } catch (CoolifyApiException $exception) {
            throw new ComposePackException($exception->getMessage(), $exception->status, $exception);
        }

        $this->remember($site, $autoDeploy, CoolifyApplication::HEAD_REF);

        $this->audit($site, $actor, $ip, 'site.git_follow_head', [
            'git_commit_sha' => null,
            'is_auto_deploy' => $autoDeploy,
        ]);

        return $app;
    }

    /**
     * Move a pinned site to the branch HEAD once, without touching Coolify
     * auto-deploy: a site with auto-deploy off stays off and keeps waiting for
     * the next manual update. "Follow HEAD" is the variant that also turns
     * auto-deploy on.
     */
    public function updateToHead(Site $site, ?User $actor = null, ?string $ip = null, ?Deployment $into = null): CoolifyApplication
    {
        if ($this->runsBuiltImages($site)) {
            return $this->deployLatestBuilt($site, 'site.git_update_head', $actor, $ip, $into);
        }

        $uuid = $this->requireApp($site);
        $this->assertCanStartDeploy($site);
        $this->preflight($site, $actor, $ip);
        $coolify = CoolifyApplicationService::forSite($site);

        try {
            $app = $coolify->patchApplication($uuid, [
                'git_commit_sha' => CoolifyApplication::HEAD_REF,
            ]);
            $this->recordDeployment($site, $coolify->deploy($uuid), DeploymentTrigger::Manual, $actor, $ip, $into);
        } catch (CoolifyDeployBusyException $exception) {
            throw new ComposePackException($exception->getMessage(), $exception->getCode(), $exception);
        } catch (CoolifyApiException $exception) {
            throw new ComposePackException($exception->getMessage(), $exception->status, $exception);
        }

        $autoDeploy = $app->autoDeployState() ?? $site->coolify_auto_deploy;
        $this->remember($site, $autoDeploy, CoolifyApplication::HEAD_REF);

        $this->audit($site, $actor, $ip, 'site.git_update_head', [
            'git_commit_sha' => null,
            'is_auto_deploy' => $autoDeploy,
        ]);

        return $app;
    }

    /**
     * "HEAD'de deploy" / "HEAD'e güncelle" of a site that runs built images: the
     * branch HEAD may have no image yet, so the site moves to the newest commit its
     * channel builder finished instead. Auto-deploy stays off.
     */
    private function deployLatestBuilt(Site $site, string $action, ?User $actor, ?string $ip, ?Deployment $into): CoolifyApplication
    {
        $uuid = $this->requireApp($site);
        $this->assertCanStartDeploy($site);
        $coolify = CoolifyApplicationService::forSite($site);
        [$sha, $current] = $this->builtCommit($site, $coolify, fn (BuiltImageCommits $images, CoolifyApplication $app): string => $images->latest(
            BuiltImageCommits::channelOf($site), $coolify, $app->isDockerImagePack(),
        )) ?? throw new BuiltImageException(__('site_ops.image.no_builder', ['channel' => BuiltImageCommits::channelOf($site)->value]));
        $this->preflight($site, $actor, $ip);

        try {
            $app = app(BuiltImageCommits::class)->pinApp($coolify, $uuid, $sha, $current);
            $this->recordDeployment($site, $coolify->deploy($uuid), DeploymentTrigger::Manual, $actor, $ip, $into);
        } catch (CoolifyDeployBusyException $exception) {
            throw new ComposePackException($exception->getMessage(), $exception->getCode(), $exception);
        } catch (CoolifyApiException $exception) {
            throw new ComposePackException($exception->getMessage(), $exception->status, $exception);
        }

        // The newest build is a release pin, never an operator hold.
        $this->remember($site, false, $sha, held: false);

        $this->audit($site, $actor, $ip, $action, [
            'git_commit_sha' => $sha,
            'is_auto_deploy' => false,
            'built_image' => true,
        ]);

        return $app;
    }

    /**
     * `force=true` is the operator's Tekrar deploy (no build cache); the
     * domain-bind follow-up passes `false`. A site that runs built images
     * redeploys its pinned commit when that one is built, else the newest build.
     */
    public function redeploy(Site $site, ?User $actor = null, ?string $ip = null, bool $force = true, ?Deployment $into = null): Deployment
    {
        $deployment = $this->startDeploy($site, $force, DeploymentTrigger::Manual, $actor, $ip, $into);
        $this->audit($site, $actor, $ip, 'site.redeployed', [
            'force' => $force,
        ]);

        return $deployment;
    }

    /**
     * Follow-up build after a first provision bound its hosts: Coolify only writes
     * Traefik labels at deploy time. `force=false` reuses the build cache.
     */
    public function deployAfterDomainBind(Site $site): Deployment
    {
        return $this->startDeploy($site, false, DeploymentTrigger::Manual, null, null);
    }

    /**
     * The CI rollout build: `force=false`, so Coolify reuses its build cache and
     * a fleet sweep costs the host far less than a manual Redeploy. The app
     * follows the branch HEAD, which the rollout checked is the green commit.
     * A site that runs built images is pinned to the rollout commit instead; a
     * commit the builder has not finished yet throws BuiltImageException.
     */
    public function deployForRollout(Site $site, FleetRollout $rollout): Deployment
    {
        $deployment = $this->startDeploy($site, false, DeploymentTrigger::CiRollout, null, null, ref: $rollout->sha);
        $this->audit($site, null, null, 'site.ci_rollout_deployed', [
            'rollout_id' => $rollout->id,
            'channel' => $rollout->channel->value,
            'sha' => $rollout->sha,
            'force' => false,
            'deployment_id' => $deployment->id,
        ]);

        return $deployment;
    }

    /**
     * Bind hosts, sync env and align the agent secret with Plane before Coolify
     * builds (DeployPreflight). Never throws: a deploy is not held back by it.
     */
    private function preflight(Site $site, ?User $actor, ?string $ip): void
    {
        app(DeployPreflight::class)->run($site, $actor, $ip);
    }

    /**
     * `$ref` is the commit an image site must run (a rollout); without it an image
     * site keeps its built pin or moves to the newest build. Other sites build
     * whatever their Coolify app points at.
     */
    private function startDeploy(Site $site, bool $force, DeploymentTrigger $trigger, ?User $actor, ?string $ip, ?Deployment $into = null, ?string $ref = null): Deployment
    {
        $uuid = $this->requireApp($site);
        $this->assertCanStartDeploy($site);
        $coolify = CoolifyApplicationService::forSite($site);
        $built = $this->builtCommit($site, $coolify, fn (BuiltImageCommits $images, CoolifyApplication $app): string => $ref !== null
            ? $images->resolve(BuiltImageCommits::channelOf($site), $ref, $coolify, $app->isDockerImagePack())
            : $images->currentOrLatest($site, $coolify, $app));
        $this->preflight($site, $actor, $ip);

        try {
            if ($built !== null) {
                app(BuiltImageCommits::class)->pinApp($coolify, $uuid, $built[0], $built[1]);
            }
            $result = $coolify->deploy($uuid, $force);
        } catch (CoolifyDeployBusyException $exception) {
            throw new ComposePackException($exception->getMessage(), $exception->getCode(), $exception);
        } catch (CoolifyApiException $exception) {
            throw new ComposePackException($exception->getMessage(), $exception->status, $exception);
        }

        if ($built !== null) {
            $this->remember($site, false, $built[0]);
        }

        return $this->recordDeployment($site, $result, $trigger, $actor, $ip, $into);
    }

    private function runsBuiltImages(Site $site): bool
    {
        return app(BuiltImageCommits::class)->appliesTo($site);
    }

    /**
     * The built commit an image site's deploy targets and the site's Coolify app
     * (its build pack decides the pin field), resolved before Plane touches the
     * app; null for a site whose channel still builds its branch.
     *
     * @param  callable(BuiltImageCommits, CoolifyApplication): string  $pick
     * @return array{0: string, 1: CoolifyApplication}|null
     */
    private function builtCommit(Site $site, CoolifyApplicationService $coolify, callable $pick): ?array
    {
        $images = app(BuiltImageCommits::class);
        if (! $images->appliesTo($site)) {
            return null;
        }

        try {
            $app = $coolify->getApp((string) $site->coolify_app_uuid);

            return [$pick($images, $app), $app];
        } catch (CoolifyApiException $exception) {
            throw new ComposePackException($exception->getMessage(), $exception->status, $exception);
        }
    }

    /**
     * @param  iterable<int, Site>  $sites
     * @return array{ok: int, failed: int, skipped: int, waiting: int, errors: list<string>, rate_limited: bool, deploy_busy: bool}
     */
    public function setAutoDeployMany(iterable $sites, bool $enabled, ?User $actor = null, ?string $ip = null): array
    {
        return $this->applyMany($sites, fn (Site $site) => $this->setAutoDeploy($site, $enabled, $actor, $ip));
    }

    /**
     * @param  iterable<int, Site>  $sites
     * @return array{ok: int, failed: int, skipped: int, waiting: int, errors: list<string>, rate_limited: bool, deploy_busy: bool}
     */
    public function setDeployGateMany(iterable $sites, DeployGate $gate, ?User $actor = null, ?string $ip = null): array
    {
        return $this->applyMany($sites, function (Site $site) use ($gate, $actor, $ip): void {
            $this->setDeployGate($site, $gate, $actor, $ip);
        });
    }

    /**
     * @param  iterable<int, Site>  $sites
     * @return array{ok: int, failed: int, skipped: int, waiting: int, errors: list<string>, rate_limited: bool, deploy_busy: bool}
     */
    public function redeployMany(iterable $sites, ?User $actor = null, ?string $ip = null): array
    {
        return $this->applyMany($sites, function (Site $site) use ($actor, $ip): void {
            $this->redeploy($site, $actor, $ip);
        });
    }

    /**
     * @param  iterable<int, Site>  $sites
     * @return array{ok: int, failed: int, skipped: int, waiting: int, errors: list<string>, rate_limited: bool, deploy_busy: bool}
     */
    public function followHeadMany(iterable $sites, ?User $actor = null, ?string $ip = null): array
    {
        return $this->applyMany($sites, fn (Site $site) => $this->followHead($site, $actor, $ip));
    }

    /**
     * @param  iterable<int, Site>  $sites
     * @return array{ok: int, failed: int, skipped: int, waiting: int, errors: list<string>, rate_limited: bool, deploy_busy: bool}
     */
    public function updateToHeadMany(iterable $sites, ?User $actor = null, ?string $ip = null): array
    {
        return $this->applyMany($sites, function (Site $site) use ($actor, $ip): void {
            $this->updateToHead($site, $actor, $ip);
        });
    }

    /**
     * @param  iterable<int, Site>  $sites
     * @return array{ok: int, failed: int, skipped: int, waiting: int, errors: list<string>, rate_limited: bool, deploy_busy: bool}
     */
    public function pinMany(iterable $sites, string $ref, ?User $actor = null, ?string $ip = null): array
    {
        return $this->applyMany($sites, fn (Site $site) => $this->pin($site, $ref, $actor, $ip));
    }

    /**
     * @param  iterable<int, Site>  $sites
     * @param  callable(Site): void  $action
     * @return array{ok: int, failed: int, skipped: int, waiting: int, errors: list<string>, rate_limited: bool, deploy_busy: bool}
     */
    private function applyMany(iterable $sites, callable $action): array
    {
        return app(PacedFanout::class)->run($sites, $action);
    }

    private function recordDeployment(
        Site $site,
        CoolifyDeployResult $result,
        DeploymentTrigger $trigger,
        ?User $actor,
        ?string $ip = null,
        ?Deployment $into = null,
    ): Deployment {
        $channel = $site->channel instanceof Channel ? $site->channel : Channel::from((string) $site->channel);
        $uuid = $result->firstDeploymentUuid();

        $values = [
            'channel' => $channel,
            'trigger' => $trigger,
            'coolify_deployment_uuid' => $uuid,
            'status' => $uuid === null ? DeploymentStatus::Failed : DeploymentStatus::InProgress,
            'started_at' => now(),
            'finished_at' => $uuid === null ? now() : null,
            'requested_by' => $actor?->id,
            'error_message' => $uuid === null ? 'Coolify did not return a deployment uuid.' : null,
        ];

        if ($into instanceof Deployment) {
            // The waiting row keeps its id, requester and queue history; it only
            // turns into the live build.
            $values['requested_by'] = $into->requested_by ?? $values['requested_by'];
            $into->forceFill($values)->save();
            $deployment = $into;
        } else {
            $deployment = $site->deployments()->create($values);
        }

        if ($uuid !== null) {
            PollDeploymentJob::dispatch($deployment->id, $actor?->id, $ip);
        }

        return $deployment;
    }

    private function requireApp(Site $site): string
    {
        $uuid = trim((string) $site->coolify_app_uuid);
        if ($uuid === '') {
            throw new ComposePackException(__('site_ops.pack.missing_app'));
        }

        return $uuid;
    }

    private function assertCanStartDeploy(Site $site): void
    {
        try {
            app(CoolifyDeployGate::class)->assertCanStartDeploy($site);
        } catch (CoolifyDeployBusyException $exception) {
            throw new ComposePackException($exception->getMessage(), $exception->getCode(), $exception);
        }
    }

    /**
     * Mirrors the switch and the pinned commit onto the site row, so the Sites
     * list can show them without one Coolify call per row. Written without
     * touching `updated_at`: learning a setting is not an edit to the site.
     * A response without a pinned commit (`git_commit_sha`, or a Docker Image
     * app's `docker_registry_image_tag`) keeps the last known pin.
     * `$held`: true for an operator pin, false for a release pin, null keeps the
     * hold while the commit stays the same (see mirrorOntoSite).
     */
    private function remember(Site $site, ?bool $autoDeploy, ?string $sha, ?bool $held = null): void
    {
        self::mirrorOntoSite($site, $autoDeploy, $sha, $held);
    }

    /**
     * Same mirror from a GET application someone else already fetched (the
     * Coolify site / inventory sync), so the list stays fresh without an extra call.
     */
    public static function mirrorFromApplication(Site $site, CoolifyApplication $app): void
    {
        self::mirrorOntoSite($site, $app->autoDeployState(), $app->pinnedCommit());
    }

    /**
     * Mirror a built-image pin written outside this class (provision, channel switch,
     * activate, the host's release.sh). A new commit is a release pin; the same
     * commit keeps an operator hold.
     */
    public static function rememberPin(Site $site, string $sha): void
    {
        self::mirrorOntoSite($site, false, $sha);
    }

    /**
     * `coolify_pin_held`: an explicit `$held` wins; otherwise a pin keeps its hold
     * only while it stays on the same commit. Plane's Commite geç is the one path
     * that sets it; a pin that moved elsewhere (release.sh, Coolify UI, a release)
     * is not an operator hold any more. No pin, no hold.
     */
    private static function mirrorOntoSite(Site $site, ?bool $autoDeploy, ?string $sha, ?bool $held = null): void
    {
        if ($autoDeploy === null || ! $site->exists) {
            return;
        }

        $values = [
            'coolify_auto_deploy' => $autoDeploy,
            'coolify_deploy_settings_at' => now(),
        ];
        if ($sha !== null) {
            $sha = trim($sha);
            $pin = $sha === '' || CoolifyApplication::isHeadRef($sha)
                ? null
                : mb_substr($sha, 0, 64);
            $values['coolify_pinned_sha'] = $pin;
            if ($pin !== null && $held === null) {
                // Read the stored pin, not the model: a sync loop may hold a stale copy.
                $stored = Site::query()->toBase()->where('id', $site->getKey())->first(['coolify_pinned_sha', 'coolify_pin_held']);
                $held = $stored !== null
                    && (bool) $stored->coolify_pin_held
                    && self::sameCommit((string) $stored->coolify_pinned_sha, $pin);
            }
            $values['coolify_pin_held'] = $pin !== null && (bool) $held;
        }

        Site::query()->toBase()->where('id', $site->getKey())->update($values);
        $site->forceFill($values)->syncOriginalAttributes(array_keys($values));
    }

    /**
     * Full or abbreviated shas of the same commit.
     */
    private static function sameCommit(string $a, string $b): bool
    {
        $a = strtolower(trim($a));
        $b = strtolower(trim($b));

        return $a !== '' && $b !== '' && (str_starts_with($a, $b) || str_starts_with($b, $a));
    }

    /**
     * @param  array<string, mixed>  $after
     */
    private function audit(Site $site, ?User $actor, ?string $ip, string $action, array $after): void
    {
        $site->auditLogs()->create([
            'actor_user_id' => $actor?->id,
            'action' => $action,
            'before' => null,
            'after' => $after,
            'ip' => $ip,
        ]);
    }
}
