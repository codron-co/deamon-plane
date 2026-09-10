<?php

namespace App\Services\Sites;

use App\Models\Site;
use App\Models\User;
use App\Services\Coolify\CoolifyApiException;
use App\Services\Coolify\CoolifyApplicationService;
use App\Services\Coolify\Dto\CoolifyApplication;

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
        } catch (CoolifyApiException $exception) {
            throw new ComposePackException($exception->getMessage(), $exception->status, $exception);
        }

        $this->audit($site, $actor, $ip, 'site.auto_deploy_updated', [
            'is_auto_deploy' => $enabled,
        ]);

        return $app;
    }

    public function pin(Site $site, string $ref, ?User $actor = null, ?string $ip = null): CoolifyApplication
    {
        $uuid = $this->requireApp($site);
        $coolify = CoolifyApplicationService::forSite($site);

        try {
            $app = $coolify->patchApplication($uuid, [
                'git_commit_sha' => $ref,
                'is_auto_deploy_enabled' => false,
            ]);
            $coolify->deploy($uuid);
        } catch (CoolifyApiException $exception) {
            throw new ComposePackException($exception->getMessage(), $exception->status, $exception);
        }

        $this->audit($site, $actor, $ip, 'site.git_pinned', [
            'git_commit_sha' => $ref,
            'is_auto_deploy' => false,
        ]);

        return $app;
    }

    public function followHead(Site $site, ?User $actor = null, ?string $ip = null): CoolifyApplication
    {
        $uuid = $this->requireApp($site);
        $coolify = CoolifyApplicationService::forSite($site);

        try {
            $app = $coolify->patchApplication($uuid, [
                'git_commit_sha' => CoolifyApplication::HEAD_REF,
                'is_auto_deploy_enabled' => true,
            ]);
            $coolify->deploy($uuid);
        } catch (CoolifyApiException $exception) {
            throw new ComposePackException($exception->getMessage(), $exception->status, $exception);
        }

        $this->audit($site, $actor, $ip, 'site.git_follow_head', [
            'git_commit_sha' => null,
            'is_auto_deploy' => true,
        ]);

        return $app;
    }

    /**
     * All on → off. All off → on. Mixed or unknown → off.
     *
     * @param  iterable<int, Site>  $sites
     */
    public function toggleEnabledFor(iterable $sites): bool
    {
        $known = [];

        foreach ($sites as $site) {
            if (! $site instanceof Site || blank($site->coolify_app_uuid)) {
                continue;
            }

            $state = $this->snapshot($site)['is_auto_deploy'];
            if (is_bool($state)) {
                $known[] = $state;
            }
        }

        if ($known === []) {
            return false;
        }

        if (! in_array(false, $known, true)) {
            return false;
        }

        if (! in_array(true, $known, true)) {
            return true;
        }

        return false;
    }

    /**
     * @param  iterable<int, Site>  $sites
     * @return array{ok: int, failed: int, errors: list<string>}
     */
    public function setAutoDeployMany(iterable $sites, bool $enabled, ?User $actor = null, ?string $ip = null): array
    {
        $ok = 0;
        $failed = 0;
        $errors = [];

        foreach ($sites as $site) {
            try {
                $this->setAutoDeploy($site, $enabled, $actor, $ip);
                $ok++;
            } catch (ComposePackException $exception) {
                $failed++;
                $errors[] = $site->name.': '.$exception->getMessage();
            }
        }

        return ['ok' => $ok, 'failed' => $failed, 'errors' => $errors];
    }

    private function requireApp(Site $site): string
    {
        $uuid = trim((string) $site->coolify_app_uuid);
        if ($uuid === '') {
            throw new ComposePackException(__('site_ops.pack.missing_app'));
        }

        return $uuid;
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
