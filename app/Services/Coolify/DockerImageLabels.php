<?php

namespace App\Services\Coolify;

/**
 * Container labels of a Deamon site's Coolify "Docker Image" app.
 *
 * These apps run with read-only labels off (to-dockerimage.sh), so Coolify does not
 * regenerate them when the domains change: Plane writes them with every domain PATCH.
 * The text is what Coolify 4.5 generates for the app (Traefik block, then Caddy block,
 * each sorted the way Coolify sorts them) plus the Deamon additions on the https routers
 * and every service: retry on a vanished container and a 1s dial timeout, both defined in
 * Traefik's file provider (tools/ops/proxy/deamon-retry.yaml). With them a rolling deploy
 * has no failed request while the old container goes away.
 */
final class DockerImageLabels
{
    public const RETRY_MIDDLEWARE = 'deamon-retry@file';

    public const FAST_TRANSPORT = 'deamon-fast@file';

    /**
     * @param  list<string>  $domains  hosts or URLs, in binding order (primary first)
     */
    public static function for(string $appUuid, array $domains, int $port = 8080, string $ingressNetwork = 'deamon-db'): string
    {
        $hosts = self::hosts($domains);
        if (trim($appUuid) === '' || $hosts === []) {
            throw new CoolifyApiException('An app uuid and at least one domain are required for Docker Image labels.', 422);
        }

        $traefik = [
            'traefik.enable=true',
            'traefik.http.middlewares.gzip-sse.compress.excludedcontenttypes=text/event-stream',
            'traefik.http.middlewares.redirect-to-https.redirectscheme.scheme=https',
        ];
        $caddy = [];

        foreach ($hosts as $i => $host) {
            $http = "http-{$i}-{$appUuid}";
            $https = "https-{$i}-{$appUuid}";
            $rule = "Host(`{$host}`) && PathPrefix(`/`)";

            array_push(
                $traefik,
                "traefik.http.routers.{$http}.entryPoints=http",
                "traefik.http.routers.{$http}.middlewares=redirect-to-https",
                "traefik.http.routers.{$http}.rule={$rule}",
                "traefik.http.routers.{$http}.service={$http}",
                "traefik.http.routers.{$https}.entryPoints=https",
                'traefik.http.routers.'.$https.'.middlewares='.self::RETRY_MIDDLEWARE.',gzip-sse',
                "traefik.http.routers.{$https}.rule={$rule}",
                "traefik.http.routers.{$https}.service={$https}",
                "traefik.http.routers.{$https}.tls.certresolver=letsencrypt",
                "traefik.http.routers.{$https}.tls=true",
            );

            foreach ([$http, $https] as $service) {
                array_push(
                    $traefik,
                    "traefik.http.services.{$service}.loadbalancer.server.port={$port}",
                    "traefik.http.services.{$service}.loadbalancer.serverstransport=".self::FAST_TRANSPORT,
                );
            }

            array_push(
                $caddy,
                "caddy_{$i}.encode=zstd gzip",
                "caddy_{$i}.handle_path.{$i}_reverse_proxy={{upstreams {$port}}}",
                "caddy_{$i}.handle_path=/*",
                "caddy_{$i}.header=-Server",
                "caddy_{$i}.try_files={path} /index.html /index.php",
                "caddy_{$i}=https://{$host}",
            );
        }

        $caddy[] = "caddy_ingress_network={$ingressNetwork}";

        sort($traefik, SORT_STRING);
        sort($caddy, SORT_STRING);

        return implode("\n", [...$traefik, ...$caddy]);
    }

    /**
     * The `custom_labels` value Coolify's API takes (base64 of the label text).
     *
     * @param  list<string>  $domains
     */
    public static function encoded(string $appUuid, array $domains, int $port = 8080, string $ingressNetwork = 'deamon-db'): string
    {
        return base64_encode(self::for($appUuid, $domains, $port, $ingressNetwork));
    }

    /**
     * Labels for a Deamon site app from config (port, ingress network).
     *
     * @param  list<string>  $domains
     */
    public static function encodedForSite(string $appUuid, array $domains): string
    {
        return self::encoded(
            $appUuid,
            $domains,
            (int) config('ops.deamon.docker_image.port', 8080),
            (string) config('ops.deamon.docker_image.ingress_network', 'deamon-db'),
        );
    }

    /**
     * Bare lowercase hosts from hosts, URLs or a comma list, first occurrence kept.
     *
     * @param  string|list<string>  $domains
     * @return list<string>
     */
    public static function hosts(string|array $domains): array
    {
        $parts = [];
        foreach ((array) $domains as $domain) {
            foreach (explode(',', (string) $domain) as $part) {
                $parts[] = $part;
            }
        }

        $hosts = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }

            $host = parse_url(preg_match('#^https?://#i', $part) === 1 ? $part : 'https://'.$part, PHP_URL_HOST);
            $host = is_string($host) ? rtrim(strtolower($host), '.') : '';
            if ($host !== '' && ! in_array($host, $hosts, true)) {
                $hosts[] = $host;
            }
        }

        return $hosts;
    }

    /**
     * Coolify's `domains` value for the hosts: `https://a,https://b`.
     *
     * @param  string|list<string>  $domains
     */
    public static function domainsValue(string|array $domains): string
    {
        return implode(',', array_map(static fn (string $host): string => 'https://'.$host, self::hosts($domains)));
    }
}
