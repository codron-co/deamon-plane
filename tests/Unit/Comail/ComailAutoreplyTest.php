<?php

namespace Tests\Unit\Comail;

use App\Services\Comail\ComailAutoreply;
use PHPUnit\Framework\TestCase;

class ComailAutoreplyTest extends TestCase
{
    public function test_script_escapes_quotes_and_backslashes_and_strips_controls(): void
    {
        $script = ComailAutoreply::script([
            'subject' => "Tatil \"yaz\"\nikinci satır",
            'body' => "Satır 1\r\nSatır \\ 2 \"x\"\x07",
        ]);

        $this->assertStringContainsString('require ["vacation","date","relational"];', $script);
        $this->assertStringContainsString('vacation :days 1 :subject "Tatil \\"yaz\\" ikinci satır" "Satır 1'."\n".'Satır \\\\ 2 \\"x\\"";', $script);
        $this->assertStringNotContainsString("\x07", $script);
        $this->assertStringNotContainsString("\r", $script);
        $this->assertStringNotContainsString('currentdate', $script);
    }

    public function test_injection_attempt_stays_inside_the_string(): void
    {
        $script = ComailAutoreply::script(['subject' => 's', 'body' => '"; discard; vacation "x']);

        // Exactly one statement: the injected quote is escaped.
        $this->assertSame(1, substr_count($script, 'vacation :days'));
        $this->assertStringContainsString('"\\"; discard; vacation \\"x"', $script);
    }

    public function test_date_guard_and_metadata_round_trip(): void
    {
        $script = ComailAutoreply::script([
            'subject' => 'Kapalıyız',
            'body' => 'Pazartesi döneriz.',
            'starts_on' => '2026-12-24',
            'ends_on' => '2027-01-02',
        ]);

        $this->assertStringContainsString('if allof(currentdate :value "ge" "date" "2026-12-24", currentdate :value "le" "date" "2027-01-02") {', $script);
        $this->assertSame([
            'subject' => 'Kapalıyız',
            'body' => 'Pazartesi döneriz.',
            'starts_on' => '2026-12-24',
            'ends_on' => '2027-01-02',
        ], ComailAutoreply::read($script));
    }

    public function test_invalid_dates_are_ignored_and_foreign_scripts_read_empty(): void
    {
        $script = ComailAutoreply::script(['subject' => 'a', 'body' => 'b', 'starts_on' => '2026-02-30', 'ends_on' => 'yarın']);

        $this->assertStringNotContainsString('currentdate', $script);
        $this->assertSame(['subject' => '', 'body' => '', 'starts_on' => null, 'ends_on' => null], ComailAutoreply::read('require "vacation"; vacation "x";'));
    }
}
