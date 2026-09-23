<?php

namespace App\Services\Sites;

use App\Enums\Channel;
use App\Enums\DeploymentStatus;
use App\Enums\DeploymentTrigger;
use App\Jobs\PollDeploymentJob;
use App\Models\Deployment;
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

        $this->remember($site, $app->autoDeployState(), $app->gitCommitSha());

        return [
            'build_pack' => $app->buildPack,
            'is_auto_deploy' => $app->autoDeployState(),
            'git_commit_sha' => $app->gitCommitSha(),
            'error' => null,
        ];
    }

    public function setAutoDeploy(Site $site, bool $enabled, ?User $actor = null, ?string $ip = null): CoolifyApplication
    {
        $uuid = $this->requireApp($site);
        $coolify = CoolifyApplicationService::forSite($site);

        try {
            $app = $coolify->patchApplication($uuid, ['is_auto_deploy_enabled' => $enabled]);
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

        $this->remember($site, $enabled, $app->gitCommitSha());

        $this->audit($site, $actor, $ip, 'site.auto_deploy_updated', [
            'is_auto_deploy' => $enabled,
        ]);

        return $app;
    }

    public function pin(Site $site, string $ref, ?User $actor = null, ?string $ip = null): CoolifyApplication
    {
        $uuid = $this->requireApp($site);
        $this->assertCanStartDeploy($site);
        $coolify = CoolifyApplicationService::forSite($site);

        try {
            $app = $coolify->patchApplication($uuid, [
                'git_commit_sha' => $ref,
                'is_auto_deploy_enabled' => false,
            ]);
            $this->recordDeployment($site, $coolify->deploy($uuid), DeploymentTrigger::Manual, $actor, $ip);
        } catch (CoolifyDeployBusyException $exception) {
            throw new ComposePackException($exception->getMessage(), $exception->getCode(), $exception);
        } catch (CoolifyApiException $exception) {
            throw new ComposePackException($exception->getMessage(), $exception->status, $exception);
        }

        $this->remember($site, false, $ref);

        $this->audit($site, $actor, $ip, 'site.git_pinned', [
            'git_commit_sha' => $ref,
            'is_auto_deploy' => false,
        ]);

        return $app;
    }

    public function followHead(Site $site, ?User $actor = null, ?string $ip = null): CoolifyApplication
    {
        $uuid = $this->requireApp($site);
        $this->assertCanStartDeploy($site);
        $coolify = CoolifyApplicationService::forSite($site);

        try {
            $app = $coolify->patchApplication($uuid, [
                'git_commit_sha' => CoolifyApplication::HEAD_REF,
                'is_auto_deploy_enabled' => true,
            ]);
            $this->recordDeployment($site, $coolify->deploy($uuid), DeploymentTrigger::Manual, $actor, $ip);
        } catch (CoolifyDeployBusyException $exception) {
            throw new ComposePackException($exception->getMessage(), $exception->getCode(), $exception);
        } catch (CoolifyApiException $exception) {
            throw new ComposePackException($exception->getMessage(), $exception->status, $exception);
        }

        $this->remember($site, true, CoolifyApplication::HEAD_REF);

        $this->audit($site, $actor, $ip, 'site.git_follow_head', [
            'git_commit_sha' => null,
            'is_auto_deploy' => true,
        ]);

        return $app;
    }

    public function redeploy(Site $site, ?User $actor = null, ?string $ip = null): void
    {
        $uuid = $this->requireApp($site);
        $this->assertCanStartDeploy($site);

        try {
            $result = CoolifyApplicationService::forSite($site)->deploy($uuid, true);
        } catch (CoolifyDeployBusyException $exception) {
            throw new ComposePackException($exception->getMessage(), $exception->getCode(), $exception);
        } catch (CoolifyApiException $exception) {
            throw new ComposePackException($exception->getMessage(), $exception->status, $exception);
        }

        $this->recordDeployment($site, $result, DeploymentTrigger::Manual, $actor, $ip);
        $this->audit($site, $actor, $ip, 'site.redeployed', [
            'force' => true,
        ]);
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
    public function redeployMany(iterable $sites, ?User $actor = null, ?string $ip = null): array
    {
        return $this->applyMany($sites, fn (Site $site) => $this->redeploy($site, $actor, $ip));
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
    ): Deployment {
        $channel = $site->channel instanceof Channel ? $site->channel : Channel::from((string) $site->channel);
        $uuid = $result->firstDeploymentUuid();

        $deployment = $site->deployments()->create([
            'channel' => $channel,
            'trigger' => $trigger,
            'coolify_deployment_uuid' => $uuid,
            'status' => $uuid === null ? DeploymentStatus::Failed : DeploymentStatus::InProgress,
            'started_at' => now(),
            'requested_by' => $actor?->id,
            'error_message' => $uuid === null ? 'Coolify did not return a deployment uuid.' : null,
        ]);

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
     * A response without `git_commit_sha` keeps the last known pin.
     */
    private function remember(Site $site, ?bool $autoDeploy, ?string $sha): void
    {
        self::mirrorOntoSite($site, $autoDeploy, $sha);
    }

    /**
     * Same mirror from a GET application someone else already fetched (the
     * Coolify site / inventory sync), so the list stays fresh without an extra call.
     */
    public static function mirrorFromApplication(Site $site, CoolifyApplication $app): void
    {
        self::mirrorOntoSite($site, $app->autoDeployState(), $app->gitCommitSha());
    }

    private static function mirrorOntoSite(Site $site, ?bool $autoDeploy, ?string $sha): void
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
            $values['coolify_pinned_sha'] = $sha === '' || CoolifyApplication::isHeadRef($sha)
                ? null
                : mb_substr($sha, 0, 64);
        }

        Site::query()->toBase()->where('id', $site->getKey())->update($values);
        $site->forceFill($values)->syncOriginalAttributes(array_keys($values));
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
