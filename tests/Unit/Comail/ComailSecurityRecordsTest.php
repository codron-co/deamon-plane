<?php

namespace Tests\Unit\Comail;

use App\Services\Comail\ComailSecurityRecords;
use App\Services\Comail\ComailSpam;
use Tests\TestCase;

class ComailSecurityRecordsTest extends TestCase
{
    public function test_dmarc_merge_rules(): void
    {
        $ours = 'v=DMARC1; p=none; rua=mailto:dmarc@codron.co; fo=1';

        $this->assertSame($ours, ComailSecurityRecords::mergeDmarc(null, 'none'));
        $this->assertSame($ours, ComailSecurityRecords::mergeDmarc('v=DMARC1; p=none', 'none'));
        $this->assertSame('v=DMARC1; p=reject; rua=mailto:dmarc@codron.co; fo=1', ComailSecurityRecords::mergeDmarc($ours, 'reject'));
        // Plane's own record may be loosened; a customer's stricter one may not.
        $this->assertSame($ours, ComailSecurityRecords::mergeDmarc('v=DMARC1; p=reject; rua=mailto:dmarc@codron.co; fo=1', 'none'));
        $this->assertNull(ComailSecurityRecords::mergeDmarc('v=DMARC1; p=reject', 'quarantine'));
        $this->assertSame(
            'v=DMARC1; p=quarantine; sp=none; rua=mailto:r@acme.test,mailto:dmarc@codron.co; fo=0',
            ComailSecurityRecords::mergeDmarc('v=DMARC1; p=none; sp=none; rua=mailto:r@acme.test; fo=0', 'quarantine'),
        );
    }

    public function test_dmarc_verification(): void
    {
        $this->assertSame(['missing', null], ComailSecurityRecords::verifyDmarc([], 'none'));
        $this->assertSame(['ok', null], ComailSecurityRecords::verifyDmarc(['v=DMARC1; p=none; rua=mailto:dmarc@codron.co'], 'none'));
        $this->assertSame(['different', 'v=DMARC1; p=none'], ComailSecurityRecords::verifyDmarc(['v=DMARC1; p=none'], 'none'));
    }

    public function test_report_authorization_name(): void
    {
        $this->assertSame('acme.test._report._dmarc.codron.co', ComailSecurityRecords::reportAuthorizationName('Acme.test'));
        $this->assertNull(ComailSecurityRecords::reportAuthorizationName('codron.co'));
        $this->assertNull(ComailSecurityRecords::reportAuthorizationName('shop.codron.co'));
    }

    public function test_spam_sender_normalisation(): void
    {
        $this->assertSame('user@acme.test', ComailSpam::normaliseSender(' User@Acme.test '));
        $this->assertSame('*@acme.test', ComailSpam::normaliseSender('@acme.test'));
        $this->assertSame('*@acme.test', ComailSpam::normaliseSender('acme.test'));
        $this->assertSame('*@acme.test', ComailSpam::normaliseSender('*@acme.test'));
        $this->assertNull(ComailSpam::normaliseSender('a+b@acme.test'));
        $this->assertNull(ComailSpam::normaliseSender('nobody'));
        $this->assertSame('custom', ComailSpam::levelOf(6, 12));
        $this->assertSame('normal', ComailSpam::levelOf(8, 15));
    }
}
