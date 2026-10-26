<?php

namespace Tests\Unit\Coolify;

use App\Services\Coolify\CoolifyApiException;
use App\Services\Coolify\DockerImageLabels;
use PHPUnit\Framework\TestCase;

class DockerImageLabelsTest extends TestCase
{
    private const UUID = 'f8spwrln1cutk43tw6mwpqnk';

    /**
     * The live labels of the two-host Docker Image app on srv2 (Coolify 4.5 + Deamon additions).
     */
    private const LIVE = <<<'LABELS'
traefik.enable=true
traefik.http.middlewares.gzip-sse.compress.excludedcontenttypes=text/event-stream
traefik.http.middlewares.redirect-to-https.redirectscheme.scheme=https
traefik.http.routers.http-0-U.entryPoints=http
traefik.http.routers.http-0-U.middlewares=redirect-to-https
traefik.http.routers.http-0-U.rule=Host(`mcp-test.testdomain.tr`) && PathPrefix(`/`)
traefik.http.routers.http-0-U.service=http-0-U
traefik.http.routers.http-1-U.entryPoints=http
traefik.http.routers.http-1-U.middlewares=redirect-to-https
traefik.http.routers.http-1-U.rule=Host(`www.mcp-test.testdomain.tr`) && PathPrefix(`/`)
traefik.http.routers.http-1-U.service=http-1-U
traefik.http.routers.https-0-U.entryPoints=https
traefik.http.routers.https-0-U.middlewares=deamon-retry@file,gzip-sse
traefik.http.routers.https-0-U.rule=Host(`mcp-test.testdomain.tr`) && PathPrefix(`/`)
traefik.http.routers.https-0-U.service=https-0-U
traefik.http.routers.https-0-U.tls.certresolver=letsencrypt
traefik.http.routers.https-0-U.tls=true
traefik.http.routers.https-1-U.entryPoints=https
traefik.http.routers.https-1-U.middlewares=deamon-retry@file,gzip-sse
traefik.http.routers.https-1-U.rule=Host(`www.mcp-test.testdomain.tr`) && PathPrefix(`/`)
traefik.http.routers.https-1-U.service=https-1-U
traefik.http.routers.https-1-U.tls.certresolver=letsencrypt
traefik.http.routers.https-1-U.tls=true
traefik.http.services.http-0-U.loadbalancer.server.port=8080
traefik.http.services.http-0-U.loadbalancer.serverstransport=deamon-fast@file
traefik.http.services.http-1-U.loadbalancer.server.port=8080
traefik.http.services.http-1-U.loadbalancer.serverstransport=deamon-fast@file
traefik.http.services.https-0-U.loadbalancer.server.port=8080
traefik.http.services.https-0-U.loadbalancer.serverstransport=deamon-fast@file
traefik.http.services.https-1-U.loadbalancer.server.port=8080
traefik.http.services.https-1-U.loadbalancer.serverstransport=deamon-fast@file
caddy_0.encode=zstd gzip
caddy_0.handle_path.0_reverse_proxy={{upstreams 8080}}
caddy_0.handle_path=/*
caddy_0.header=-Server
caddy_0.try_files={path} /index.html /index.php
caddy_0=https://mcp-test.testdomain.tr
caddy_1.encode=zstd gzip
caddy_1.handle_path.1_reverse_proxy={{upstreams 8080}}
caddy_1.handle_path=/*
caddy_1.header=-Server
caddy_1.try_files={path} /index.html /index.php
caddy_1=https://www.mcp-test.testdomain.tr
caddy_ingress_network=deamon-db
LABELS;

    public function test_it_reproduces_the_live_two_host_labels_line_for_line(): void
    {
        $expected = str_replace('-U', '-'.self::UUID, self::LIVE);

        $this->assertSame(
            $expected,
            DockerImageLabels::for(self::UUID, ['mcp-test.testdomain.tr', 'www.mcp-test.testdomain.tr']),
        );
    }

    public function test_urls_and_comma_lists_give_the_same_labels_and_base64_is_the_api_value(): void
    {
        $fromHosts = DockerImageLabels::for(self::UUID, ['mcp-test.testdomain.tr', 'www.mcp-test.testdomain.tr']);

        $this->assertSame($fromHosts, DockerImageLabels::for(self::UUID, ['https://MCP-test.testdomain.tr/', 'https://www.mcp-test.testdomain.tr,mcp-test.testdomain.tr']));
        $this->assertSame($fromHosts, base64_decode(DockerImageLabels::encoded(self::UUID, ['mcp-test.testdomain.tr', 'www.mcp-test.testdomain.tr']), true));
        $this->assertSame('https://a.test,https://www.a.test', DockerImageLabels::domainsValue('a.test, https://www.a.test/'));
    }

    public function test_port_and_ingress_network_are_parameters(): void
    {
        $labels = DockerImageLabels::for('app1', ['one.test'], 9000, 'other-net');

        $this->assertStringContainsString("traefik.http.services.https-0-app1.loadbalancer.server.port=9000\n", $labels);
        $this->assertStringContainsString('caddy_0.handle_path.0_reverse_proxy={{upstreams 9000}}', $labels);
        $this->assertStringEndsWith("caddy_0=https://one.test\ncaddy_ingress_network=other-net", $labels);
    }

    public function test_no_host_is_refused(): void
    {
        $this->expectException(CoolifyApiException::class);

        DockerImageLabels::for(self::UUID, [' ', '']);
    }
}
