<?php

namespace Tests\Unit\Support;

use App\Support\PublicAppUrl;
use Tests\TestCase;

class PublicAppUrlForAgentsTest extends TestCase
{
    public function test_public_http_app_url_is_sent_as_https(): void
    {
        $this->assertSame('https://plane.codron.co', PublicAppUrl::forAgents('http://plane.codron.co/'));
    }

    public function test_https_is_kept_and_trailing_slash_trimmed(): void
    {
        $this->assertSame('https://plane.codron.co', PublicAppUrl::forAgents('https://plane.codron.co/'));
    }

    public function test_local_and_private_hosts_keep_http(): void
    {
        foreach (['http://localhost:8088', 'http://plane.test', 'http://192.168.1.10'] as $url) {
            $this->assertSame($url, PublicAppUrl::forAgents($url));
        }
    }

    public function test_it_reads_app_url_by_default(): void
    {
        config(['app.url' => 'http://ops.example.com']);

        $this->assertSame('https://ops.example.com', PublicAppUrl::forAgents());
    }
}
