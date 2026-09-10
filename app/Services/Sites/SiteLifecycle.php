<?php

namespace App\Services\Sites;

use App\Enums\SiteStatus;
use App\Models\Site;
use App\Models\User;
use App\Services\Coolify\CoolifyApiException;
use App\Services\Coolify\CoolifyApplicationService;
use Illuminate\Support\Facades\DB;

class SiteLifecycle
{
    public function activate(Site $site, ?User $actor, ?string $ip): void
    {
        if (! $site->canBeActivated()) {
            throw new SiteLifecycleException(__('sites.flash.activate_blocked'));
        }

        $this->startCoolify($site);
        $this->transition($site, SiteStatus::Active, 'site.activated', $actor, $ip);
    }

    public function deactivate(Site $site, ?User $actor, ?string $ip): void
    {
        if (! $site->canBeDeactivated()) {
            throw new SiteLifecycleException(__('sites.flash.deactivate_blocked'));
        }

        $this->stopCoolify($site);
        $this->transition($site, SiteStatus::Stopped, 'site.deactivated', $actor, $ip);
    }

    public function purge(Site $site, ?User $actor, ?string $ip): void
    {
        $this->deleteCoolifyIfPresent($site);

        DB::transaction(function () use ($site, $actor, $ip): void {
            $site->auditLogs()->create([
                'actor_user_id' => $actor?->id,
                'action' => 'site.purged',
                'before' => $this->snapshot($site),
                'ip' => $ip,
            ]);

            $site->forceDelete();
        });
    }

    /**
     * @param  iterable<int, mixed>  $sites
     * @return array{ok: int, failed: int, errors: list<string>}
     */
    public function purgeMany(iterable $sites, ?User $actor, ?string $ip): array
    {
        $ok = 0;
        $failed = 0;
        $errors = [];

        foreach ($sites as $site) {
            if (! $site instanceof Site) {
                continue;
            }

            try {
                $this->purge($site, $actor, $ip);
                $ok++;
            } catch (CoolifyApiException|SiteLifecycleException $exception) {
                $failed++;
                $errors[] = $site->name.': '.$exception->getMessage();
            }
        }

        return ['ok' => $ok, 'failed' => $failed, 'errors' => $errors];
    }

    private function startCoolify(Site $site): void
    {
        $uuid = $this->appUuid($site);
        if ($uuid === null) {
            throw new SiteLifecycleException(__('sites.flash.activate_missing_app'));
        }

        try {
            CoolifyApplicationService::forSite($site)->startApplication($uuid);
        } catch (CoolifyApiException $exception) {
            if ($exception->isNotFound()) {
                throw new SiteLifecycleException(__('sites.flash.activate_missing_app'));
            }

            throw $exception;
        }
    }

    private function stopCoolify(Site $site): void
    {
        $uuid = $this->appUuid($site);
        if ($uuid === null) {
            throw new SiteLifecycleException(__('sites.flash.activate_missing_app'));
        }

        try {
            CoolifyApplicationService::forSite($site)->stopApplication($uuid);
        } catch (CoolifyApiException $exception) {
            if ($exception->isNotFound()) {
                return;
            }

            throw $exception;
        }
    }

    private function deleteCoolifyIfPresent(Site $site): void
    {
        $uuid = $this->appUuid($site);
        if ($uuid === null) {
            return;
        }

        try {
            CoolifyApplicationService::forSite($site)->deleteApplication($uuid, true);
        } catch (CoolifyApiException $exception) {
            if ($exception->isNotFound()) {
                return;
            }

            throw $exception;
        }
    }

    private function transition(Site $site, SiteStatus $next, string $action, ?User $actor, ?string $ip): void
    {
        DB::transaction(function () use ($site, $next, $action, $actor, $ip): void {
            $before = $this->snapshot($site);
            $site->transitionTo($next);
            $site->save();

            $site->auditLogs()->create([
                'actor_user_id' => $actor?->id,
                'action' => $action,
                'before' => $before,
                'after' => $this->snapshot($site),
                'ip' => $ip,
            ]);
        });
    }

    private function appUuid(Site $site): ?string
    {
        $uuid = trim((string) $site->coolify_app_uuid);

        return $uuid === '' ? null : $uuid;
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Site $site): array
    {
        return [
            'slug' => $site->slug,
            'name' => $site->name,
            'primary_domain' => $site->primary_domain,
            'status' => $site->status instanceof SiteStatus ? $site->status->value : $site->status,
            'coolify_app_uuid' => $site->coolify_app_uuid,
        ];
    }
}
