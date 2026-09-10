<?php

namespace Tests\Unit\Support;

use App\Support\IdentityMark;
use Tests\TestCase;

class IdentityMarkTest extends TestCase
{
    public function test_letter_uses_utf8_character_not_first_byte(): void
    {
        $this->assertSame('İ', IdentityMark::letter('İzyem'));
        $this->assertNotSame('İ', strtoupper(substr('İzyem', 0, 1)));
        $this->assertSame('A', IdentityMark::letter('alpha'));
        $this->assertSame('?', IdentityMark::letter(''));
        $this->assertSame('?', IdentityMark::letter("\u{FFFD}"));
    }
}
