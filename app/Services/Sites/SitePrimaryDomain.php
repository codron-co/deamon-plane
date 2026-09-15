<?php

namespace App\Services\Sites;

use App\Models\Site;
use App\Models\User;
use App\Services\Cloudflare\CloudflareHostname;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Promote one of the site's extra hosts to primary. The old primary stays as an
 * alias so nothing that still points at it breaks; the operator removes it later.
 */
class SitePrimaryDomain
{
    public function __construct(
        private readonly SiteDomainSync $sync,
        private readonly SiteLanding $landing,
    ) {}

    /**
     * @return array{previous: string, outcome: string, error: ?string}
     *
     * @throws ValidationException when the host is not an extra, non-www host of this site
     */
    public function promote(Site $site, string $host, ?User $actor, ?string $ip): array
    {
        $raw = CloudflareHostname::host($host);
        $new = CloudflareHostname::normalize($raw);
        $previous = CloudflareHostname::normalize((string) $site->primary_domain);
        $aliases = $site->aliasHosts();

        if ($new === '' || str_starts_with($raw, 'www.') || $new === $previous || ! in_array($new, $aliases, true)) {
            throw ValidationException::withMessages([
                'domain' => __('sites.form.domain_promote_invalid'),
            ]);
        }

        $hostsBefore = $site->operatorHosts();
        $keep = array_values(array_filter($aliases, static fn (string $alias): bool => $alias !== $new));
        $keep[] = $previous;

        DB::transaction(function () use ($site, $new, $keep, $previous, $hostsBefore, $actor, $ip): void {
            $this->sync->sync($site, $new, $keep);

            $site->auditLogs()->create([
                'actor_user_id' => $actor?->id,
                'action' => 'site.domain_changed',
                'before' => ['primary_domain' => $previous, 'hosts' => $hostsBefore],
                'after' => ['primary_domain' => $new, 'hosts' => $site->operatorHosts()],
                'ip' => $ip,
            ]);
        });

        $site->refresh();
        $error = $this->landing->releaseHostDns($site, $this->sync->lastRemoved);

        $outcome = SiteLanding::BIND_NO_APP;
        if (filled($site->coolify_app_uuid)) {
            try {
                $this->landing->applyAliasDns($site);
                $outcome = $this->landing->bindAndRedeploy($site, $actor, $ip);
            } catch (SiteProvisionException $exception) {
                // The Plane change landed; Cloudflare or Coolify did not. Keep the agent where it answers.
                return ['previous' => $previous, 'outcome' => $outcome, 'error' => $exception->getMessage()];
            }
        }

        $this->followAgentBaseUrl($site->refresh(), $previous, $outcome);

        return ['previous' => $previous, 'outcome' => $outcome, 'error' => $error];
    }

    /**
     * Point the agent at the new primary once it is actually served. A pending zone
     * (waiting_dns) keeps Coolify on the old hosts, so the agent stays there too;
     * DNS confirm moves it. A custom agent URL on another host is left alone.
     */
    public function followAgentBaseUrl(Site $site, string $previousPrimary, string $outcome): void
    {
        if ($outcome === SiteLanding::BIND_WAITING_DNS) {
            return;
        }

        $agentHost = CloudflareHostname::normalize((string) (parse_url((string) $site->agent_base_url, PHP_URL_HOST) ?? ''));
        $primary = CloudflareHostname::normalize((string) $site->primary_domain);

        if ($agentHost === '' || $primary === '' || $agentHost === $primary || $agentHost !== CloudflareHostname::normalize($previousPrimary)) {
            return;
        }

        $site->agent_base_url = 'https://'.$primary;
        $site->save();
    }
}
