<?php

namespace App\Services\Instagram;

use App\Models\Site;
use App\Services\Cloudflare\CloudflareHostname;
use App\Support\ControlPlaneAgentSignature;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Verifies the browser link a CMS admin opens to start «Instagram ile bağlan».
 * The CMS signs "instagram-connect\n{plane_site_id}\n{return}" with its agent secret
 * (same HMAC as the agent channel); the return URL must be one of the site's own hosts.
 */
final class InstagramConnectLink
{
    public const PURPOSE = 'instagram-connect';

    /** The admin clicks within seconds; a generous window still keeps a leaked link short-lived. */
    private const MAX_SKEW_SECONDS = 600;

    public static function canonical(string $planeSiteId, string $returnUrl): string
    {
        return self::PURPOSE."\n".$planeSiteId."\n".$returnUrl;
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array{site: Site, return: string}
     *
     * @throws RuntimeException
     */
    public function verify(array $query): array
    {
        $siteId = (string) ($query['site'] ?? '');
        $timestamp = (string) ($query['ts'] ?? '');
        $nonce = (string) ($query['nonce'] ?? '');
        $return = (string) ($query['return'] ?? '');
        $signature = (string) ($query['sig'] ?? '');

        if ($siteId === '' || ! ctype_digit($timestamp) || preg_match('/\A[A-Za-z0-9._-]{8,128}\z/', $nonce) !== 1 || $return === '' || $signature === '') {
            throw new RuntimeException('invalid_link');
        }

        if (abs(time() - (int) $timestamp) > self::MAX_SKEW_SECONDS) {
            throw new RuntimeException('link_expired');
        }

        $site = Site::query()->with('domains')->find($siteId);
        if (! $site instanceof Site || ! $site->hasAgentSecret()) {
            throw new RuntimeException('invalid_link');
        }

        if (! ControlPlaneAgentSignature::matches((string) $site->agent_secret_encrypted, $timestamp, $nonce, self::canonical($siteId, $return), $signature)) {
            throw new RuntimeException('invalid_signature');
        }

        $this->assertReturnBelongsToSite($site, $return);

        if (! Cache::add('instagram-connect:nonce:'.hash('sha256', $siteId.'|'.$nonce), 1, self::MAX_SKEW_SECONDS * 2)) {
            throw new RuntimeException('link_reused');
        }

        return ['site' => $site, 'return' => $return];
    }

    private function assertReturnBelongsToSite(Site $site, string $return): void
    {
        $parts = parse_url($return);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = CloudflareHostname::host((string) ($parts['host'] ?? ''));

        $allowedSchemes = app()->environment(['local', 'testing']) ? ['https', 'http'] : ['https'];
        $hosts = $site->operatorHosts();
        if (filled($site->temporary_domain)) {
            $hosts[] = CloudflareHostname::host((string) $site->temporary_domain);
        }

        if (! in_array($scheme, $allowedSchemes, true) || $host === '' || isset($parts['user']) || ! in_array($host, $hosts, true)) {
            throw new RuntimeException('invalid_return');
        }
    }
}
