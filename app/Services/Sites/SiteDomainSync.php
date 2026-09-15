<?php

namespace App\Services\Sites;

use App\Models\Site;
use App\Models\SiteDomain;
use App\Services\Cloudflare\CloudflareHostname;
use Illuminate\Validation\ValidationException;

class SiteDomainSync
{
    /**
     * Hosts the last sync() dropped, so the caller can release their DNS.
     *
     * @var list<string>
     */
    public array $lastRemoved = [];

    /**
     * @param  list<string>  $aliases
     */
    public function sync(Site $site, string $primary, array $aliases = []): void
    {
        $primaryHost = CloudflareHostname::normalize($primary);
        if ($primaryHost === '') {
            throw ValidationException::withMessages([
                'domain' => __('sites.form.domain_invalid'),
            ]);
        }

        $wanted = [];
        $this->push($wanted, $primaryHost, true, false);

        $www = CloudflareHostname::wwwHost($primaryHost);
        if ($www !== '') {
            $this->push($wanted, $www, false, true);
        }

        foreach ($aliases as $alias) {
            $host = CloudflareHostname::normalize((string) $alias);
            if ($host === '' || $host === $primaryHost) {
                continue;
            }

            // Any registrable apex is welcome; a foreign apex gets its own Cloudflare
            // zone when DNS is applied (CloudflareZoneService::ensureZoneAndDns).
            $this->push($wanted, $host, false, false);
            $aliasWww = CloudflareHostname::wwwHost($host);
            if ($aliasWww !== '') {
                $this->push($wanted, $aliasWww, false, true);
            }
        }

        $this->assertAvailable($site, $wanted, $primaryHost, $aliases);

        $keep = array_keys($wanted);
        $this->lastRemoved = $site->domains()
            ->where('is_temporary', false)
            ->whereNotIn('domain', $keep)
            ->pluck('domain')
            ->map(static fn (mixed $domain): string => (string) $domain)
            ->all();
        $site->domains()
            ->where('is_temporary', false)
            ->whereNotIn('domain', $keep)
            ->delete();

        foreach ($wanted as $domain => $meta) {
            $site->domains()->updateOrCreate(
                ['domain' => $domain],
                [
                    'is_primary' => $meta['primary'],
                    'is_www' => $meta['www'],
                    'is_temporary' => false,
                ],
            );
        }

        $site->primary_domain = $primaryHost;
        $site->save();
        $site->unsetRelation('domains');
        $site->unsetRelation('primaryDomainRecord');
    }

    /**
     * Remove an extra host and its www sibling. The primary host (and its www) stays:
     * change the primary first.
     *
     * @return list<string> hosts whose rows were deleted
     */
    public function removeAlias(Site $site, string $host): array
    {
        $host = CloudflareHostname::normalize($host);
        $primary = CloudflareHostname::normalize((string) $site->primary_domain);

        if ($host === '' || $host === $primary || $host === CloudflareHostname::wwwHost($primary)) {
            throw ValidationException::withMessages([
                'domain' => __('sites.form.domain_primary_locked'),
            ]);
        }

        // Removing "www.shop.x" alone would leave "shop.x" without the sibling every host gets.
        $base = str_starts_with($host, 'www.') ? substr($host, 4) : $host;
        $targets = array_values(array_unique([$base, CloudflareHostname::wwwHost($base)]));

        $removed = $site->domains()
            ->where('is_temporary', false)
            ->whereIn('domain', $targets)
            ->pluck('domain')
            ->map(static fn (mixed $domain): string => (string) $domain)
            ->all();

        if ($removed === []) {
            return [];
        }

        $site->domains()->where('is_temporary', false)->whereIn('domain', $removed)->delete();
        $site->unsetRelation('domains');

        return $removed;
    }

    public function addAlias(Site $site, string $alias): void
    {
        $extras = $site->aliasHosts();
        $extras[] = $alias;
        $this->sync($site, (string) $site->primary_domain, $extras);
    }

    /**
     * @param  array<string, array{primary: bool, www: bool}>  $wanted
     * @param  list<string>  $aliases
     */
    private function assertAvailable(Site $site, array $wanted, string $primaryHost, array $aliases): void
    {
        foreach (array_keys($wanted) as $host) {
            $taken = SiteDomain::query()
                ->where('domain', $host)
                ->where('site_id', '!=', $site->id)
                ->exists()
                || Site::query()
                    ->where('primary_domain', $host)
                    ->where('id', '!=', $site->id)
                    ->exists();

            if (! $taken) {
                continue;
            }

            $field = 'domain';
            if ($host !== $primaryHost && $host !== CloudflareHostname::wwwHost($primaryHost)) {
                $field = 'aliases.0';
                foreach ($aliases as $index => $alias) {
                    $normalized = CloudflareHostname::normalize((string) $alias);
                    if ($normalized === $host || CloudflareHostname::wwwHost($normalized) === $host) {
                        $field = 'aliases.'.$index;
                        break;
                    }
                }
            }

            throw ValidationException::withMessages([
                $field => __('sites.form.domain_taken', ['domain' => $host]),
            ]);
        }
    }

    /**
     * @param  array<string, array{primary: bool, www: bool}>  $wanted
     */
    private function push(array &$wanted, string $host, bool $primary, bool $www): void
    {
        if ($host === '' || isset($wanted[$host])) {
            return;
        }

        $wanted[$host] = ['primary' => $primary, 'www' => $www];
    }
}
