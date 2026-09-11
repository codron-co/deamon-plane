<?php

namespace Tests\Unit\Models;

use App\Models\Deployment;
use Carbon\Carbon;
use Tests\TestCase;

class DeploymentDurationLabelTest extends TestCase
{
    public function test_duration_label_is_never_negative_when_finished_before_started(): void
    {
        $deployment = new Deployment([
            'started_at' => Carbon::parse('2026-09-10 23:03:00', 'Europe/Istanbul'),
            'finished_at' => Carbon::parse('2026-09-10 20:03:00', 'Europe/Istanbul'),
        ]);

        $this->assertSame('180m', $deployment->durationLabel());
        $this->assertStringNotContainsString('-', $deployment->durationLabel());
    }

    public function test_duration_label_formats_minutes_and_seconds(): void
    {
        $deployment = new Deployment([
            'started_at' => Carbon::parse('2026-09-10 12:00:00', 'Europe/Istanbul'),
            'finished_at' => Carbon::parse('2026-09-10 12:02:05', 'Europe/Istanbul'),
        ]);

        $this->assertSame('2m 5s', $deployment->durationLabel());
    }

    public function test_duration_label_uses_em_dash_without_start(): void
    {
        $deployment = new Deployment([
            'started_at' => null,
            'finished_at' => now(),
        ]);

        $this->assertSame('—', $deployment->durationLabel());
    }
}
