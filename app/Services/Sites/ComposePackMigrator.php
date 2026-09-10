<?php

namespace App\Services\Sites;

use App\Models\Site;
use App\Models\User;
use App\Services\Coolify\CoolifyApiException;
use App\Services\Coolify\CoolifyApplicationService;
use App\Services\Coolify\CoolifyDomainParser;
use App\Services\Coolify\Dto\CoolifyEnvironmentVariable;
use App\Services\Coolify\Dto\CreateComposeAppRequest;

class ComposePackMigrator
{
    /**
     * Keys restored onto compose service `app` only. Database and Redis secrets are never copied.
     *
     * @var list<string>
     */
    private const APP_KEYS = ['APP_KEY', 'APP_URL'];

    public function migrate(Site $site, ?User $actor = null, ?string $ip = null): void
    {
        $uuid = trim((string) $site->coolify_app_uuid);
        if ($uuid === '') {
            throw new ComposePackException(__('site_ops.pack.missing_app'));
        }

        $coolify = CoolifyApplicationService::forSite($site);

        try {
            $app = $coolify->getApp($uuid);
        } catch (CoolifyApiException $exception) {
            throw new ComposePackException($exception->getMessage(), $exception->status, $exception);
        }

        if ($app->isComposePack()) {
            $this->clearMarker($site, $actor, $ip, alreadyCompose: true);

            return;
        }

        if (! $app->isDockerfilePack() && ! $site->hasDockerfileBuildPackWarning()) {
            throw new ComposePackException(__('site_ops.pack.not_dockerfile'));
        }

        $snapshot = $this->snapshotAppEnvs($coolify, $site, $uuid);

        try {
            $coolify->patchApplication($uuid, [
                'build_pack' => 'dockercompose',
                'docker_compose_location' => CreateComposeAppRequest::DEFAULT_COMPOSE_LOCATION,
            ]);
        } catch (CoolifyApiException $exception) {
            if ($this->requiresRecreate($exception)) {
                throw new ComposePackException(__('site_ops.pack.recreate_aborted'), $exception->status, $exception);
            }

            throw new ComposePackException($exception->getMessage(), $exception->status, $exception);
        }

        try {
            $this->restoreAppEnvs($coolify, $uuid, $snapshot);
        } catch (CoolifyApiException $exception) {
            throw new ComposePackException($exception->getMessage(), $exception->status, $exception);
        }

        $this->clearMarker($site, $actor, $ip, alreadyCompose: false);
    }

    /**
     * @param  iterable<int, Site>  $sites
     * @return array{ok: int, failed: int, errors: list<string>}
     */
    public function migrateMany(iterable $sites, ?User $actor = null, ?string $ip = null): array
    {
        $ok = 0;
        $failed = 0;
        $errors = [];

        foreach ($sites as $site) {
            try {
                $this->migrate($site, $actor, $ip);
                $ok++;
            } catch (ComposePackException $exception) {
                $failed++;
                $errors[] = $site->name.': '.$exception->getMessage();
            }
        }

        return ['ok' => $ok, 'failed' => $failed, 'errors' => $errors];
    }

    /**
     * @return array<string, string>
     */
    private function snapshotAppEnvs(CoolifyApplicationService $coolify, Site $site, string $uuid): array
    {
        $snapshot = [];

        foreach ($coolify->listEnvs($uuid) as $env) {
            if (! $env instanceof CoolifyEnvironmentVariable || ! $this->shouldRestore($env->key)) {
                continue;
            }

            $value = $env->value();
            if (is_string($value) && $value !== '') {
                $snapshot[$env->key] = $value;
            }
        }

        if (! isset($snapshot['APP_KEY']) && filled($site->app_key_encrypted)) {
            $snapshot['APP_KEY'] = (string) $site->app_key_encrypted;
        }

        return $snapshot;
    }

    /**
     * @param  array<string, string>  $snapshot
     */
    private function restoreAppEnvs(CoolifyApplicationService $coolify, string $uuid, array $snapshot): void
    {
        foreach ($snapshot as $key => $value) {
            $coolify->upsertEnvOnService($uuid, $key, $value, CoolifyDomainParser::COMPOSE_SERVICE);
        }
    }

    private function shouldRestore(string $key): bool
    {
        if (in_array($key, self::APP_KEYS, true)) {
            return true;
        }

        return str_starts_with($key, 'DEAMON_');
    }

    private function requiresRecreate(CoolifyApiException $exception): bool
    {
        $haystack = strtolower($exception->getMessage());

        foreach (['recreate', 'delete_volumes', 'must be deleted', 'cannot change build pack', 'rebuild required'] as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function clearMarker(Site $site, ?User $actor, ?string $ip, bool $alreadyCompose): void
    {
        $hadMarker = $site->hasDockerfileBuildPackWarning();
        if ($hadMarker) {
            $site->clearDockerfileBuildPackWarning();
            $site->save();
        }

        $site->auditLogs()->create([
            'actor_user_id' => $actor?->id,
            'action' => 'site.compose_pack_migrated',
            'before' => ['dockerfile' => true],
            'after' => [
                'build_pack' => 'dockercompose',
                'already_compose' => $alreadyCompose,
                'marker_cleared' => $hadMarker,
            ],
            'ip' => $ip,
        ]);
    }
}
