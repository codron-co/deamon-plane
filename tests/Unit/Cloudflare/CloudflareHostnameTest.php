<?php

namespace Tests\Unit\Cloudflare;

use App\Services\Cloudflare\CloudflareDnsRecord;
use App\Services\Cloudflare\CloudflareHostname;
use Tests\TestCase;

class CloudflareHostnameTest extends TestCase
{
    public function test_zone_candidates_walk_parents_longest_first(): void
    {
        $this->assertSame(
            ['test.deamon.codron.co', 'deamon.codron.co', 'codron.co'],
            CloudflareHostname::zoneCandidates('test.deamon.codron.co'),
        );

        $this->assertSame(
            [
                'a.b.c.deamon.codron.co',
                'b.c.deamon.codron.co',
                'c.deamon.codron.co',
                'deamon.codron.co',
                'codron.co',
            ],
            CloudflareHostname::zoneCandidates('a.b.c.deamon.codron.co'),
        );

        $this->assertSame(['example.com'], CloudflareHostname::zoneCandidates('example.com'));
        $this->assertSame(['example.com'], CloudflareHostname::zoneCandidates('www.example.com'));
    }

    public function test_relative_record_name_supports_unlimited_labels(): void
    {
        $this->assertSame('test', CloudflareDnsRecord::relative('test.deamon.codron.co', 'deamon.codron.co'));
        $this->assertSame('a.b.c', CloudflareDnsRecord::relative('a.b.c.deamon.codron.co', 'deamon.codron.co'));
        $this->assertSame('test.deamon', CloudflareDnsRecord::relative('test.deamon.codron.co', 'codron.co'));
        $this->assertSame('@', CloudflareDnsRecord::relative('deamon.codron.co', 'deamon.codron.co'));
    }

    public function test_star_covers_one_label_only(): void
    {
        $this->assertTrue(CloudflareHostname::starCovers('test'));
        $this->assertTrue(CloudflareHostname::starCovers('amber-harbor'));
        $this->assertFalse(CloudflareHostname::starCovers('test.deamon'));
        $this->assertFalse(CloudflareHostname::starCovers('a.b.c'));
        $this->assertFalse(CloudflareHostname::starCovers('@'));
    }

    public function test_only_two_label_hosts_are_apex(): void
    {
        $this->assertTrue(CloudflareHostname::isApex('izyem.test'));
        $this->assertFalse(CloudflareHostname::isApex('deamon.codron.co'));
        $this->assertFalse(CloudflareHostname::isApex('test.deamon.codron.co'));
    }
}
