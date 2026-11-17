<?php

namespace Tests\Feature\Sites;

use App\Enums\Channel;
use App\Enums\OpsRole;
use App\Enums\SiteStatus;
use App\Models\AuditLog;
use App\Models\McpToken;
use App\Models\Site;
use App\Models\User;
use App\Services\Agent\ControlPlaneAgentContract;
use App\Support\ControlPlaneAgentSignature;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SiteModulesTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://shop.example.test/internal/control/v1/modules';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Http::preventStrayRequests();
    }

    public function test_detail_page_has_the_modules_tab_without_calling_the_agent(): void
    {
        $site = $this->siteWithSecret();

        $this->actingAs($this->user(OpsRole::Viewer))
            ->get(route('ops.sites.show', $site))
            ->assertOk()
            ->assertSee('id="site-tab-modules"', false)
            ->assertSee(route('ops.sites.modules.panel', $site), false);

        Http::assertNothingSent();
    }

    public function test_panel_lists_modules_grouped_by_category_with_a_signed_get(): void
    {
        $site = $this->siteWithSecret();
        Http::fake([self::URL => Http::response($this->catalog(), 200)]);

        $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.sites.modules.panel', $site))
            ->assertOk()
            ->assertSeeInOrder(['İçerik', 'Blog', 'Entegrasyonlar', 'XML Dışa Aktarma'])
            ->assertSee('data-module-slug="xml-export"', false)
            ->assertSee('Kurulu değil')
            ->assertSee('Sabit')
            ->assertSee(route('ops.sites.modules.update', $site), false);

        Http::assertSent(function (Request $request) use ($site): bool {
            return $request->method() === 'GET'
                && $request->url() === self::URL
                && $this->signatureMatches($request, $site);
        });
    }

    public function test_viewer_sees_the_list_but_the_switches_are_disabled(): void
    {
        $site = $this->siteWithSecret();
        Http::fake([self::URL => Http::response($this->catalog(), 200)]);

        $this->actingAs($this->user(OpsRole::Viewer))
            ->get(route('ops.sites.modules.panel', $site))
            ->assertOk()
            ->assertSee('Modülleri değiştirmek için yazma yetkisi gerekir.');
    }

    public function test_old_cms_404_shows_the_unsupported_note(): void
    {
        $site = $this->siteWithSecret();
        Http::fake([self::URL => Http::response('Not Found', 404)]);

        $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.sites.modules.panel', $site))
            ->assertOk()
            ->assertSee('Bu site sürümü modül kataloğunu desteklemiyor');
    }

    public function test_site_without_agent_secret_never_calls_the_agent(): void
    {
        $site = $this->siteWithSecret();
        $site->forceFill(['agent_secret_encrypted' => null])->save();

        $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.sites.modules.panel', $site))
            ->assertOk()
            ->assertSee('CONTROL_PLANE_AGENT_SECRET');

        Http::assertNothingSent();
    }

    public function test_operator_turns_a_module_on_and_the_toggle_is_audited(): void
    {
        $site = $this->siteWithSecret();
        Http::fake([
            self::URL => Http::sequence()
                ->push($this->catalog(), 200)
                ->push(['ok' => true, 'slug' => 'xml-export', 'enabled' => true, 'changed' => ['catalog', 'xml-export'], 'message' => 'Modül açıldı: catalog, xml-export'], 200),
        ]);

        $this->actingAs($this->user(OpsRole::Operator))
            ->postJson(route('ops.sites.modules.update', $site), ['module' => 'xml-export', 'enabled' => true])
            ->assertOk()
            ->assertJson([
                'ok' => true,
                'module' => 'xml-export',
                'enabled' => true,
                'changed' => ['catalog', 'xml-export'],
                'message' => 'Modül açıldı: catalog, xml-export',
            ]);

        Http::assertSent(function (Request $request) use ($site): bool {
            return $request->method() === 'POST'
                && $request->url() === self::URL
                && $request->body() === '{"slug":"xml-export","enabled":true}'
                && $this->signatureMatches($request, $site);
        });

        $audit = AuditLog::query()->where('action', 'site.module.enabled')->sole();
        $this->assertSame($site->id, $audit->subject_id);
        $this->assertSame(['module' => 'xml-export', 'installed' => false, 'enabled' => false], $audit->before);
        $this->assertSame(['module' => 'xml-export', 'enabled' => true, 'changed' => ['catalog', 'xml-export']], $audit->after);
    }

    public function test_turning_a_module_off_is_audited_as_disabled(): void
    {
        $site = $this->siteWithSecret();
        Http::fake([
            self::URL => Http::sequence()
                ->push($this->catalog(), 200)
                ->push(['ok' => true, 'slug' => 'blog', 'enabled' => false, 'changed' => ['blog'], 'message' => 'Modül kapatıldı: blog'], 200),
        ]);

        $this->actingAs($this->user(OpsRole::Operator))
            ->postJson(route('ops.sites.modules.update', $site), ['module' => 'blog', 'enabled' => false])
            ->assertOk()
            ->assertJson(['ok' => true, 'enabled' => false, 'message' => 'Modül kapatıldı: blog']);

        $this->assertDatabaseHas('audit_logs', ['action' => 'site.module.disabled', 'subject_id' => $site->id]);
    }

    public function test_locked_module_cannot_be_turned_off_and_no_post_is_sent(): void
    {
        $site = $this->siteWithSecret();
        Http::fake([self::URL => Http::response($this->catalog(), 200)]);

        $this->actingAs($this->user(OpsRole::Operator))
            ->postJson(route('ops.sites.modules.update', $site), ['module' => 'pages', 'enabled' => false])
            ->assertStatus(422)
            ->assertJson(['ok' => false, 'message' => 'Bu modül sabit, kapatılamaz.']);

        Http::assertSentCount(1);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_unknown_module_is_refused(): void
    {
        $site = $this->siteWithSecret();
        Http::fake([self::URL => Http::response($this->catalog(), 200)]);

        $this->actingAs($this->user(OpsRole::Operator))
            ->postJson(route('ops.sites.modules.update', $site), ['module' => 'nope', 'enabled' => true])
            ->assertStatus(422)
            ->assertJson(['ok' => false, 'message' => 'Bu modül sitenin kataloğunda yok.']);

        Http::assertSentCount(1);
    }

    public function test_invalid_input_never_reaches_the_agent(): void
    {
        $site = $this->siteWithSecret();

        $this->actingAs($this->user(OpsRole::Operator))
            ->postJson(route('ops.sites.modules.update', $site), ['module' => '../etc', 'enabled' => 'maybe'])
            ->assertStatus(422)
            ->assertJson(['ok' => false, 'type' => 'validation_error']);

        Http::assertNothingSent();
    }

    public function test_cms_refusal_is_shown_inline_and_audited_as_failed(): void
    {
        $site = $this->siteWithSecret();
        Http::fake([
            self::URL => Http::sequence()
                ->push($this->catalog(), 200)
                ->push(['ok' => false, 'error' => 'module_change_failed', 'message' => 'xml-export modülüne bağlı modüller açık.'], 422),
        ]);

        $this->actingAs($this->user(OpsRole::Operator))
            ->postJson(route('ops.sites.modules.update', $site), ['module' => 'blog', 'enabled' => false])
            ->assertStatus(422)
            ->assertJson(['ok' => false, 'type' => 'agent_error', 'message' => 'xml-export modülüne bağlı modüller açık.']);

        $audit = AuditLog::query()->where('action', 'site.module.toggle_failed')->sole();
        $this->assertSame('module_change_failed', $audit->after['error']);
        $this->assertSame('failed', $audit->outcome());
    }

    public function test_toggle_on_an_old_cms_answers_unsupported(): void
    {
        $site = $this->siteWithSecret();
        Http::fake([self::URL => Http::response('', 404)]);

        $this->actingAs($this->user(OpsRole::Operator))
            ->postJson(route('ops.sites.modules.update', $site), ['module' => 'blog', 'enabled' => true])
            ->assertStatus(502)
            ->assertJson(['ok' => false, 'type' => 'unsupported', 'message' => 'Bu site sürümü modül kataloğunu desteklemiyor. CMS güncellenince burada görünür.']);
    }

    public function test_viewer_cannot_toggle(): void
    {
        $site = $this->siteWithSecret();

        $this->actingAs($this->user(OpsRole::Viewer))
            ->postJson(route('ops.sites.modules.update', $site), ['module' => 'blog', 'enabled' => false])
            ->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_mcp_list_site_modules_reads_the_catalog(): void
    {
        $site = $this->siteWithSecret();
        Http::fake([self::URL => Http::response($this->catalog(), 200)]);
        [, $plain] = McpToken::issue($this->user(OpsRole::Viewer), 'agent', ['read']);

        $result = $this->tool('list_site_modules', ['site' => 'shop'], $plain);

        $this->assertFalse($result['isError']);
        $this->assertSame(['xml-export', 'blog', 'pages'], array_column($result['structuredContent']['modules'], 'slug'));
        $this->assertSame($site->slug, $result['structuredContent']['site']['slug']);
    }

    public function test_mcp_set_site_module_toggles_and_audits_under_the_token_user(): void
    {
        $site = $this->siteWithSecret();
        $user = $this->user(OpsRole::Operator);
        Http::fake([
            self::URL => Http::sequence()
                ->push($this->catalog(), 200)
                ->push(['ok' => true, 'slug' => 'xml-export', 'enabled' => true, 'changed' => ['xml-export'], 'message' => 'Modül açıldı: xml-export'], 200),
        ]);
        [, $plain] = McpToken::issue($user, 'agent', ['read', 'write']);

        $result = $this->tool('set_site_module', ['site' => 'shop', 'module' => 'xml-export', 'enabled' => true], $plain);

        $this->assertFalse($result['isError']);
        $this->assertTrue($result['structuredContent']['enabled']);
        $this->assertSame(['xml-export'], $result['structuredContent']['changed']);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'site.module.enabled',
            'subject_id' => $site->id,
            'actor_user_id' => $user->id,
        ]);
    }

    public function test_mcp_set_site_module_reports_the_cms_refusal(): void
    {
        $this->siteWithSecret();
        Http::fake([self::URL => Http::response($this->catalog(), 200)]);
        [, $plain] = McpToken::issue($this->user(OpsRole::Operator), 'agent', ['read', 'write']);

        $result = $this->tool('set_site_module', ['site' => 'shop', 'module' => 'pages', 'enabled' => false], $plain);

        $this->assertTrue($result['isError']);
        $this->assertSame('validation_error', $result['structuredContent']['code']);
    }

    public function test_mcp_tool_abilities(): void
    {
        $user = $this->user(OpsRole::SuperAdmin);
        $names = fn (array $abilities): array => collect(
            $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => new \stdClass], [
                'Authorization' => 'Bearer '.McpToken::issue($user, 'agent', $abilities)[1],
            ])->json('result.tools'),
        )->pluck('name')->all();

        $read = $names(['read']);
        $this->assertContains('list_site_modules', $read);
        $this->assertNotContains('set_site_module', $read);
        $this->assertContains('set_site_module', $names(['read', 'write']));
    }

    /**
     * @return array<string, mixed>
     */
    private function catalog(): array
    {
        return [
            'ok' => true,
            'modules' => [
                ['slug' => 'xml-export', 'name' => 'XML Dışa Aktarma', 'tagline' => 'Ürün XML akışları', 'category' => 'integrations', 'installed' => false, 'enabled' => false, 'locked' => false, 'requires' => ['catalog']],
                ['slug' => 'blog', 'name' => 'Blog', 'tagline' => 'Yazılar', 'category' => 'content', 'installed' => true, 'enabled' => true, 'locked' => false, 'requires' => []],
                ['slug' => 'pages', 'name' => 'Sayfalar', 'tagline' => '', 'category' => 'content', 'installed' => true, 'enabled' => true, 'locked' => true, 'requires' => [], 'secret_field' => 'dropped'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{isError: bool, structuredContent: array<string, mixed>}
     */
    private function tool(string $name, array $arguments, string $token): array
    {
        return $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => $name, 'arguments' => $arguments],
        ], ['Authorization' => 'Bearer '.$token])->assertOk()->json('result');
    }

    private function signatureMatches(Request $request, Site $site): bool
    {
        $header = static function (string $name) use ($request): string {
            $value = $request->header($name);

            return is_array($value) ? (string) ($value[0] ?? '') : (string) $value;
        };

        return $header(ControlPlaneAgentContract::HEADER_SIGNATURE) === ControlPlaneAgentSignature::sign(
            (string) $site->agent_secret_encrypted,
            $header(ControlPlaneAgentContract::HEADER_TIMESTAMP),
            $header(ControlPlaneAgentContract::HEADER_NONCE),
            $request->body(),
        );
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
            'agent_secret_encrypted' => 'plane-module-agent-secret',
        ]);
    }

    private function user(OpsRole $role): User
    {
        $user = User::factory()->create(['locale' => 'tr']);
        $user->assignRole($role->value);

        return $user;
    }
}
