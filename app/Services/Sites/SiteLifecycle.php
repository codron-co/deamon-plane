<?php

namespace App\Services\Sites;

use App\Enums\SiteStatus;
use App\Models\Site;
use App\Models\User;
use App\Services\Cloudflare\CloudflareHostname;
use App\Services\Coolify\CoolifyApiException;
use App\Services\Coolify\CoolifyApplicationService;
use Illuminate\Support\Facades\DB;
use Throwable;

class SiteLifecycle
{
    public function __construct(
        private readonly SiteLanding $landing,
    ) {}

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
        // Read hosts before forceDelete cascades the site_domains rows away.
        $hosts = $this->dnsHosts($site);

        $this->deleteCoolifyIfPresent($site);

        // Coolify is gone, so the site is going regardless: a Cloudflare failure is
        // recorded on the audit row instead of leaving a half-purged site behind.
        $dnsError = $this->landing->releaseHostDns($site, $hosts);

        DB::transaction(function () use ($site, $actor, $ip, $hosts, $dnsError): void {
            $site->auditLogs()->create([
                'actor_user_id' => $actor?->id,
                'action' => 'site.purged',
                'before' => $this->snapshot($site),
                'after' => [
                    'released_hosts' => $hosts,
                    'dns_error' => $dnsError,
                ],
                'ip' => $ip,
            ]);

            $site->forceDelete();
        });
    }

    /**
     * @param  iterable<int, mixed>  $sites
     * @param  (callable(Site, int, int): void)|null  $onProgress
     * @return array{ok: int, failed: int, errors: list<string>}
     */
    public function purgeMany(iterable $sites, ?User $actor, ?string $ip, ?callable $onProgress = null): array
    {
        $list = array_values(array_filter(
            is_array($sites) ? $sites : iterator_to_array($sites, false),
            static fn (mixed $site): bool => $site instanceof Site,
        ));
        $total = count($list);
        $ok = 0;
        $failed = 0;
        $errors = [];

        foreach ($list as $index => $site) {
            try {
                $this->purge($site, $actor, $ip);
                $ok++;
            } catch (Throwable $exception) {
                // One site must not stop the sweep: an unexpected error used to abort
                // the loop with a 500 and leave the rest of the selection untouched.
                report($exception);
                $failed++;
                $errors[] = $site->name.': '.$this->failureText($exception);
            }

            if ($onProgress !== null) {
                $onProgress($site, $index + 1, $total);
            }
        }

        return ['ok' => $ok, 'failed' => $failed, 'errors' => $errors];
    }

    private function failureText(Throwable $exception): string
    {
        return $exception instanceof CoolifyApiException || $exception instanceof SiteLifecycleException
            ? $exception->getMessage()
            : __('sites.flash.purge_unexpected');
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

    /**
     * Operator hosts plus the temporary preview host, each of which got an origin A
     * record from Plane. Zones are never deleted: they belong to the customer.
     *
     * @return list<string>
     */
    private function dnsHosts(Site $site): array
    {
        $hosts = $site->operatorHosts();
        $temporary = CloudflareHostname::host((string) $site->temporary_domain);
        if ($temporary !== '') {
            $hosts[] = $temporary;
        }

        return array_values(array_unique(array_filter($hosts)));
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
