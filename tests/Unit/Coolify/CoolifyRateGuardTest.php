<?php

namespace Tests\Unit\Coolify;

use App\Services\Coolify\CoolifyRateGuard;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class CoolifyRateGuardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
        config()->set('ops.coolify.rate.min_interval_ms', 200);
        config()->set('ops.coolify.rate.max_cooldown_ms', 30000);
    }

    public function test_first_call_to_a_host_does_not_wait(): void
    {
        app(CoolifyRateGuard::class)->await('https://coolify.example/api/v1');

        Sleep::assertNeverSlept();
    }

    public function test_second_call_waits_for_the_configured_spacing(): void
    {
        $guard = app(CoolifyRateGuard::class);

        $guard->await('https://coolify.example/api/v1');
        $guard->await('https://coolify.example/api/v1');

        Sleep::assertSleptTimes(1);
    }

    public function test_other_hosts_are_paced_independently(): void
    {
        $guard = app(CoolifyRateGuard::class);

        $guard->await('https://one.example/api/v1');
        $guard->await('https://two.example/api/v1');

        Sleep::assertNeverSlept();
    }

    public function test_penalize_holds_later_callers_for_the_cooldown(): void
    {
        $guard = app(CoolifyRateGuard::class);

        $guard->penalize('https://coolify.example/api/v1', 5.0);

        $this->assertGreaterThan(4.0, $guard->cooldownRemaining('https://coolify.example/api/v1'));

        $guard->await('https://coolify.example/api/v1');

        Sleep::assertSleptTimes(1);
    }

    public function test_cooldown_is_clamped_to_the_configured_maximum(): void
    {
        config()->set('ops.coolify.rate.max_cooldown_ms', 2000);
        $guard = app(CoolifyRateGuard::class);

        $guard->penalize('https://coolify.example/api/v1', 600.0);

        $this->assertLessThanOrEqual(2.0, $guard->cooldownRemaining('https://coolify.example/api/v1'));
    }

    public function test_the_guard_is_shared_across_resolutions(): void
    {
        app(CoolifyRateGuard::class)->penalize('https://coolify.example/api/v1', 4.0);

        $this->assertGreaterThan(
            3.0,
            app(CoolifyRateGuard::class)->cooldownRemaining('https://coolify.example/api/v1'),
        );
    }
}
