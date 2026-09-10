<?php

namespace Tests\Feature\Ops;

use App\Enums\OpsRole;
use App\Models\CloudflareSetting;
use App\Models\User;
use Database\Seeders\RoleSeeder;
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

    public function test_cloudflare_page_never_renders_api_token(): void
    {
        CloudflareSetting::factory()->create([
            'account_id' => self::ACCOUNT_ID,
            'api_token' => self::TOKEN,
        ]);

        $this->actingAs($this->operator())
            ->get(route('ops.cloudflare.show'))
            ->assertOk()
            ->assertSee(__('cloudflare.title'), false)
            ->assertSee('DNS & Zones', false)
            ->assertDontSee(self::TOKEN, false);
    }

    public function test_operator_saves_cloudflare_token_encrypted(): void
    {
        $this->actingAs($this->operator())
            ->post(route('ops.cloudflare.update'), [
                'account_id' => self::ACCOUNT_ID,
                'api_token' => self::TOKEN,
                'origin_ipv4' => '72.62.117.147',
                'mail_template_enabled' => '1',
            ])
            ->assertRedirect(route('ops.cloudflare.show'));

        $raw = DB::table('cloudflare_settings')->value('api_token');
        $this->assertNotSame(self::TOKEN, $raw);
        $this->assertSame(self::TOKEN, CloudflareSetting::current()->api_token);
        $this->assertArrayNotHasKey('api_token', CloudflareSetting::current()->toArray());
        $this->assertSame(self::ACCOUNT_ID, CloudflareSetting::current()->account_id);
        $this->assertTrue(CloudflareSetting::current()->mail_template_enabled);
        $this->assertFalse(CloudflareSetting::current()->proxied);
    }

    public function test_blank_token_keeps_existing_value(): void
    {
        CloudflareSetting::factory()->create([
            'account_id' => self::ACCOUNT_ID,
            'api_token' => self::TOKEN,
            'origin_ipv4' => '72.62.117.147',
        ]);

        $this->actingAs($this->operator())
            ->post(route('ops.cloudflare.update'), [
                'account_id' => self::ACCOUNT_ID,
                'api_token' => '',
                'origin_ipv4' => '1.2.3.4',
                'mail_template_enabled' => '0',
            ])
            ->assertRedirect(route('ops.cloudflare.show'));

        $settings = CloudflareSetting::current();
        $this->assertSame(self::TOKEN, $settings->api_token);
        $this->assertSame('1.2.3.4', $settings->origin_ipv4);
        $this->assertFalse($settings->mail_template_enabled);
    }

    public function test_probe_success_flashes_verified_copy(): void
    {
        CloudflareSetting::factory()->create([
            'account_id' => self::ACCOUNT_ID,
            'api_token' => self::TOKEN,
        ]);

        $this->fakeProbeHappyPath();

        $this->actingAs($this->operator(['locale' => 'tr']))
            ->from(route('ops.cloudflare.show'))
            ->post(route('ops.cloudflare.test'))
            ->assertRedirect(route('ops.cloudflare.show'))
            ->assertSessionHas('status', 'Cloudflare bağlantısı tamam. Zone oluşturma ve DNS yazma izinleri doğrulandı.');

        $status = session('status');
        $this->assertIsString($status);
        $this->assertStringNotContainsString(self::TOKEN, $status);

        $payload = CloudflareSetting::current()->last_probe_payload;
        $this->assertIsArray($payload);
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString(self::TOKEN, $encoded);
        $this->assertNotNull(CloudflareSetting::current()->last_probe_at);
    }

    public function test_probe_zone_edit_403_lists_dashboard_label(): void
    {
        CloudflareSetting::factory()->create([
            'account_id' => self::ACCOUNT_ID,
            'api_token' => self::TOKEN,
        ]);

        Http::fake(function (Request $request) {
            return $this->probeResponse($request, zoneEditStatus: 403);
        });

        $this->actingAs($this->operator(['locale' => 'tr']))
            ->from(route('ops.cloudflare.show'))
            ->post(route('ops.cloudflare.test'))
            ->assertRedirect(route('ops.cloudflare.show'))
            ->assertSessionHas('error');

        $error = session('error');
        $this->assertIsString($error);
        $this->assertStringContainsString('Cloudflare token geçerli, ancak şu izinler eksik:', $error);
        $this->assertStringContainsString('DNS & Zones → Zone → Edit', $error);
        $this->assertStringNotContainsString(self::TOKEN, $error);
    }

    public function test_probe_dns_edit_403_lists_dashboard_label(): void
    {
        CloudflareSetting::factory()->create([
            'account_id' => self::ACCOUNT_ID,
            'api_token' => self::TOKEN,
        ]);

        Http::fake(function (Request $request) {
            return $this->probeResponse($request, dnsEditStatus: 403);
        });

        $this->actingAs($this->operator(['locale' => 'tr']))
            ->from(route('ops.cloudflare.show'))
            ->post(route('ops.cloudflare.test'))
            ->assertRedirect(route('ops.cloudflare.show'))
            ->assertSessionHas('error');

        $error = session('error');
        $this->assertIsString($error);
        $this->assertStringContainsString('Cloudflare token geçerli, ancak şu izinler eksik:', $error);
        $this->assertStringContainsString('DNS & Zones → DNS → Edit', $error);
    }

    public function test_probe_never_posts_zones_with_a_name(): void
    {
        CloudflareSetting::factory()->create([
            'account_id' => self::ACCOUNT_ID,
            'api_token' => self::TOKEN,
        ]);

        $this->fakeProbeHappyPath();

        $this->actingAs($this->operator())
            ->from(route('ops.cloudflare.show'))
            ->post(route('ops.cloudflare.test'))
            ->assertRedirect(route('ops.cloudflare.show'));

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
        CloudflareSetting::factory()->create([
            'account_id' => self::ACCOUNT_ID,
            'api_token' => self::TOKEN,
        ]);

        Http::fake(function (Request $request) {
            return $this->probeResponse($request, zoneCount: 0);
        });

        $this->actingAs($this->operator(['locale' => 'tr']))
            ->from(route('ops.cloudflare.show'))
            ->post(route('ops.cloudflare.test'))
            ->assertRedirect(route('ops.cloudflare.show'))
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
        $viewer = User::factory()->create(['locale' => 'en']);
        $viewer->assignRole(OpsRole::Viewer->value);

        $this->actingAs($viewer)
            ->post(route('ops.cloudflare.update'), [
                'account_id' => self::ACCOUNT_ID,
                'api_token' => self::TOKEN,
            ])
            ->assertForbidden();

        $this->actingAs($viewer)
            ->post(route('ops.cloudflare.test'))
            ->assertForbidden();

        $this->actingAs($viewer)
            ->get(route('ops.cloudflare.show'))
            ->assertOk()
            ->assertDontSee(self::TOKEN, false);
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
    ): \GuzzleHttp\Promise\PromiseInterface {
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
                ? [['id' => 'zone-1', 'name' => 'existing.test', 'name_servers' => ['a.ns.cloudflare.com']]]
                : [];

            return Http::response([
                'success' => true,
                'result' => $zones,
                'result_info' => ['count' => count($zones)],
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
