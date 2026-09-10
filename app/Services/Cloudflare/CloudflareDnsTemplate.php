<?php

namespace App\Services\Cloudflare;

class CloudflareDnsTemplate
{
    /**
     * @return list<CloudflareDnsRecord>
     */
    public function records(string $originIpv4, bool $mailEnabled): array
    {
        $records = [
            new CloudflareDnsRecord('A', '@', $originIpv4, proxied: false),
            new CloudflareDnsRecord('A', 'www', $originIpv4, proxied: false),
            new CloudflareDnsRecord('A', '*', $originIpv4, proxied: false),
        ];

        if (! $mailEnabled) {
            return $records;
        }

        foreach (['hostingermail-a._domainkey', 'hostingermail-b._domainkey', 'hostingermail-c._domainkey'] as $name) {
            $records[] = new CloudflareDnsRecord('CNAME', $name, 'dkim.mail.hostinger.com', proxied: false);
        }

        $records[] = new CloudflareDnsRecord('MX', '@', 'mx1.hostinger.com', priority: 5);
        $records[] = new CloudflareDnsRecord('MX', '@', 'mx2.hostinger.com', priority: 10);
        $records[] = new CloudflareDnsRecord('TXT', '@', 'v=spf1 include:_spf.mail.hostinger.com ~all');
        $records[] = new CloudflareDnsRecord('TXT', '_dmarc', 'v=DMARC1; p=none');

        return $records;
    }
}
