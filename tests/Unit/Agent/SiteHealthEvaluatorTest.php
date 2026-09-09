<?php

namespace Tests\Unit\Agent;

use App\Enums\SiteStatus;
use App\Models\Site;
use App\Services\Agent\AgentHealthReason;
use App\Services\Agent\AgentHealthStatus;
use App\Services\Agent\SiteHealthEvaluator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteHealthEvaluatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_error_status_counts_as_unhealthy(): void
    {
        $site = Site::factory()->create(['status' => SiteStatus::Error]);

        $this->assertTrue((new SiteHealthEvaluator)->countsAsUnhealthy($site));
    }

    public function test_timeout_and_stale_count_as_unhealthy(): void
    {
        $evaluator = new SiteHealthEvaluator;

        $timeout = Site::factory()->withSecrets()->create([
            'status' => SiteStatus::Active,
            'last_health_at' => now(),
            'last_health_payload' => [
                'ok' => false,
                'status' => AgentHealthStatus::Unhealthy,
                'reason' => AgentHealthReason::Timeout,
            ],
        ]);
        $this->assertTrue($evaluator->countsAsUnhealthy($timeout));

        $stale = Site::factory()->withSecrets()->create([
            'status' => SiteStatus::Active,
            'last_health_at' => now()->subHours(2),
            'last_health_payload' => [
                'ok' => true,
                'status' => AgentHealthStatus::Ok,
                'queue_ok' => true,
                'deamon_version' => '1.2.0',
            ],
        ]);
        $this->assertTrue($evaluator->isStale($stale));
        $this->assertTrue($evaluator->countsAsUnhealthy($stale));
        $this->assertSame(AgentHealthStatus::Stale, $evaluator->displayStatus($stale));
    }

    public function test_needs_secret_does_not_count_as_unhealthy(): void
    {
        $site = Site::factory()->create([
            'status' => SiteStatus::Active,
            'last_health_at' => now()->subHours(2),
            'last_health_payload' => [
                'ok' => false,
                'status' => AgentHealthStatus::NeedsSecret,
                'reason' => AgentHealthReason::NeedsSecret,
            ],
        ]);

        $this->assertFalse((new SiteHealthEvaluator)->countsAsUnhealthy($site));
    }
}
