<?php

namespace App\Services\Sites;

use App\Models\Site;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Throwable;

class SiteLiveProbe
{
    /**
     * GET each site homepage from the public origin. Stores status + favicon href
     * from the live HTML. Does not write secrets or change Coolify.
     *
     * @param  Collection<int, Site>|iterable<int, Site>  $sites
     * @return array{ok: int, failed: int, statuses: array<int, int>}
     */
    public function probeMany(iterable $sites): array
    {
        $targets = [];
        foreach ($sites as $site) {
            if (! $site instanceof Site) {
                continue;
            }

            $url = $this->homepageUrl($site);
            if ($url === null) {
                continue;
            }

            $targets[$site->id] = ['site' => $site, 'url' => $url];
        }

        $ok = 0;
        $failed = 0;
        $statuses = [];

        foreach ($targets as $target) {
            /** @var Site $site */
            $site = $target['site'];
            $status = 0;
            $favicon = $site->last_live_favicon_url;
            $response = null;

            try {
                $response = Http::timeout(8)
                    ->connectTimeout(4)
                    ->withUserAgent('Deamon-Plane-LiveSync/1')
                    ->withOptions(['allow_redirects' => true])
                    ->get($target['url']);
            } catch (Throwable) {
                $response = null;
            }

            if ($response instanceof Response) {
                $status = $response->status();
                $found = $this->faviconFromHtml((string) $response->body(), $target['url']);
                if ($found !== null) {
                    $favicon = $found;
                }
                if ($status >= 200 && $status < 400) {
                    $ok++;
                } else {
                    $failed++;
                }
            } else {
                $failed++;
            }

            $site->forceFill([
                'last_live_http_status' => $status,
                'last_live_checked_at' => now(),
                'last_live_favicon_url' => $favicon,
            ])->save();

            $statuses[$status] = ($statuses[$status] ?? 0) + 1;
        }

        return [
            'ok' => $ok,
            'failed' => $failed,
            'statuses' => $statuses,
        ];
    }

    public function homepageUrl(Site $site): ?string
    {
        $host = strtolower(trim((string) $site->primary_domain));
        if ($host === '' || str_contains($host, '..') || ! preg_match('/^[a-z0-9.-]+$/i', $host)) {
            return null;
        }

        return 'https://'.$host.'/';
    }

    public function faviconFromHtml(string $html, string $baseUrl): ?string
    {
        if ($html === '' || ! str_contains(strtolower($html), 'link')) {
            return null;
        }

        $dom = new \DOMDocument;
        try {
            @$dom->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
        } catch (Throwable) {
            return null;
        }

        $links = $dom->getElementsByTagName('link');
        $href = null;
        foreach ($links as $link) {
            if (! $link instanceof \DOMElement) {
                continue;
            }

            $rel = strtolower(trim($link->getAttribute('rel')));
            if ($rel === '' || ! str_contains($rel, 'icon')) {
                continue;
            }

            $candidate = trim($link->getAttribute('href'));
            if ($candidate === '') {
                continue;
            }

            $href = $candidate;
            if (str_contains($rel, 'apple-touch-icon') === false) {
                break;
            }
        }

        return $this->absoluteUrl($href, $baseUrl);
    }

    private function absoluteUrl(?string $href, string $baseUrl): ?string
    {
        if (! is_string($href) || $href === '') {
            return null;
        }

        $href = trim($href);
        if (preg_match('/^(javascript|data|file):/i', $href) === 1) {
            return null;
        }

        if (str_starts_with($href, '//')) {
            $href = 'https:'.$href;
        }

        if (! preg_match('#^https?://#i', $href)) {
            $base = rtrim($baseUrl, '/');
            $href = str_starts_with($href, '/') ? $base.$href : $base.'/'.$href;
        }

        $parts = parse_url($href);
        if (! is_array($parts) || empty($parts['host'])) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $host = strtolower((string) $parts['host']);
        if ($host === '' || str_contains($host, '..')) {
            return null;
        }

        $path = (string) ($parts['path'] ?? '/');
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';

        return $scheme.'://'.$host.$path.$query;
    }
}
