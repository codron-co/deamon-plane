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

    public function test_operator_can_create_admin_via_invite_without_password_flash(): void
    {
        $site = $this->siteWithSecret();

        Http::fake([
            'https://shop.example.test/internal/control/v1/admins' => Http::sequence()
                ->push([
                    'ok' => true,
                    'admin' => ['id' => 9, 'name' => 'Invited', 'email' => 'invited@example.com', 'is_active' => true, 'must_change_password' => true, 'password_is_set' => false, 'has_two_factor' => false, 'created_at' => now()->toIso8601String()],
                ], 200)
                ->push(['ok' => true, 'admins' => []], 200),
        ]);

        $this->actingAs($this->user(OpsRole::Operator))
            ->from(route('ops.sites.show', $site))
            ->post(route('ops.sites.admins.store', $site), [
                'name' => 'Invited',
                'email' => 'invited@example.com',
                'password_mode' => 'invite',
            ])
            ->assertRedirect(route('ops.sites.show', $site).'#admins')
            ->assertSessionHas('status', __('sites.admins.flash.created_invite'))
            ->assertSessionMissing('admin_password_once');

        $audit = AuditLog::query()->where('action', 'site.admin.created')->firstOrFail();
        $this->assertSame('invite', $audit->after['password_mode'] ?? null);
        $this->assertSame('invited@example.com', $audit->after['email'] ?? null);

        Http::assertSent(function (Request $request): bool {
            if ($request->method() !== 'POST' || ! str_ends_with($request->url(), '/admins')) {
                return false;
            }

            $body = $request->data();

            return ($body['password_mode'] ?? null) === 'invite'
                && ! array_key_exists('password', $body);
        });
    }

    public function test_operator_can_resend_password_invite(): void
    {
        $site = $this->siteWithSecret();

        Http::fake([
            'https://shop.example.test/internal/control/v1/admins/9/password-invite' => Http::response([
                'ok' => true,
                'admin' => ['id' => 9, 'name' => 'Invited', 'email' => 'invited@example.com', 'is_active' => true, 'must_change_password' => true, 'password_is_set' => false, 'has_two_factor' => false, 'created_at' => now()->toIso8601String()],
            ], 200),
            'https://shop.example.test/internal/control/v1/admins' => Http::response(['ok' => true, 'admins' => []], 200),
        ]);

        $this->actingAs($this->user(OpsRole::Operator))
            ->from(route('ops.sites.show', $site))
            ->post(route('ops.sites.admins.password-invite', [$site, 9]), [
                'admin_email' => 'invited@example.com',
            ])
            ->assertRedirect(route('ops.sites.show', $site).'#admins')
            ->assertSessionHas('status', __('sites.admins.flash.password_invite_sent'))
            ->assertSessionMissing('admin_password_once');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'site.admin.password_invite_sent',
            'subject_id' => $site->id,
        ]);

        $audit = AuditLog::query()->where('action', 'site.admin.password_invite_sent')->firstOrFail();
        $this->assertSame(['admin_id' => 9, 'email' => 'invited@example.com'], $audit->after);

        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'POST'
                && str_ends_with($request->url(), '/admins/9/password-invite')
                && $request->body() === '';
        });
    }

    public function test_password_invite_on_outdated_cms_returns_clear_error(): void
    {
        $site = $this->siteWithSecret();

        Http::fake([
            'https://shop.example.test/internal/control/v1/admins/9/password-invite' => Http::response('Not Found', 404),
        ]);

        $this->actingAs($this->user(OpsRole::Operator))
            ->from(route('ops.sites.show', $site))
            ->post(route('ops.sites.admins.password-invite', [$site, 9]))
            ->assertRedirect(route('ops.sites.show', $site).'#admins')
            ->assertSessionHasErrors(['admins' => __('sites.admins.errors.outdated')]);

        $this->assertDatabaseMissing('audit_logs', ['action' => 'site.admin.password_invite_sent']);
    }

    public function test_password_invite_mail_not_configured_reaches_the_async_caller(): void
    {
        $site = $this->siteWithSecret();

        Http::fake([
            'https://shop.example.test/internal/control/v1/admins/9/password-invite' => Http::response([
                'ok' => false,
                'error' => 'mail_not_configured',
                'message' => 'Platform mail is not configured.',
            ], 422),
        ]);

        // The site page posts through fetch; before the fix this answered ok:true with an empty message.
        $this->actingAs($this->user(OpsRole::Operator))
            ->from(route('ops.sites.show', $site))
            ->postJson(route('ops.sites.admins.password-invite', [$site, 9]), [
                'admin_email' => 'invited@example.com',
            ])
            ->assertOk()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('type', 'error')
            ->assertJsonPath('message', __('sites.admins.errors.mail_not_configured'));

        $this->assertDatabaseMissing('audit_logs', ['action' => 'site.admin.password_invite_sent']);
    }

    public function test_password_invite_send_failure_carries_the_cms_reason(): void
    {
        $site = $this->siteWithSecret();

        Http::fake([
            'https://shop.example.test/internal/control/v1/admins/9/password-invite' => Http::response([
                'ok' => false,
                'error' => 'mail_send_failed',
                'message' => 'Please wait before retrying.',
            ], 422),
        ]);

        $this->actingAs($this->user(OpsRole::Operator))
            ->postJson(route('ops.sites.admins.password-invite', [$site, 9]))
            ->assertOk()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('message', __('sites.admins.errors.mail_send_failed', ['reason' => 'Please wait before retrying.']));
    }

    public function test_password_invite_success_reaches_the_async_caller(): void
    {
        $site = $this->siteWithSecret();

        Http::fake([
            'https://shop.example.test/internal/control/v1/admins/9/password-invite' => Http::response([
                'ok' => true,
                'admin' => ['id' => 9, 'name' => 'Invited', 'email' => 'invited@example.com', 'is_active' => true, 'must_change_password' => true, 'password_is_set' => false, 'has_two_factor' => false, 'created_at' => now()->toIso8601String()],
            ], 200),
        ]);

        $this->actingAs($this->user(OpsRole::Operator))
            ->postJson(route('ops.sites.admins.password-invite', [$site, 9]))
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('type', 'status')
            ->assertJsonPath('message', __('sites.admins.flash.password_invite_sent'));
    }

    public function test_admin_forms_reload_the_panel_after_success(): void
    {
        $site = $this->siteWithSecret();

        Http::fake([
            'https://shop.example.test/internal/control/v1/admins' => Http::response([
                'ok' => true,
                'admins' => [
                    ['id' => 9, 'name' => 'Invited', 'email' => 'invited@example.com', 'is_active' => true, 'must_change_password' => true, 'password_is_set' => false, 'has_two_factor' => false, 'created_at' => now()->toIso8601String()],
                ],
            ], 200),
        ]);

        $html = $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.sites.show', $site))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '#action="'.preg_quote(route('ops.sites.admins.password-invite', [$site, 9]), '#').'"[^>]*data-reload-on-success#',
            $html,
        );
        $this->assertMatchesRegularExpression('#data-admin-create[^>]*data-reload-on-success|data-reload-on-success[^>]*data-admin-create#', $html);
    }

    public function test_viewer_cannot_send_password_invite(): void
    {
        $site = $this->siteWithSecret();

        Http::fake();

        $this->actingAs($this->user(OpsRole::Viewer))
            ->post(route('ops.sites.admins.password-invite', [$site, 9]))
            ->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_admins_list_shows_send_invite_only_for_passwordless_admins(): void
    {
        $site = $this->siteWithSecret();

        Http::fake([
            'https://shop.example.test/internal/control/v1/admins' => Http::response([
                'ok' => true,
                'admins' => [
                    ['id' => 1, 'name' => 'Set', 'email' => 'set@example.com', 'is_active' => true, 'must_change_password' => false, 'password_is_set' => true, 'has_two_factor' => false, 'created_at' => now()->toIso8601String()],
                    ['id' => 9, 'name' => 'Invited', 'email' => 'invited@example.com', 'is_active' => true, 'must_change_password' => true, 'password_is_set' => false, 'has_two_factor' => false, 'created_at' => now()->toIso8601String()],
                ],
            ], 200),
        ]);

        $html = $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.sites.show', $site))
            ->assertOk()
            ->assertSee(__('sites.admins.password_not_set'), false)
            ->assertSee(__('sites.admins.password_invite'), false)
            ->getContent();

        $this->assertStringContainsString(route('ops.sites.admins.password-invite', [$site, 9]), $html);
        $this->assertStringNotContainsString(route('ops.sites.admins.password-invite', [$site, 1]), $html);
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
