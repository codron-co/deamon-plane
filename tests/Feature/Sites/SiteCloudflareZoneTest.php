<?php

namespace Tests\Feature\Sites;

use App\Enums\Channel;
use App\Enums\OpsRole;
use App\Enums\SiteStatus;
use App\Models\CloudflareSetting;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SiteCloudflareZoneTest extends TestCase
{
    use RefreshDatabase;

    private const DEFAULT_TOKEN = 'cf-default-secret-token';

    private const FREE_TOKEN = 'cf-free-secret-token';

    private const DEFAULT_ACCOUNT = 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4';

    private const FREE_ACCOUNT = 'b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Http::preventStrayRequests();
    }

    public function test_create_form_offers_cloudflare_account_select(): void
    {
        $this->seedAccounts();

        $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.sites.create'))
            ->assertOk()
            ->assertSee('name="cloudflare_setting_id"', false)
            ->assertSee('Free CF', false)
            ->assertSee('Default CF', false)
            ->assertSee(__('sites.form.cloudflare'), false);
    }

    public function test_store_persists_selected_cloudflare_account(): void
    {
        [, $free] = $this->seedAccounts();

        $this->actingAs($this->user(OpsRole::Operator))
            ->post(route('ops.sites.store'), [
                'slug' => 'izyem',
                'name' => 'Izyem',
                'domain' => 'shop.izyem.test',
                'channel' => 'beta',
                'cloudflare_setting_id' => $free->id,
            ])
            ->assertRedirect();

        $site = Site::query()->where('slug', 'izyem')->first();
        $this->assertNotNull($site);
        $this->assertSame($free->id, $site->cloudflare_setting_id);
        $this->assertSame(SiteStatus::Draft, $site->status);
        Http::assertNothingSent();
    }

    public function test_ajax_creates_free_zone_and_returns_nameservers_without_coolify(): void
    {
        [, $free] = $this->seedAccounts();
        $site = $this->draftSite(['primary_domain' => 'izyem.test']);

        Http::fake(function (Request $request) {
            return $this->cloudflareResponse($request);
        });

        $this->actingAs($this->user(OpsRole::Operator))
            ->postJson(route('ops.sites.cloudflare.zone', $site), [
                'cloudflare_setting_id' => $free->id,
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('zone_id', 'zone-new')
            ->assertJsonPath('zone_status', 'pending')
            ->assertJsonPath('nameservers.0', 'ada.ns.cloudflare.com')
            ->assertJsonPath('nameservers.1', 'bob.ns.cloudflare.com')
            ->assertJsonMissing(['redirect']);

        $site->refresh();
        $this->assertSame($free->id, $site->cloudflare_setting_id);
        $this->assertSame('zone-new', $site->cloudflare_zone_id);
        $this->assertSame(['ada.ns.cloudflare.com', 'bob.ns.cloudflare.com'], $site->cloudflare_nameservers);
        $this->assertNotNull($site->dns_applied_at);
        $this->assertSame('izyem.test', $site->primary_domain);
        $this->assertSame(SiteStatus::Draft, $site->status);
        $this->assertNull($site->coolify_app_uuid);

        Http::assertSent(function (Request $request): bool {
            if ($request->method() !== 'POST' || $request->url() !== 'https://api.cloudflare.com/client/v4/zones') {
                return false;
            }

            $body = $request->data();

            return ($body['name'] ?? null) === 'izyem.test'
                && ($body['type'] ?? null) === 'full'
                && data_get($body, 'account.id') === self::FREE_ACCOUNT
                && $request->hasHeader('Authorization', 'Bearer '.self::FREE_TOKEN)
                && ! array_key_exists('plan', $body);
        });

        Http::assertNotSent(function (Request $request): bool {
            return str_contains($request->url(), 'coolify');
        });

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'site.cloudflare_ready',
            'subject_id' => $site->id,
        ]);
    }

    public function test_ajax_uses_apex_zone_for_customer_hostname(): void
    {
        [, $free] = $this->seedAccounts();
        $site = $this->draftSite(['primary_domain' => 'shop.customer.example']);

        Http::fake(function (Request $request) {
            return $this->cloudflareResponse($request);
        });

        $this->actingAs($this->user(OpsRole::Operator))
            ->postJson(route('ops.sites.cloudflare.zone', $site), [
                'cloudflare_setting_id' => $free->id,
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('nameservers.0', 'ada.ns.cloudflare.com');

        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'POST'
                && $request->url() === 'https://api.cloudflare.com/client/v4/zones'
                && ($request->data()['name'] ?? null) === 'customer.example';
        });

        $this->assertSame('shop.customer.example', $site->fresh()->primary_domain);
    }

    public function test_html_post_still_redirects_with_nameservers_saved(): void
    {
        [, $free] = $this->seedAccounts();
        $site = $this->draftSite(['primary_domain' => 'izyem.test']);

        Http::fake(function (Request $request) {
            return $this->cloudflareResponse($request);
        });

        $this->actingAs($this->user(OpsRole::Operator))
            ->post(route('ops.sites.cloudflare.zone', $site), [
                'cloudflare_setting_id' => $free->id,
            ])
            ->assertRedirect(route('ops.sites.show', $site))
            ->assertSessionHas('status');

        $this->assertSame(['ada.ns.cloudflare.com', 'bob.ns.cloudflare.com'], $site->fresh()->cloudflare_nameservers);
    }

    public function test_site_detail_renders_account_select_and_add_zone_step(): void
    {
        [, $free] = $this->seedAccounts();
        $site = $this->draftSite([
            'cloudflare_setting_id' => $free->id,
            'cloudflare_nameservers' => ['ada.ns.cloudflare.com', 'bob.ns.cloudflare.com'],
        ]);

        $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.sites.show', $site))
            ->assertOk()
            ->assertSee(route('ops.sites.cloudflare.zone', $site), false)
            ->assertSee('name="cloudflare_setting_id"', false)
            ->assertSee('data-cloudflare-zone', false)
            ->assertSee('ada.ns.cloudflare.com', false)
            ->assertSee('bob.ns.cloudflare.com', false)
            ->assertSee('data-copy-target', false)
            ->assertSee(__('sites.detail.add_zone'), false);
    }

    public function test_viewer_cannot_create_zone(): void
    {
        [, $free] = $this->seedAccounts();
        $site = $this->draftSite();

        $this->actingAs($this->user(OpsRole::Viewer))
            ->postJson(route('ops.sites.cloudflare.zone', $site), [
                'cloudflare_setting_id' => $free->id,
            ])
            ->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_ajax_requires_cloudflare_account(): void
    {
        $this->seedAccounts();
        $site = $this->draftSite();

        $this->actingAs($this->user(OpsRole::Operator))
            ->postJson(route('ops.sites.cloudflare.zone', $site), [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('cloudflare_setting_id');

        Http::assertNothingSent();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function draftSite(array $overrides = []): Site
    {
        return Site::factory()->create(array_merge([
            'slug' => 'izyem',
            'name' => 'Izyem',
            'primary_domain' => 'shop.izyem.example.test',
            'channel' => Channel::Beta,
            'status' => SiteStatus::Draft,
        ], $overrides));
    }

    /**
     * @return array{0: CloudflareSetting, 1: CloudflareSetting}
     */
    private function seedAccounts(): array
    {
        $default = CloudflareSetting::factory()->create([
            'name' => 'Default CF',
            'account_id' => self::DEFAULT_ACCOUNT,
            'api_token' => self::DEFAULT_TOKEN,
            'is_enabled' => true,
            'is_default' => true,
        ]);

        $free = CloudflareSetting::factory()->create([
            'name' => 'Free CF',
            'account_id' => self::FREE_ACCOUNT,
            'api_token' => self::FREE_TOKEN,
            'is_enabled' => true,
            'is_default' => false,
        ]);

        return [$default, $free];
    }

    private function user(OpsRole $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);

        return $user;
    }

    private function cloudflareResponse(Request $request): PromiseInterface
    {
        $url = $request->url();
        $method = $request->method();

        if ($method === 'GET' && preg_match('#/client/v4/zones(\?|$)#', $url) === 1) {
            return Http::response(['success' => true, 'result' => []], 200);
        }

        if ($method === 'POST' && $url === 'https://api.cloudflare.com/client/v4/zones') {
            $name = strtolower((string) ($request->data()['name'] ?? 'created.test'));

            return Http::response([
                'success' => true,
                'result' => [
                    'id' => 'zone-new',
                    'name' => $name,
                    'status' => 'pending',
                    'type' => 'full',
                    'name_servers' => ['ada.ns.cloudflare.com', 'bob.ns.cloudflare.com'],
                ],
            ], 200);
        }

        if ($method === 'GET' && str_contains($url, '/dns_records')) {
            return Http::response(['success' => true, 'result' => []], 200);
        }

        if ($method === 'POST' && str_contains($url, '/dns_records')) {
            return Http::response([
                'success' => true,
                'result' => ['id' => 'rec-1', 'type' => $request->data()['type'] ?? 'A'],
            ], 200);
        }

        if ($method === 'PUT' && str_contains($url, '/dns_records')) {
            return Http::response([
                'success' => true,
                'result' => ['id' => 'rec-1'],
            ], 200);
        }

        return Http::response(['success' => false, 'errors' => [['message' => 'unexpected '.$url]]], 404);
    }
}
