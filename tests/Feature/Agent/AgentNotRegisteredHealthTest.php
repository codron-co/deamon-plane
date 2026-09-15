<?php

namespace Tests\Feature\Agent;

use App\Enums\SiteStatus;
use App\Models\Site;
use App\Services\Agent\AgentHealthReason;
use App\Services\Agent\SiteAgentClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AgentNotRegisteredHealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_html_200_means_the_cms_agent_routes_are_not_registered(): void
    {
        $site = $this->site();

        // A CMS without CONTROL_PLANE_AGENT_SECRET does not register /internal/control/v1/*,
        // so the health GET falls through to the storefront and answers its HTML with 200.
        Http::fake([
            'https://shop.example.test/internal/control/v1/health' => Http::response('<!DOCTYPE html><html><body>Avolife</body></html>', 200, ['Content-Type' => 'text/html; charset=UTF-8']),
        ]);

        $result = app(SiteAgentClient::class)->health($site);

        $this->assertFalse($result->ok);
        $this->assertSame(AgentHealthReason::AgentNotRegistered, $result->reason);
        $this->assertStringContainsString('CONTROL_PLANE_AGENT_SECRET', $result->safeMessage);
    }

    public function test_other_non_json_body_stays_an_http_error(): void
    {
        $site = $this->site();

        Http::fake([
            'https://shop.example.test/internal/control/v1/health' => Http::response('ok', 200, ['Content-Type' => 'text/plain']),
        ]);

        $this->assertSame(AgentHealthReason::HttpError, app(SiteAgentClient::class)->health($site)->reason);
    }

    public function test_reason_has_operator_copy(): void
    {
        $this->assertNotSame('sites.agent.reasons.agent_not_registered', __('sites.agent.reasons.agent_not_registered'));
    }

    private function site(): Site
    {
        return Site::factory()->withSecrets()->create([
            'status' => SiteStatus::Active,
            'primary_domain' => 'shop.example.test',
            'agent_base_url' => 'https://shop.example.test',
        ]);
    }
}
