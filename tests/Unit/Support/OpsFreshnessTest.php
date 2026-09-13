<?php

namespace Tests\Unit\Support;

use App\Support\OpsFreshness;
use Tests\TestCase;

class OpsFreshnessTest extends TestCase
{
    public function test_a_missing_timestamp_is_never_stale(): void
    {
        $fresh = OpsFreshness::describe(null);

        $this->assertTrue($fresh['missing']);
        $this->assertFalse($fresh['stale']);
        $this->assertNull($fresh['absolute']);
    }

    public function test_age_inside_the_window_is_relative_and_not_stale(): void
    {
        config(['ops.agent.poll_minutes' => 10]);

        $fresh = OpsFreshness::describe(now()->subMinutes(4));

        $this->assertFalse($fresh['missing']);
        $this->assertFalse($fresh['stale']);
        $this->assertSame(trans_choice('ops.freshness.minutes', 4, ['count' => 4]), $fresh['label']);
        $this->assertNotNull($fresh['absolute']);
    }

    public function test_age_past_twice_the_poll_window_is_stale(): void
    {
        config(['ops.agent.poll_minutes' => 10]);

        $fresh = OpsFreshness::describe(now()->subHours(4));

        $this->assertTrue($fresh['stale']);
        $this->assertSame(trans_choice('ops.freshness.hours', 4, ['count' => 4]), $fresh['label']);
    }

    public function test_mark_stale_false_keeps_the_relative_age_without_the_flag(): void
    {
        config(['ops.agent.poll_minutes' => 10]);

        $fresh = OpsFreshness::describe(now()->subHours(4), markStale: false);

        $this->assertFalse($fresh['stale']);
        $this->assertSame(trans_choice('ops.freshness.hours', 4, ['count' => 4]), $fresh['label']);
    }

    public function test_the_poll_window_is_clamped_like_the_scheduler(): void
    {
        config(['ops.agent.poll_minutes' => 1]);
        $this->assertSame(5, OpsFreshness::pollWindowMinutes());
        $this->assertSame(10, OpsFreshness::staleAfterMinutes());

        config(['ops.agent.poll_minutes' => 40]);
        $this->assertSame(15, OpsFreshness::pollWindowMinutes());
        $this->assertSame(30, OpsFreshness::staleAfterMinutes());
    }
}
