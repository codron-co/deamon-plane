<?php

namespace Tests\Feature\Ops;

use App\Enums\OpsRole;
use App\Models\CloudflareDnsDefault;
use App\Models\CloudflareSetting;
use App\Models\User;
use App\Services\Cloudflare\CloudflareDnsTemplate;
use Database\Seeders\RoleSeeder;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CloudflareDnsOpsTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'cf-api-token-secret-never-show';

    private const ACCOUNT_ID = 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4';

    private const ZONE_ID = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private const RECORD_ID = 'cccccccccccccccccccccccccccccccc';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Http::preventStrayRequests();
    }

    public function test_defaults_page_lists_builtin_seed(): void
    {
        $this->actingAs($this->operator())
            ->get(route('ops.cloudflare.defaults'))
            ->assertOk()
            ->assertSee(__('cloudflare.defaults.title'), false)
            ->assertSee('72.62.117.147', false)
            ->assertDontSee(self::TOKEN, false);

        $this->assertGreaterThan(0, CloudflareDnsDefault::query()->count());
    }

    public function test_operator_adds_updates_and_deletes_default_record(): void
    {
        $this->actingAs($this->operator())
            ->post(route('ops.cloudflare.defaults.store'), [
                'type' => 'A',
                'name' => 'blog',
                'content' => '1.2.3.4',
                'ttl' => 1,
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $record = CloudflareDnsDefault::query()->where('name', 'blog')->first();
        $this->assertNotNull($record);
        $this->assertSame('A', $record->type);
        $this->assertSame('1.2.3.4', $record->content);

        $this->actingAs($this->operator())
            ->put(route('ops.cloudflare.defaults.update', $record), [
                'type' => 'A',
                'name' => 'blog',
                'content' => '5.6.7.8',
                'ttl' => 1,
            ])
            ->assertRedirect();

        $this->assertSame('5.6.7.8', $record->fresh()->content);

        $this->actingAs($this->operator())
            ->delete(route('ops.cloudflare.defaults.destroy', $record))
            ->assertRedirect();

        $this->assertDatabaseMissing('cloudflare_dns_defaults', ['id' => $record->id]);
    }

    public function test_reset_restores_builtin_template(): void
    {
        CloudflareDnsDefault::query()->delete();
        CloudflareDnsDefault::factory()->create([
            'type' => 'TXT',
            'name' => 'custom',
            'content' => 'keep-me-not',
            'sort_order' => 0,
        ]);

        $this->actingAs($this->operator())
            ->post(route('ops.cloudflare.defaults.reset'))
            ->assertRedirect();

        $this->assertDatabaseMissing('cloudflare_dns_defaults', ['name' => 'custom']);
        $this->assertSame(count(CloudflareDnsTemplate::builtin()), CloudflareDnsDefault::query()->count());
        $this->assertTrue(CloudflareDnsDefault::query()->where('name', '@')->where('type', 'A')->exists());
    }

    public function test_zone_show_404_when_account_does_not_own_zone(): void
    {
        $account = $this->account();

        Http::fake(function (Request $request) {
            if ($request->method() === 'GET' && str_contains($request->url(), '/zones/'.self::ZONE_ID) && ! str_contains($request->url(), 'dns_records')) {
                return Http::response([
                    'success' => true,
                    'result' => [
                        'id' => self::ZONE_ID,
                        'name' => 'example.com',
                        'account' => ['id' => 'ffffffffffffffffffffffffffffffff'],
                    ],
                ], 200);
            }

            return Http::response(['success' => false, 'errors' => [['message' => 'unexpected '.$request->url()]]], 404);
        });

        $this->actingAs($this->operator())
            ->get(route('ops.cloudflare.zones.show', ['account' => $account, 'zone' => self::ZONE_ID]))
            ->assertNotFound();
    }

    public function test_invalid_zone_id_is_not_found(): void
    {
        $account = $this->account();

        $this->actingAs($this->operator())
            ->get(route('ops.cloudflare.zones.show', ['account' => $account, 'zone' => 'zone-1']))
            ->assertNotFound();
    }

    public function test_operator_creates_updates_and_deletes_dns_record(): void
    {
        $account = $this->account();
        $created = false;

        Http::fake(function (Request $request) use (&$created) {
            $url = $request->url();
            $method = $request->method();

            if ($method === 'GET' && str_contains($url, '/zones/'.self::ZONE_ID) && ! str_contains($url, 'dns_records')) {
                return $this->zoneEnvelope();
            }

            if ($method === 'GET' && str_contains($url, '/dns_records')) {
                $rows = $created
                    ? [[
                        'id' => self::RECORD_ID,
                        'type' => 'A',
                        'name' => 'blog.example.com',
                        'content' => $created === 'updated' ? '9.9.9.9' : '1.2.3.4',
                        'ttl' => 1,
                    ]]
                    : [];

                return Http::response([
                    'success' => true,
                    'result' => $rows,
                    'result_info' => ['total_pages' => 1],
                ], 200);
            }

            if ($method === 'POST' && str_contains($url, '/dns_records')) {
                $created = true;

                return Http::response([
                    'success' => true,
                    'result' => ['id' => self::RECORD_ID, 'type' => 'A', 'name' => 'blog.example.com'],
                ], 200);
            }

            if ($method === 'PUT' && str_contains($url, '/dns_records/'.self::RECORD_ID)) {
                $created = 'updated';

                return Http::response([
                    'success' => true,
                    'result' => ['id' => self::RECORD_ID, 'type' => 'A', 'content' => '9.9.9.9'],
                ], 200);
            }

            if ($method === 'DELETE' && str_contains($url, '/dns_records/'.self::RECORD_ID)) {
                $created = false;

                return Http::response(['success' => true, 'result' => []], 200);
            }

            return Http::response(['success' => false, 'errors' => [['message' => 'unexpected '.$url]]], 404);
        });

        $this->actingAs($this->operator())
            ->post(route('ops.cloudflare.zones.dns.store', ['account' => $account, 'zone' => self::ZONE_ID]), [
                'type' => 'A',
                'name' => 'blog',
                'content' => '1.2.3.4',
                'ttl' => 1,
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->actingAs($this->operator())
            ->put(route('ops.cloudflare.zones.dns.update', ['account' => $account, 'zone' => self::ZONE_ID, 'record' => self::RECORD_ID]), [
                'type' => 'A',
                'name' => 'blog',
                'content' => '9.9.9.9',
                'ttl' => 1,
            ])
            ->assertRedirect();

        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'PUT'
                && str_contains($request->url(), '/dns_records/'.self::RECORD_ID)
                && ($request->data()['content'] ?? null) === '9.9.9.9';
        });

        $this->actingAs($this->operator())
            ->delete(route('ops.cloudflare.zones.dns.destroy', ['account' => $account, 'zone' => self::ZONE_ID, 'record' => self::RECORD_ID]))
            ->assertRedirect();

        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'DELETE'
                && str_contains($request->url(), '/dns_records/'.self::RECORD_ID);
        });
    }

    public function test_apply_defaults_puts_existing_a_record_with_new_ip(): void
    {
        $account = $this->account();

        Http::fake(function (Request $request) {
            $url = $request->url();
            $method = $request->method();

            if ($method === 'GET' && str_contains($url, '/zones/'.self::ZONE_ID) && ! str_contains($url, 'dns_records')) {
                return $this->zoneEnvelope();
            }

            if ($method === 'GET' && str_contains($url, '/dns_records')) {
                $query = $request->data();
                if (($query['type'] ?? null) === 'A' && ($query['name'] ?? null) === 'example.com') {
                    return Http::response([
                        'success' => true,
                        'result' => [[
                            'id' => self::RECORD_ID,
                            'type' => 'A',
                            'name' => 'example.com',
                            'content' => '1.1.1.1',
                            'ttl' => 1,
                        ]],
                    ], 200);
                }

                return Http::response(['success' => true, 'result' => []], 200);
            }

            if ($method === 'PUT' && str_contains($url, '/dns_records/'.self::RECORD_ID)) {
                return Http::response([
                    'success' => true,
                    'result' => ['id' => self::RECORD_ID, 'type' => 'A', 'content' => '72.62.117.147'],
                ], 200);
            }

            if ($method === 'POST' && str_contains($url, '/dns_records')) {
                return Http::response([
                    'success' => true,
                    'result' => ['id' => 'dddddddddddddddddddddddddddddddd', 'type' => $request->data()['type'] ?? 'A'],
                ], 200);
            }

            return Http::response(['success' => false, 'errors' => [['message' => 'unexpected '.$url]]], 404);
        });

        $this->actingAs($this->operator())
            ->post(route('ops.cloudflare.zones.apply', ['account' => $account, 'zone' => self::ZONE_ID]))
            ->assertRedirect()
            ->assertSessionHas('status');

        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'PUT'
                && str_contains($request->url(), '/dns_records/'.self::RECORD_ID)
                && ($request->data()['content'] ?? null) === '72.62.117.147'
                && ($request->data()['proxied'] ?? null) === false;
        });
    }

    public function test_viewer_cannot_mutate_dns_defaults(): void
    {
        $viewer = User::factory()->create();
        $viewer->assignRole(OpsRole::Viewer->value);

        $this->actingAs($viewer)
            ->post(route('ops.cloudflare.defaults.store'), [
                'type' => 'A',
                'name' => 'blog',
                'content' => '1.2.3.4',
            ])
            ->assertForbidden();

        $this->actingAs($viewer)
            ->get(route('ops.cloudflare.defaults'))
            ->assertOk();
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

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function operator(array $attributes = []): User
    {
        $operator = User::factory()->create($attributes);
        $operator->assignRole(OpsRole::Operator->value);

        return $operator;
    }

    private function zoneEnvelope(): PromiseInterface
    {
        return Http::response([
            'success' => true,
            'result' => [
                'id' => self::ZONE_ID,
                'name' => 'example.com',
                'status' => 'active',
                'name_servers' => ['ada.ns.cloudflare.com', 'bob.ns.cloudflare.com'],
                'account' => ['id' => self::ACCOUNT_ID],
            ],
        ], 200);
    }
}
