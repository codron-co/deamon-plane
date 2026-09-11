<?php

namespace Tests\Feature\Agent;

use App\Enums\Channel;
use App\Enums\OpsRole;
use App\Enums\SiteStatus;
use App\Models\AuditLog;
use App\Models\Site;
use App\Models\User;
use App\Services\Agent\ControlPlaneAgentContract;
use App\Support\ControlPlaneAgentSignature;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SiteAdminAgentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Http::preventStrayRequests();
    }

    public function test_operator_can_create_admin_and_sees_one_time_password(): void
    {
        $site = $this->siteWithSecret();

        Http::fake([
            'https://shop.example.test/internal/control/v1/admins' => Http::sequence()
                ->push([
                    'ok' => true,
                    'admins' => [
                        ['id' => 1, 'name' => 'CodRon Team', 'email' => 'support@codron.co', 'is_active' => true, 'must_change_password' => true, 'has_two_factor' => false, 'created_at' => now()->toIso8601String()],
                    ],
                ], 200)
                ->push([
                    'ok' => true,
                    'admin' => ['id' => 2, 'name' => 'Ops Admin', 'email' => 'ops@example.com', 'is_active' => true, 'must_change_password' => true, 'has_two_factor' => false, 'created_at' => now()->toIso8601String()],
                ], 200)
                ->push([
                    'ok' => true,
                    'admins' => [
                        ['id' => 1, 'name' => 'CodRon Team', 'email' => 'support@codron.co', 'is_active' => true, 'must_change_password' => true, 'has_two_factor' => false, 'created_at' => now()->toIso8601String()],
                        ['id' => 2, 'name' => 'Ops Admin', 'email' => 'ops@example.com', 'is_active' => true, 'must_change_password' => true, 'has_two_factor' => false, 'created_at' => now()->toIso8601String()],
                    ],
                ], 200),
        ]);

        $this->actingAs($this->user(OpsRole::Operator))
            ->from(route('ops.sites.show', $site))
            ->post(route('ops.sites.admins.store', $site), [
                'name' => 'Ops Admin',
                'email' => 'ops@example.com',
                'password_mode' => 'manual',
                'password' => 'PlaneReset!234',
            ])
            ->assertRedirect(route('ops.sites.show', $site).'#admins')
            ->assertSessionHas('status')
            ->assertSessionHas('admin_password_once', 'PlaneReset!234');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'site.admin.created',
            'subject_id' => $site->id,
        ]);

        $audit = AuditLog::query()->where('action', 'site.admin.created')->firstOrFail();
        $encoded = json_encode($audit->after);
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('PlaneReset!234', $encoded);

        Http::assertSent(function (Request $request) use ($site): bool {
            if ($request->method() !== 'POST' || ! str_ends_with($request->url(), '/admins')) {
                return false;
            }

            $body = $request->body();
            $header = static function (string $name) use ($request): string {
                $value = $request->header($name);

                return is_array($value) ? (string) ($value[0] ?? '') : (string) $value;
            };

            $expected = ControlPlaneAgentSignature::sign(
                (string) $site->agent_secret_encrypted,
                $header(ControlPlaneAgentContract::HEADER_TIMESTAMP),
                $header(ControlPlaneAgentContract::HEADER_NONCE),
                $body,
            );

            return $header(ControlPlaneAgentContract::HEADER_SIGNATURE) === $expected
                && str_contains($body, 'ops@example.com')
                && str_contains($body, 'PlaneReset!234');
        });
    }

    public function test_operator_cannot_deactivate_or_delete_admin(): void
    {
        $site = $this->siteWithSecret();

        Http::fake([
            'https://shop.example.test/internal/control/v1/admins*' => Http::response(['ok' => true, 'admins' => []], 200),
        ]);

        $this->actingAs($this->user(OpsRole::Operator))
            ->post(route('ops.sites.admins.deactivate', [$site, 2]))
            ->assertForbidden();

        $this->actingAs($this->user(OpsRole::Operator))
            ->delete(route('ops.sites.admins.destroy', [$site, 2]))
            ->assertForbidden();
    }

    public function test_super_admin_can_deactivate_admin(): void
    {
        $site = $this->siteWithSecret();

        Http::fake([
            'https://shop.example.test/internal/control/v1/admins/2' => Http::response([
                'ok' => true,
                'admin' => ['id' => 2, 'name' => 'Second', 'email' => 'second@example.com', 'is_active' => false, 'must_change_password' => true, 'has_two_factor' => false, 'created_at' => now()->toIso8601String()],
            ], 200),
            'https://shop.example.test/internal/control/v1/admins' => Http::response(['ok' => true, 'admins' => []], 200),
        ]);

        $this->actingAs($this->user(OpsRole::SuperAdmin))
            ->post(route('ops.sites.admins.deactivate', [$site, 2]))
            ->assertRedirect(route('ops.sites.show', $site).'#admins')
            ->assertSessionHas('status');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'site.admin.deactivated',
            'subject_id' => $site->id,
        ]);
    }

    public function test_outdated_cms_returns_clear_error(): void
    {
        $site = $this->siteWithSecret();

        Http::fake([
            'https://shop.example.test/internal/control/v1/admins' => Http::response('Not Found', 404),
        ]);

        $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.sites.show', $site))
            ->assertOk()
            ->assertSee(__('sites.admins.errors.outdated'), false);
    }

    public function test_viewer_does_not_see_admins_tab(): void
    {
        $site = $this->siteWithSecret();

        $this->actingAs($this->user(OpsRole::Viewer))
            ->get(route('ops.sites.show', $site))
            ->assertOk()
            ->assertDontSee(__('sites.admins.title'), false);
    }

    private function siteWithSecret(): Site
    {
        return Site::factory()->create([
            'name' => 'Shop',
            'slug' => 'shop',
            'primary_domain' => 'shop.example.test',
            'channel' => Channel::Main,
            'status' => SiteStatus::Active,
            'agent_base_url' => 'https://shop.example.test',
            'agent_secret_encrypted' => 'plane-admin-agent-secret',
        ]);
    }

    private function user(OpsRole $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);

        return $user;
    }
}
