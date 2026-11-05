<?php

namespace Tests\Feature\Sites;

use App\Enums\Channel;
use App\Enums\OpsRole;
use App\Enums\SiteStatus;
use App\Models\McpToken;
use App\Models\Site;
use App\Models\User;
use App\Services\Agent\AgentHealthReason;
use App\Services\Agent\AgentHealthResult;
use App\Services\Agent\SiteAgentClient;
use App\Services\Agent\SiteHealthChecker;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * E-ticaret: the CMS health `commerce` key is kept, turned into a state
 * (shop / catalog / none / unknown) and shown as a badge, a list filter and
 * an MCP list_sites filter + field.
 */
class SiteCommerceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Http::preventStrayRequests();
    }

    public function test_health_poll_keeps_the_commerce_key(): void
    {
        $site = $this->agentSite();
        Http::fake([
            '*' => Http::response([
                'deamon_version' => '1.2.96',
                'queue_ok' => true,
                'commerce' => ['module_enabled' => true, 'checkout_ready' => true, 'priced_products' => 25],
            ], 200),
        ]);

        app(SiteHealthChecker::class)->check($site);
        $site->refresh();

        $this->assertSame(['module_enabled' => true, 'checkout_ready' => true, 'priced_products' => 25], $site->last_health_payload['commerce']);
        $this->assertTrue($site->isEcommerce());
        $this->assertSame('shop', $site->commerceState());
    }

    public function test_a_failed_poll_keeps_the_last_known_commerce_state(): void
    {
        $site = $this->agentSite(['last_health_payload' => ['ok' => true, 'commerce' => ['module_enabled' => true, 'checkout_ready' => false, 'priced_products' => 3]]]);
        $this->mock(SiteAgentClient::class, function ($mock): void {
            $mock->shouldReceive('health')->once()->andReturn(AgentHealthResult::failure(AgentHealthReason::Timeout, 'Agent health timed out.'));
        });

        app(SiteHealthChecker::class)->check($site);

        $this->assertSame('shop', $site->refresh()->commerceState());
    }

    public function test_malformed_commerce_payload_is_normalised(): void
    {
        $this->assertNull(AgentHealthResult::fromCmsPayload(['queue_ok' => true], 200)->summary['commerce']);
        $this->assertSame(
            ['module_enabled' => false, 'checkout_ready' => false, 'priced_products' => null],
            AgentHealthResult::fromCmsPayload(['queue_ok' => true, 'commerce' => ['module_enabled' => 'yes', 'priced_products' => -1]], 200)->summary['commerce'],
        );
    }

    public function test_state_tells_shops_from_catalogue_sites(): void
    {
        $this->assertSame('shop', $this->siteWith(['module_enabled' => true, 'checkout_ready' => false, 'priced_products' => 1])->commerceState());
        $this->assertSame('catalog', $this->siteWith(['module_enabled' => true, 'checkout_ready' => false, 'priced_products' => 0])->commerceState());
        $this->assertSame('none', $this->siteWith(['module_enabled' => false, 'checkout_ready' => false, 'priced_products' => null])->commerceState());
        $this->assertSame('unknown', $this->siteWith(null)->commerceState());
    }

    public function test_list_filter_and_badge(): void
    {
        $shop = $this->siteWith(['module_enabled' => true, 'checkout_ready' => true, 'priced_products' => 25], 'Magaza');
        $catalog = $this->siteWith(['module_enabled' => true, 'checkout_ready' => false, 'priced_products' => 0], 'Zeytinlik');
        $this->siteWith(null, 'Eski');

        $this->assertSame([$shop->id], Site::query()->matchingListFilters(commerce: 'shop')->pluck('id')->all());
        $this->assertSame([$catalog->id], Site::query()->matchingListFilters(commerce: 'catalog')->pluck('id')->all());
        $this->assertSame(3, Site::query()->matchingListFilters(commerce: 'bogus')->count());

        $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.sites', ['commerce' => 'shop']))
            ->assertOk()
            ->assertSee('Magaza')
            ->assertDontSee('Zeytinlik')
            ->assertSee(route('ops.sites', ['commerce' => 'shop']), false)
            ->assertSee(__('sites.commerce_badge_title', ['count' => 25]));
    }

    public function test_mcp_list_sites_filters_and_reports_commerce(): void
    {
        $this->siteWith(['module_enabled' => true, 'checkout_ready' => true, 'priced_products' => 25], 'Magaza', 'magaza');
        $this->siteWith(null, 'Eski', 'eski');
        [, $plain] = McpToken::issue($this->user(OpsRole::Viewer), 'agent', ['read']);

        $result = $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'list_sites', 'arguments' => ['commerce' => 'shop']],
        ], ['Authorization' => 'Bearer '.$plain])->assertOk()->json('result.structuredContent.sites');

        $this->assertSame(['magaza'], array_column($result, 'slug'));
        $this->assertSame('shop', $result[0]['commerce']);
    }

    /**
     * @param  array<string, mixed>|null  $commerce
     */
    private function siteWith(?array $commerce, string $name = 'Site', ?string $slug = null): Site
    {
        $payload = ['ok' => true];
        if ($commerce !== null) {
            $payload['commerce'] = $commerce;
        }

        return Site::factory()->create(array_filter([
            'name' => $name,
            'slug' => $slug,
            'last_health_at' => now(),
            'last_health_payload' => $payload,
        ], static fn (mixed $value): bool => $value !== null));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function agentSite(array $overrides = []): Site
    {
        return Site::factory()->withSecrets()->create(array_merge([
            'slug' => 'magaza',
            'name' => 'Magaza',
            'primary_domain' => 'magaza.example.test',
            'agent_base_url' => 'https://magaza.example.test',
            'channel' => Channel::Main,
            'status' => SiteStatus::Active,
            'coolify_app_uuid' => 'coolify-app-1',
        ], $overrides));
    }

    private function user(OpsRole $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);

        return $user;
    }
}
