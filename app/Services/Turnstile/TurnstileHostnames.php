<?php

namespace App\Services\Turnstile;

use App\Models\CloudflareSetting;
use App\Models\Site;
use App\Services\Cloudflare\CloudflareHostname;

/**
 * Bir sitenin widget'a yazılacak alan adları. Cloudflare'de kök alan adı alt
 * alan adlarını da kapsar, bu yüzden müşteri alanı kökten yazılır (www ayrıca
 * yer tutmaz). Ajansın ortak alanındaki (`*.codron.co`) site ise tam adıyla
 * yazılır: kök yazılsaydı başka sitelerin önizleme adresleri de kapsanırdı.
 * Geçici önizleme adresi yazılmaz (CMS orada korumayı atlar).
 */
final class TurnstileHostnames
{
    /**
     * @return list<string>
     */
    public function forSite(Site $site, CloudflareSetting $account): array
    {
        $shared = $account->resolvedWildcardDomain();
        $hosts = [];

        foreach ($site->operatorHosts() as $host) {
            $host = CloudflareHostname::host($host);
            if ($host === '' || ! str_contains($host, '.')) {
                continue;
            }

            if ($shared !== '' && ($host === $shared || str_ends_with($host, '.'.$shared))) {
                $hosts[] = CloudflareHostname::normalize($host);

                continue;
            }

            $apex = CloudflareHostname::apex($host);
            if ($apex !== '') {
                $hosts[] = $apex;
            }
        }

        $hosts = array_values(array_unique($hosts));
        sort($hosts);

        return $hosts;
    }
}
