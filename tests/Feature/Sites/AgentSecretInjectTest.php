<?php

namespace Tests\Feature\Sites;

use App\Enums\OpsRole;
use App\Enums\SiteStatus;
use App\Models\CoolifyConnection;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AgentSecretInjectTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Http::preventStrayRequests();
    }

    public function test_operator_generates_and_injects_secret_without_rendering_it(): void
    {
        CoolifyConnection::factory()->create([
            'base_url' => 'https://coolify.test',
            'api_token' => 'test-coolify-token',
            'is_default' => true,
        ]);

        Http::fake([
            'https://coolify.test/api/v1/applications/coolify-app-1/envs/bulk' => Http::response([], 200),
        ]);

        $site = Site::factory()->create([
            'status' => SiteStatus::Active,
            'coolify_app_uuid' => 'coolify-app-1',
            'primary_domain' => 'shop.example.test',
        ]);

        $this->assertFalse($site->hasAgentSecret());

        $this->actingAs($this->user(OpsRole::Operator))
            ->post(route('ops.sites.agent-secret', $site))
            ->assertRedirect(route('ops.sites.show', $site))
            ->assertSessionHas('status');

        $site->refresh();
        $this->assertTrue($site->hasAgentSecret());
        $secret = (string) $site->agent_secret_encrypted;
        $this->assertNotSame('', $secret);

        Http::assertSent(function (Request $request) use ($secret): bool {
            if ($request->method() !== 'PATCH' || ! str_ends_with($request->url(), '/envs/bulk')) {
                return false;
            }

            $pairs = $request->data()['data'] ?? [];
            $row = collect($pairs)->firstWhere('key', 'CONTROL_PLANE_AGENT_SECRET');

            return is_array($row) && ($row['value'] ?? null) === $secret;
        });

        $html = $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.sites.show', $site))
            ->assertOk()
            ->assertSee('Generate & inject secret')
            ->getContent();

        $this->assertStringNotContainsString($secret, $html);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'site.agent_secret_injected',
            'subject_id' => $site->id,
        ]);
    }

    public function test_viewer_cannot_inject_agent_secret(): void
    {
        $site = Site::factory()->create([
            'status' => SiteStatus::Active,
            'coolify_app_uuid' => 'coolify-app-1',
        ]);

        $this->actingAs($this->user(OpsRole::Viewer))
            ->post(route('ops.sites.agent-secret', $site))
            ->assertForbidden();
    }

    private function user(OpsRole $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);

        return $user;
    }
}
