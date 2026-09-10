<?php

namespace Tests\Unit\Services;

use App\Models\Site;
use App\Services\Sites\SiteLiveProbe;
use Tests\TestCase;

class SiteLiveProbeTest extends TestCase
{
    public function test_homepage_url_allows_plain_hosts_only(): void
    {
        $probe = new SiteLiveProbe;
        $site = new Site(['primary_domain' => 'shop.example.test']);

        $this->assertSame('https://shop.example.test/', $probe->homepageUrl($site));
        $this->assertNull($probe->homepageUrl(new Site(['primary_domain' => ''])));
        $this->assertNull($probe->homepageUrl(new Site(['primary_domain' => 'not a host'])));
        $this->assertNull($probe->homepageUrl(new Site(['primary_domain' => 'evil..example.test'])));
    }

    public function test_favicon_from_html_resolves_icon_and_rejects_unsafe_hrefs(): void
    {
        $probe = new SiteLiveProbe;
        $base = 'https://shop.example.test/';

        $this->assertSame(
            'https://shop.example.test/theme/favicon.png',
            $probe->faviconFromHtml('<html><head><link rel="icon" href="/theme/favicon.png"></head></html>', $base),
        );
        $this->assertSame(
            'https://cdn.example.test/fav.ico',
            $probe->faviconFromHtml('<link rel="shortcut icon" href="https://cdn.example.test/fav.ico">', $base),
        );
        $this->assertNull($probe->faviconFromHtml('<link rel="icon" href="javascript:alert(1)">', $base));
        $this->assertNull($probe->faviconFromHtml('<link rel="icon" href="data:image/png;base64,xx">', $base));
        $this->assertNull($probe->faviconFromHtml('<link rel="stylesheet" href="/app.css">', $base));
    }
}
