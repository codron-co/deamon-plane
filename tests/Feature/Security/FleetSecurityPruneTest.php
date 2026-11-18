<?php

namespace Tests\Feature\Security;

use App\Models\SiteSecurityEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Security\Concerns\FakesSecuritySites;
use Tests\TestCase;

class FleetSecurityPruneTest extends TestCase
{
    use FakesSecuritySites;
    use RefreshDatabase;

    public function test_events_older_than_ninety_days_are_deleted_and_recent_ones_kept(): void
    {
        $this->bootSecurityFakes();
        $site = $this->activeSite('alpha');
        foreach ([2, 80, 91, 200] as $days) {
            SiteSecurityEvent::query()->create([
                'site_id' => $site->id, 'event_uid' => (string) Str::uuid(), 'occurred_at' => now()->subDays($days),
                'type' => 'probe', 'severity' => 1, 'rule' => 'r', 'ip' => '203.0.113.7',
            ]);
        }

        $this->artisan('security:prune')->assertSuccessful();

        $this->assertSame(2, SiteSecurityEvent::query()->count());
        $this->assertSame(0, SiteSecurityEvent::query()->where('occurred_at', '<', now()->subDays(90))->count());
    }

    public function test_the_retention_has_a_floor_and_both_jobs_are_scheduled(): void
    {
        $this->bootSecurityFakes();
        $site = $this->activeSite('alpha');
        SiteSecurityEvent::query()->create([
            'site_id' => $site->id, 'event_uid' => 'x', 'occurred_at' => now()->subDays(5),
            'type' => 'probe', 'severity' => 1, 'rule' => 'r', 'ip' => '203.0.113.7',
        ]);

        $this->artisan('security:prune', ['--days' => 1])->assertSuccessful();
        $this->assertSame(1, SiteSecurityEvent::query()->count(), 'a typo cannot wipe the table: minimum 7 days');

        $names = collect(app(Schedule::class)->events())->map(fn ($event) => $event->description)->all();
        $this->assertContains('ops-security-prune', $names);
        $this->assertContains('ops-security-collect', $names);
    }
}
