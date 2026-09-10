<?php

namespace Tests\Feature\Ops;

use App\Enums\OpsRole;
use App\Models\CloudflareSetting;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CloudflareSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'cf-api-token-secret-never-show';

    private const ACCOUNT_ID = 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Http::preventStrayRequests();
    }

    public function test_index_never_renders_api_token(): void
    {
        $this->account();

        $this->actingAs($this->operator())
            ->get(route('ops.cloudflare.index'))
            ->assertOk()
            ->assertSee(__('cloudflare.title'), false)
            ->assertSee('Prod CF', false)
            ->assertDontSee(self::TOKEN, false);
    }

    public function test_show_never_renders_api_token(): void
    {
        $account = $this->account();
        $this->fakeZoneList();

        $this->actingAs($this->operator())
            ->get(route('ops.cloudflare.show', $account))
            ->assertOk()
            ->assertSee('DNS & Zones')
            ->assertSee(__('cloudflare.fields.token_saved'), false)
            ->assertDontSee(self::TOKEN, false);
    }

    public function test_operator_stores_cloudflare_token_encrypted(): void
    {
        $this->actingAs($this->operator())
            ->post(route('ops.cloudflare.store'), [
                'name' => 'Prod CF',
                'account_id' => self::ACCOUNT_ID,
                'api_token' => self::TOKEN,
                'is_enabled' => '1',
                'is_default' => '1',
            ])
            ->assertRedirect();

        $account = CloudflareSetting::query()->first();
        $this->assertNotNull($account);
        $this->assertTrue($account->is_default);
        $this->assertTrue($account->is_enabled);
        $this->assertSame(self::TOKEN, $account->api_token);
        $this->assertNotSame(self::TOKEN, DB::table('cloudflare_settings')->value('api_token'));
        $this->assertArrayNotHasKey('api_token', $account->toArray());
        $this->assertSame(self::ACCOUNT_ID, $account->account_id);

        $this->fakeZoneList();

        $this->actingAs($this->operator())
            ->get(route('ops.cloudflare.show', $account))
            ->assertOk()
            ->assertDontSee(self::TOKEN, false);
    }

    public function test_blank_token_keeps_existing_value(): void
    {
        $account = $this->account();

        $this->actingAs($this->operator())
            ->put(route('ops.cloudflare.update', $account), [
                'name' => 'Renamed CF',
                'account_id' => self::ACCOUNT_ID,
                'api_token' => '',
                'is_enabled' => '1',
                'is_default' => '1',
            ])
            ->assertRedirect();

        $account->refresh();
        $this->assertSame(self::TOKEN, $account->api_token);
        $this->assertSame('Renamed CF', $account->name);
    }

    public function test_probe_success_flashes_verified_copy(): void
    {
        $account = $this->account();
        $this->fakeProbeHappyPath();

        $this->actingAs($this->operator(['locale' => 'tr']))
            ->from(route('ops.cloudflare.show', $account))
            ->post(route('ops.cloudflare.test', $account))
            ->assertRedirect(route('ops.cloudflare.show', $account))
            ->assertSessionHas('status', 'Cloudflare bağlantısı tamam. Zone oluşturma ve DNS yazma izinleri doğrulandı.');

        $status = session('status');
        $this->assertIsString($status);
        $this->assertStringNotContainsString(self::TOKEN, $status);

        $payload = $account->fresh()->last_probe_payload;
        $this->assertIsArray($payload);
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString(self::TOKEN, $encoded);
        $this->assertNotNull($account->fresh()->last_probe_at);
    }

    public function test_probe_zone_edit_403_lists_dashboard_label(): void
    {
        $account = $this->account();

        Http::fake(function (Request $request) {
            return $this->probeResponse($request, zoneEditStatus: 403);
        });

        $this->actingAs($this->operator(['locale' => 'tr']))
            ->from(route('ops.cloudflare.show', $account))
            ->post(route('ops.cloudflare.test', $account))
            ->assertRedirect(route('ops.cloudflare.show', $account))
            ->assertSessionHas('error');

        $error = session('error');
        $this->assertIsString($error);
        $this->assertStringContainsString('Cloudflare token geçerli, ancak şu izinler eksik:', $error);
        $this->assertStringContainsString('DNS & Zones → Zone → Edit', $error);
        $this->assertStringNotContainsString(self::TOKEN, $error);
    }

    public function test_probe_dns_edit_403_lists_dashboard_label(): void
    {
        $account = $this->account();

        Http::fake(function (Request $request) {
            return $this->probeResponse($request, dnsEditStatus: 403);
        });

        $this->actingAs($this->operator(['locale' => 'tr']))
            ->from(route('ops.cloudflare.show', $account))
            ->post(route('ops.cloudflare.test', $account))
            ->assertRedirect(route('ops.cloudflare.show', $account))
            ->assertSessionHas('error');

        $error = session('error');
        $this->assertIsString($error);
        $this->assertStringContainsString('Cloudflare token geçerli, ancak şu izinler eksik:', $error);
        $this->assertStringContainsString('DNS & Zones → DNS → Edit', $error);
    }

    public function test_probe_never_posts_zones_with_a_name(): void
    {
        $account = $this->account();
        $this->fakeProbeHappyPath();

        $this->actingAs($this->operator())
            ->from(route('ops.cloudflare.show', $account))
            ->post(route('ops.cloudflare.test', $account))
            ->assertRedirect(route('ops.cloudflare.show', $account));

        Http::assertSent(function (Request $request): bool {
            if ($request->method() !== 'POST' || $request->url() !== 'https://api.cloudflare.com/client/v4/zones') {
                return false;
            }

            $body = $request->data();
            $this->assertArrayNotHasKey('name', $body);
            $this->assertSame(self::ACCOUNT_ID, $body['account']['id'] ?? null);

            return true;
        });
    }

    public function test_zero_zones_skips_dns_probes_and_warns(): void
    {
        $account = $this->account();

        Http::fake(function (Request $request) {
            return $this->probeResponse($request, zoneCount: 0);
        });

        $this->actingAs($this->operator(['locale' => 'tr']))
            ->from(route('ops.cloudflare.show', $account))
            ->post(route('ops.cloudflare.test', $account))
            ->assertRedirect(route('ops.cloudflare.show', $account))
            ->assertSessionHas('status');

        $status = session('status');
        $this->assertIsString($status);
        $this->assertStringContainsString('DNS Read/Edit henüz doğrulanamadı', $status);

        Http::assertNotSent(function (Request $request): bool {
            return str_contains($request->url(), '/dns_records');
        });
    }

    public function test_viewer_cannot_save_or_test_cloudflare(): void
    {
        $account = $this->account();
        $this->fakeZoneList();

        $viewer = User::factory()->create(['locale' => 'en']);
        $viewer->assignRole(OpsRole::Viewer->value);

        $this->actingAs($viewer)
            ->post(route('ops.cloudflare.store'), [
                'name' => 'Other',
                'account_id' => self::ACCOUNT_ID,
                'api_token' => self::TOKEN,
            ])
            ->assertForbidden();

        $this->actingAs($viewer)
            ->put(route('ops.cloudflare.update', $account), [
                'name' => 'Prod CF',
                'account_id' => self::ACCOUNT_ID,
                'api_token' => self::TOKEN,
            ])
            ->assertForbidden();

        $this->actingAs($viewer)
            ->post(route('ops.cloudflare.test', $account))
            ->assertForbidden();

        $this->actingAs($viewer)
            ->get(route('ops.cloudflare.index'))
            ->assertOk()
            ->assertDontSee(self::TOKEN, false);

        $this->actingAs($viewer)
            ->get(route('ops.cloudflare.show', $account))
            ->assertOk()
            ->assertDontSee(self::TOKEN, false)
            ->assertDontSee(__('cloudflare.danger.button'), false)
            ->assertDontSee('aria-controls="danger"', false);
    }

    public function test_index_rows_open_show_and_token_stays_blank_password(): void
    {
        $account = $this->account();
        $this->fakeZoneList();

        $this->actingAs($this->operator())
            ->get(route('ops.cloudflare.index'))
            ->assertOk()
            ->assertSee('data-href="'.route('ops.cloudflare.show', $account).'"', false)
            ->assertDontSee(self::TOKEN, false);

        $html = $this->actingAs($this->operator())
            ->get(route('ops.cloudflare.show', $account))
            ->assertOk()
            ->assertSee('role="tablist"', false)
            ->assertSee('aria-controls="overview"', false)
            ->assertSee('aria-controls="zones"', false)
            ->assertSee('aria-controls="configuration"', false)
            ->assertSee(__('cloudflare.fields.token_saved'), false)
            ->assertSee('class="site-hint"', false)
            ->assertSee('class="site-technical-card"', false)
            ->assertDontSee(self::TOKEN, false)
            ->getContent();

        $this->assertMatchesRegularExpression('/<input[^>]*name="api_token"[^>]*>/', $html);
        preg_match('/<input[^>]*name="api_token"[^>]*>/', $html, $tokenInput);
        $this->assertStringContainsString('type="password"', $tokenInput[0]);
        $this->assertStringContainsString('value=""', $tokenInput[0]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function operator(array $attributes = []): User
    {
        $operator = User::factory()->create($attributes);
        $operator->assignRole(OpsRole::Operator->value);

        return $operator;
    }

    private function account(): CloudflareSetting
    {
        return CloudflareSetting::factory()->create([
            'name' => 'Prod CF',
            'account_id' => self::ACCOUNT_ID,
            'api_token' => self::TOKEN,
            'is_enabled' => true,
            'is_default' => true,
        ]);
    }

    private function fakeZoneList(): void
    {
        Http::fake(function (Request $request) {
            return $this->probeResponse($request);
        });
    }

    private function fakeProbeHappyPath(): void
    {
        Http::fake(function (Request $request) {
            return $this->probeResponse($request);
        });
    }

    private function probeResponse(
        Request $request,
        int $zoneEditStatus = 400,
        int $dnsEditStatus = 400,
        int $zoneCount = 1,
    ): PromiseInterface {
        $url = $request->url();
        $method = $request->method();

        if ($method === 'GET' && str_ends_with($url, '/user/tokens/verify')) {
            return Http::response([
                'success' => true,
                'result' => ['id' => 'tok-1', 'status' => 'active'],
            ], 200);
        }

        if ($method === 'GET' && preg_match('#/client/v4/zones(\?|$)#', $url) === 1) {
            $zones = $zoneCount > 0
                ? [['id' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'name' => 'existing.test', 'name_servers' => ['a.ns.cloudflare.com'], 'account' => ['id' => self::ACCOUNT_ID]]]
                : [];

            return Http::response([
                'success' => true,
                'result' => $zones,
                'result_info' => ['count' => count($zones), 'total_pages' => 1],
            ], 200);
        }

        if ($method === 'POST' && $url === 'https://api.cloudflare.com/client/v4/zones') {
            if ($zoneEditStatus === 403) {
                return Http::response([
                    'success' => false,
                    'errors' => [['code' => 9109, 'message' => 'Unauthorized']],
                ], 403);
            }

            return Http::response([
                'success' => false,
                'errors' => [['code' => 1001, 'message' => 'name is a required field']],
            ], 400);
        }

        if ($method === 'GET' && str_contains($url, '/dns_records')) {
            return Http::response([
                'success' => true,
                'result' => [],
            ], 200);
        }

        if ($method === 'POST' && str_contains($url, '/dns_records')) {
            if ($dnsEditStatus === 403) {
                return Http::response([
                    'success' => false,
                    'errors' => [['code' => 9109, 'message' => 'Unauthorized']],
                ], 403);
            }

            return Http::response([
                'success' => false,
                'errors' => [['code' => 1004, 'message' => 'type is a required field']],
            ], 400);
        }

        return Http::response(['success' => false, 'errors' => [['message' => 'unexpected '.$url]]], 404);
    }
}
