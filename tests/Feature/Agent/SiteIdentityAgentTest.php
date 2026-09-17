<?php

namespace Tests\Feature\Agent;

use App\Enums\Channel;
use App\Enums\OpsRole;
use App\Enums\SiteStatus;
use App\Models\Site;
use App\Models\User;
use App\Services\Agent\SiteHealthChecker;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SiteIdentityAgentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Http::preventStrayRequests();
    }

    public function test_health_poll_pushes_planes_name_when_the_cms_reports_a_different_one(): void
    {
        $site = $this->activeSite(['name' => 'Basut Silo']);

        Http::fake([
            'https://shop.izyem.example.test/internal/control/v1/health' => Http::response([
                'deamon_version' => '1.2.27',
                'queue_ok' => true,
                'site_status' => 'published',
                'site_name' => 'Curtis',
            ], 200),
            'https://shop.izyem.example.test/internal/control/v1/site/identity' => Http::response([
                'ok' => true,
                'site_name' => 'Basut Silo',
                'changed' => true,
            ], 200),
        ]);

        app(SiteHealthChecker::class)->check($site);

        Http::assertSent(function (Request $request): bool {
            return str_ends_with($request->url(), '/internal/control/v1/site/identity')
                && $request->method() === 'POST'
                && $request->body() === '{"name":"Basut Silo"}';
        });

        $this->assertSame('Curtis', $site->fresh()->last_health_payload['site_name']);
        $this->assertDatabaseHas('audit_logs', [
            'subject_id' => $site->id,
            'action' => 'site.name_pushed',
        ]);
    }

    public function test_health_poll_leaves_a_cms_alone_when_names_match_or_it_reports_none(): void
    {
        $site = $this->activeSite(['name' => 'Basut Silo']);

        Http::fake([
            'https://shop.izyem.example.test/internal/control/v1/health' => Http::sequence()
                ->push(['deamon_version' => '1.2.27', 'queue_ok' => true, 'site_name' => 'Basut Silo'], 200)
                ->push(['deamon_version' => '1.2.20', 'queue_ok' => true], 200),
        ]);

        app(SiteHealthChecker::class)->check($site);
        app(SiteHealthChecker::class)->check($site);

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/site/identity'));
    }

    public function test_renaming_a_site_pushes_the_name_and_reports_an_old_cms_softly(): void
    {
        $site = $this->activeSite(['name' => 'Izyem']);

        Http::fake([
            'https://shop.izyem.example.test/internal/control/v1/site/identity' => Http::response([
                'error' => 'not_found',
            ], 404),
        ]);

        $this->actingAs($this->user(OpsRole::Operator))
            ->put(route('ops.sites.update', $site), $this->updatePayload($site, ['name' => 'Izyem Market']))
            ->assertRedirect(route('ops.sites.show', $site))
            ->assertSessionHas('status', function (string $status): bool {
                return str_contains($status, '1.2.27');
            });

        $this->assertSame('Izyem Market', $site->fresh()->name);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/site/identity'));
        $this->assertDatabaseMissing('audit_logs', ['subject_id' => $site->id, 'action' => 'site.name_pushed']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function updatePayload(Site $site, array $overrides = []): array
    {
        return array_merge([
            'name' => $site->name,
            'slug' => $site->slug,
            'domain' => $site->primary_domain,
            'channel' => $site->channel->value,
            'coolify_target' => '',
            'notes' => null,
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function activeSite(array $overrides = []): Site
    {
        return Site::factory()->withSecrets()->create(array_merge([
            'slug' => 'izyem',
            'name' => 'Izyem',
            'primary_domain' => 'shop.izyem.example.test',
            'agent_base_url' => 'https://shop.izyem.example.test',
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
