<?php

namespace App\Support;

final class PublicAppUrl
{
    /**
     * The Plane URL handed to CMS sites for signed callbacks. The CMS rejects a non-https
     * plane_base_url in production ("URL must use https."), so a public host is always
     * sent as https even when APP_URL was injected as http behind the TLS proxy. Local
     * and private hosts keep their scheme for development.
     */
    public static function forAgents(?string $url = null): string
    {
        $url = rtrim(trim((string) ($url ?? config('app.url'))), '/');
        $parts = parse_url($url);
        if (! is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'http') {
            return $url;
        }

        $asHttps = 'https'.substr($url, strlen('http'));

        return self::isPublic($asHttps) ? $asHttps : $url;
    }

    public static function isPublic(?string $url = null): bool
    {
        $url = trim((string) ($url ?? config('app.url')));
        if ($url === '') {
            return false;
        }

        $parts = parse_url($url);
        if (! is_array($parts)) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        if ($scheme !== 'https' || $host === '') {
            return false;
        }

        if (in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            return false;
        }

        foreach (['.localhost', '.local', '.test', '.invalid'] as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return false;
            }
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
        }

        return true;
    }
}
