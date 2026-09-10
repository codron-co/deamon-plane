<?php

namespace App\Services\Sites;

use App\Enums\CoolifyEnvPack;
use App\Models\Site;
use App\Models\User;
use App\Services\Agent\SiteHealthChecker;
use App\Services\Coolify\CoolifyApiException;
use App\Services\Coolify\CoolifyAppEnvSync;
use App\Services\Coolify\CoolifyApplicationService;
use InvalidArgumentException;

class SiteAppHealthFixer
{
    public function __construct(
        private readonly SiteAppHealthInspector $inspector,
        private readonly CoolifyAppEnvSync $envSync,
        private readonly ComposePackMigrator $packMigrator,
        private readonly SiteAgentSecretInjector $secrets,
        private readonly CoolifyDeploySettings $deploys,
        private readonly SiteHealthChecker $health,
    ) {}

    public function fix(Site $site, string $fix, ?User $actor = null, ?string $ip = null): SiteAppHealthReport
    {
        try {
            match ($fix) {
                'sync_env' => $this->syncEnv($site),
                'migrate_compose' => $this->packMigrator->migrate($site, $actor, $ip),
                'inject_secret' => $this->secrets->inject($site, $actor, $ip),
                'redeploy' => $this->deploys->redeploy($site, $actor, $ip),
                'check_health' => $this->health->check($site),
                default => throw new InvalidArgumentException(__('sites.app_health.unknown_fix')),
            };
        } catch (ComposePackException|SiteProvisionException|CoolifyApiException $exception) {
            throw new SiteAppHealthException($exception->getMessage(), (int) $exception->getCode(), $exception);
        }

        try {
            return $this->inspector->inspect($site, live: filled($site->coolify_app_uuid));
        } catch (CoolifyApiException) {
            return $this->inspector->inspect($site, live: false);
        }
    }

    private function syncEnv(Site $site): void
    {
        if (blank($site->coolify_app_uuid)) {
            throw new SiteAppHealthException(__('site_ops.pack.missing_app'));
        }

        $this->envSync->sync($site, CoolifyApplicationService::forSite($site), CoolifyEnvPack::DockerCompose);
    }
}
