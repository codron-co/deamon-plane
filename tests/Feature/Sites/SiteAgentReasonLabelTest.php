<?php

namespace Tests\Feature\Sites;

use App\Enums\OpsRole;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteAgentReasonLabelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_agent_badge_shows_operator_copy_instead_of_the_reason_code(): void
    {
        $html = $this->detailWithReason('timeout');

        $this->assertStringContainsString(' · '.__('sites.agent.reasons.timeout'), $html);
        $this->assertStringNotContainsString(' · timeout', $html);
    }

    public function test_unknown_reason_code_falls_back_to_generic_copy(): void
    {
        $html = $this->detailWithReason('socket_melted');

        $this->assertStringContainsString(' · '.__('sites.agent.reasons.unknown'), $html);
        $this->assertStringNotContainsString('socket_melted', $html);
    }

    private function detailWithReason(string $reason): string
    {
        $site = Site::factory()->withSecrets()->create([
            'coolify_app_uuid' => null,
            'last_health_at' => now(),
            'last_health_payload' => ['ok' => false, 'status' => 'unhealthy', 'reason' => $reason],
        ]);

        $user = User::factory()->create();
        $user->assignRole(OpsRole::Operator->value);

        return (string) $this->actingAs($user)->get(route('ops.sites.show', $site))->assertOk()->getContent();
    }
}
