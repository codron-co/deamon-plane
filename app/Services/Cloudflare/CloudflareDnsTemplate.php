<?php

namespace App\Services\Cloudflare;

class CloudflareDnsTemplate
{
    /**
     * Exact Plane DNS template. Nothing else.
     *
     * @return list<array{type: string, name: string, content: string, ttl: int, proxied: bool, priority?: int}>
     */
    public static function records(string $originIpv4, bool $mailEnabled): array
    {
        $records = [];

        foreach (['@', 'www', '*'] as $name) {
            $records[] = [
                'type' => 'A',
                'name' => $name,
                'content' => $originIpv4,
                'ttl' => 1,
                'proxied' => false,
            ];
        }

        if (! $mailEnabled) {
            return $records;
        }

        foreach (['hostingermail-a._domainkey', 'hostingermail-b._domainkey', 'hostingermail-c._domainkey'] as $name) {
            $records[] = [
                'type' => 'CNAME',
                'name' => $name,
                'content' => 'dkim.mail.hostinger.com',
                'ttl' => 1,
                'proxied' => false,
            ];
        }

        $records[] = [
            'type' => 'MX',
            'name' => '@',
            'content' => 'mx1.hostinger.com',
            'ttl' => 1,
            'proxied' => false,
            'priority' => 5,
        ];
        $records[] = [
            'type' => 'MX',
            'name' => '@',
            'content' => 'mx2.hostinger.com',
            'ttl' => 1,
            'proxied' => false,
            'priority' => 10,
        ];
        $records[] = [
            'type' => 'TXT',
            'name' => '@',
            'content' => 'v=spf1 include:_spf.mail.hostinger.com ~all',
            'ttl' => 1,
            'proxied' => false,
        ];
        $records[] = [
            'type' => 'TXT',
            'name' => '_dmarc',
            'content' => 'v=DMARC1; p=none',
            'ttl' => 1,
            'proxied' => false,
        ];

        return $records;
    }
}
