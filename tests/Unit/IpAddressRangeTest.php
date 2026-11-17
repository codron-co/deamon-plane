<?php

namespace Tests\Unit;

use App\Services\Security\IpAddressRange;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class IpAddressRangeTest extends TestCase
{
    public function test_normalize_gives_one_canonical_form(): void
    {
        $this->assertSame('203.0.113.7', IpAddressRange::normalize(' 203.0.113.7 '));
        $this->assertSame('203.0.113.7', IpAddressRange::normalize('203.0.113.7/32'));
        $this->assertSame('203.0.113.0/24', IpAddressRange::normalize('203.0.113.0/24'));
        $this->assertSame('2a00:1450::1', IpAddressRange::normalize('2A00:1450:0:0:0:0:0:1'));
    }

    #[DataProvider('invalid')]
    public function test_normalize_rejects_garbage(string $input): void
    {
        $this->expectException(InvalidArgumentException::class);
        IpAddressRange::normalize($input);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalid(): array
    {
        return ['text' => ['hello'], 'octet' => ['256.1.1.1'], 'prefix' => ['10.0.0.0/33'], 'bad prefix' => ['10.0.0.0/x'], 'empty' => ['']];
    }

    public function test_contains_and_overlaps(): void
    {
        $this->assertTrue(IpAddressRange::contains('198.51.100.0/24', '198.51.100.20'));
        $this->assertTrue(IpAddressRange::contains('198.51.100.0/24', '198.51.100.0/25'));
        $this->assertFalse(IpAddressRange::contains('198.51.100.0/24', '198.51.0.0/16'), 'a wider range is not inside');
        $this->assertFalse(IpAddressRange::contains('198.51.100.0/24', '198.51.101.1'));
        $this->assertFalse(IpAddressRange::contains('198.51.100.0/24', '2a00::1'), 'families never mix');
        $this->assertTrue(IpAddressRange::contains('203.0.113.7', '203.0.113.7'));
        $this->assertTrue(IpAddressRange::overlaps('198.51.0.0/16', '198.51.100.20'));
        $this->assertTrue(IpAddressRange::overlaps('198.51.100.20', '198.51.0.0/16'));
        $this->assertFalse(IpAddressRange::overlaps('198.51.100.0/24', '203.0.113.0/24'));
    }

    public function test_block_guard(): void
    {
        $this->assertSame('203.0.113.0/24', IpAddressRange::normalizeForBlock('203.0.113.0/24'));

        foreach (['10.1.2.3', '127.0.0.1', '172.16.0.9', '192.168.0.1', '203.0.113.0/8', '2a00::/16', '::1', 'fe80::1'] as $bad) {
            try {
                IpAddressRange::normalizeForBlock($bad);
                $this->fail("{$bad} must be refused");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
