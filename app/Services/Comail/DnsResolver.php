<?php

namespace App\Services\Comail;

/**
 * Public DNS lookups for the CoMail DNS check. Tests bind a fake.
 *
 * Every answer is normalised to lowercase values without the trailing dot:
 *  - MX:    ['target' => 'mail.codron.co', 'priority' => 10]
 *  - TXT:   ['value' => 'v=spf1 …'] (multi-string records joined)
 *  - CNAME: ['target' => 'mail.codron.co']
 *  - SRV:   ['target' => …, 'priority' => 0, 'weight' => 1, 'port' => 443]
 *  - A:     ['ip' => '31.97.79.49'] (the system resolver follows a CNAME chain)
 */
interface DnsResolver
{
    /**
     * @param  'A'|'MX'|'TXT'|'CNAME'|'SRV'  $type
     * @return list<array<string, mixed>> empty when nothing (or the lookup failed)
     */
    public function lookup(string $name, string $type): array;
}
