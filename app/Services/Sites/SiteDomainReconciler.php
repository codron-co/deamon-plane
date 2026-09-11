<?php

namespace App\Services\Sites;

use App\Models\Site;
use App\Models\SiteDomain;
use App\Services\Cloudflare\CloudflareHostname;
use App\Services\Coolify\CoolifyDomainParser;
use App\Services\Coolify\Dto\CoolifyApplication;

class SiteDomainReconciler
{
    /**
     * Import Coolify hosts into Plane and optionally rebind missing Plane hosts.
     *
     * @return array{imported: int, conflicts: int, rebound: bool, missing: list<string>}
     */
    public function reconcile(Site $site, CoolifyApplication $app, bool $autoRebind = true): array
    {
        $coolifyHosts = $this->customerHosts($app);
        $imported = 0;
        $conflicts = 0;

        foreach ($coolifyHosts as $host) {
            $existing = SiteDomain::query()->where('domain', $host)->first();
            if ($existing instanceof SiteDomain) {
                if ((string) $existing->site_id !== (string) $site->id) {
                    $conflicts++;

                    continue;
                }

                $existing->verified_at = now();
                $existing->save();

                continue;
            }

            $site->domains()->create([
                'domain' => $host,
                'is_primary' => blank($site->primary_domain),
                'is_www' => str_starts_with($host, 'www.'),
                'is_temporary' => false,
                'verified_at' => now(),
            ]);

            if (blank($site->primary_domain)) {
                $site->primary_domain = $host;
                $site->save();
            }

            $imported++;
        }

        $missing = $this->missingOnCoolify($site, $coolifyHosts);
        $rebound = false;

        if ($autoRebind && $missing !== [] && filled($site->coolify_app_uuid)) {
            app(SiteLanding::class)->syncCoolifyDomains($site);
            $rebound = true;
            foreach ($site->domains()->where('is_temporary', false)->get() as $row) {
                $row->verified_at = now();
                $row->save();
            }
            $missing = [];
        }

        return [
            'imported' => $imported,
            'conflicts' => $conflicts,
            'rebound' => $rebound,
            'missing' => $missing,
        ];
    }

    /**
     * @param  list<string>  $coolifyHosts
     * @return list<string>
     */
    public function missingOnCoolify(Site $site, array $coolifyHosts): array
    {
        $desired = $this->desiredHosts($site);
        if ($desired === []) {
            return [];
        }

        $have = array_fill_keys($coolifyHosts, true);
        $missing = [];
        foreach ($desired as $host) {
            if (! isset($have[$host])) {
                $missing[] = $host;
            }
        }

        return $missing;
    }

    /**
     * @return list<string>
     */
    public function desiredHosts(Site $site): array
    {
        $binding = $site->coolifyDomainBinding();
        if ($binding === '') {
            return [];
        }

        $hosts = [];
        foreach (explode(',', $binding) as $part) {
            $host = CloudflareHostname::normalize(CloudflareHostname::host(trim($part)));
            if ($host !== '' && ! CoolifyDomainParser::isGeneratedWildcardHost($host)) {
                $hosts[] = $host;
            }
        }

        return array_values(array_unique($hosts));
    }

    /**
     * @return list<string>
     */
    public function customerHosts(CoolifyApplication $app): array
    {
        $urls = [];
        foreach ($app->composeDomains as $row) {
            if (($row['name'] ?? '') !== CoolifyDomainParser::COMPOSE_SERVICE) {
                continue;
            }
            foreach (preg_split('/\s*,\s*/', (string) ($row['domain'] ?? '')) ?: [] as $part) {
                if (trim($part) !== '') {
                    $urls[] = trim($part);
                }
            }
        }
        if (filled($app->fqdn)) {
            foreach (preg_split('/\s*,\s*/', (string) $app->fqdn) ?: [] as $part) {
                if (trim($part) !== '') {
                    $urls[] = trim($part);
                }
            }
        }

        $hosts = [];
        foreach ($urls as $url) {
            $host = CloudflareHostname::normalize(CloudflareHostname::host($url));
            if ($host !== '' && ! CoolifyDomainParser::isGeneratedWildcardHost($host)) {
                $hosts[] = $host;
            }
        }

        return array_values(array_unique($hosts));
    }
}
