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

    public function test_apex_is_the_registrable_two_label_suffix(): void
    {
        $this->assertSame('izyem.test', CloudflareHostname::apex('izyem.test'));
        $this->assertSame('example.com', CloudflareHostname::apex('www.example.com'));
        $this->assertSame('customer.example', CloudflareHostname::apex('shop.customer.example'));
        $this->assertSame('codron.co', CloudflareHostname::apex('a.b.c.deamon.codron.co'));
        $this->assertSame('', CloudflareHostname::apex('localhost'));
    }

    public function test_multi_label_public_suffixes_are_never_a_zone(): void
    {
        $this->assertSame('firma.com.tr', CloudflareHostname::apex('firma.com.tr'));
        $this->assertSame('firma.com.tr', CloudflareHostname::apex('www.firma.com.tr'));
        $this->assertSame('firma.com.tr', CloudflareHostname::apex('shop.firma.com.tr'));
        $this->assertSame('okul.k12.tr', CloudflareHostname::apex('www.okul.k12.tr'));
        $this->assertSame('shop.co.uk', CloudflareHostname::apex('www.shop.co.uk'));
        $this->assertSame(['a.firma.com.tr', 'firma.com.tr'], CloudflareHostname::zoneCandidates('a.firma.com.tr'));
        $this->assertTrue(CloudflareHostname::isApex('firma.com.tr'));
        $this->assertFalse(CloudflareHostname::isApex('shop.firma.com.tr'));
        $this->assertFalse(CloudflareHostname::isApex('com.tr'));
        $this->assertSame('', CloudflareHostname::apex('com.tr'));
        $this->assertSame([], CloudflareHostname::zoneCandidates('com.tr'));
        $this->assertTrue(CloudflareHostname::isPublicSuffix('com.tr'));
        $this->assertTrue(CloudflareHostname::isPublicSuffix('.com.tr'));
        $this->assertFalse(CloudflareHostname::isPublicSuffix('firma.com.tr'));
        // A plain .tr registration and ordinary .com stay two labels.
        $this->assertSame('firma.tr', CloudflareHostname::apex('www.firma.tr'));
        $this->assertTrue(CloudflareHostname::sameRegistrableApex('a.firma.com.tr', 'b.firma.com.tr'));
        $this->assertFalse(CloudflareHostname::sameRegistrableApex('a.firma.com.tr', 'b.baska.com.tr'));
    }

    public function test_www_companion_keeps_the_operator_host(): void
    {
        $this->assertSame('www.example.com', CloudflareHostname::wwwHost('example.com'));
        $this->assertSame('www.example.com', CloudflareHostname::wwwHost('www.example.com'));
        $this->assertSame('www.shop.customer.example', CloudflareHostname::wwwHost('shop.customer.example'));
        $this->assertTrue(CloudflareHostname::sameRegistrableApex('blog.customer.example', 'shop.customer.example'));
        $this->assertFalse(CloudflareHostname::sameRegistrableApex('other.test', 'shop.customer.example'));
        $this->assertTrue(CloudflareHostname::zoneIsReady('active'));
        $this->assertTrue(CloudflareHostname::zoneIsReady(null));
        $this->assertFalse(CloudflareHostname::zoneIsReady('pending'));
    }
}
