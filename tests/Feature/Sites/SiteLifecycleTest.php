<?php

namespace Tests\Feature\Sites;

use App\Enums\OpsRole;
use App\Enums\SiteStatus;
use App\Models\AuditLog;
use App\Models\CloudflareSetting;
use App\Models\CoolifyConnection;
use App\Models\OpsBackgroundJob;
use App\Models\Site;
use App\Models\User;
use App\Services\Ops\OpsJobRunner;
use App\Services\Sites\SiteLanding;
use App\Services\Sites\SiteLifecycle;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SiteLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'plane-lifecycle-token';

    private const APP = 'life-app-1';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Http::preventStrayRequests();
    }

    public function test_deactivate_stops_coolify_and_sets_stopped(): void
    {
        $site = $this->site(['status' => SiteStatus::Active]);

        Http::fake([
            'https://coolify.example/api/v1/applications/'.self::APP.'/stop' => Http::response(['message' => 'Stopping'], 200),
        ]);

        $this->actingAs($this->operator())
            ->from(route('ops.sites.show', $site))
            ->post(route('ops.sites.deactivate', $site))
            ->assertRedirect()
            ->assertSessionHas('status', __('sites.flash.deactivated'));

        $this->assertSame(SiteStatus::Stopped, $site->fresh()->status);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'site.deactivated',
            'subject_id' => $site->id,
        ]);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://coolify.example/api/v1/applications/'.self::APP.'/stop');
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE');
    }

    public function test_activate_starts_coolify_and_sets_active(): void
    {
        $site = $this->site(['status' => SiteStatus::Stopped]);

        Http::fake([
            'https://coolify.example/api/v1/applications/'.self::APP.'/start' => Http::response(['message' => 'Starting'], 200),
        ]);

        $this->actingAs($this->operator())
            ->from(route('ops.sites.show', $site))
            ->post(route('ops.sites.activate', $site))
            ->assertRedirect()
            ->assertSessionHas('status', __('sites.flash.activated'));

        $this->assertSame(SiteStatus::Active, $site->fresh()->status);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://coolify.example/api/v1/applications/'.self::APP.'/start');
    }

    public function test_show_toggles_activate_and_deactivate_by_status(): void
    {
        $active = $this->site(['status' => SiteStatus::Active, 'name' => 'Running']);
        $stopped = $this->site([
            'status' => SiteStatus::Stopped,
            'name' => 'Paused',
            'slug' => 'paused-site',
            'primary_domain' => 'paused.example.test',
            'coolify_app_uuid' => 'life-app-2',
        ]);

        $this->actingAs($this->operator())
            ->get(route('ops.sites.show', $active))
            ->assertOk()
            ->assertSee(route('ops.sites.deactivate', $active), false)
            ->assertDontSee(route('ops.sites.activate', $active), false);

        $this->actingAs($this->operator())
            ->get(route('ops.sites.show', $stopped))
            ->assertOk()
            ->assertSee(route('ops.sites.activate', $stopped), false)
            ->assertDontSee(route('ops.sites.deactivate', $stopped), false);
    }

    public function test_purge_deletes_coolify_with_volumes_then_force_deletes_plane(): void
    {
        $site = $this->site(['status' => SiteStatus::Active]);
        $id = $site->id;

        Http::fake([
            'https://coolify.example/api/v1/applications/'.self::APP.'*' => Http::response('', 200),
        ]);

        $this->actingAs($this->operator())
            ->delete(route('ops.sites.purge', $site))
            ->assertRedirect(route('ops.sites'))
            ->assertSessionHas('status', __('sites.flash.purged'));

        $this->assertDatabaseMissing('sites', ['id' => $id]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'site.purged',
            'subject_id' => $id,
        ]);
        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'DELETE'
                && str_contains($request->url(), '/applications/'.self::APP)
                && str_contains($request->url(), 'delete_volumes=true');
        });
    }

    public function test_purge_releases_cloudflare_records_of_every_host_and_the_preview(): void
    {
        $this->seedCloudflare();
        $site = $this->site([
            'status' => SiteStatus::Active,
            'primary_domain' => 'shop.gone.test',
            'temporary_domain' => 'amber-harbor.codron.co',
        ]);
        $site->domains()->createMany([
            ['domain' => 'shop.gone.test', 'is_primary' => true],
            ['domain' => 'www.shop.gone.test', 'is_www' => true],
        ]);
        $id = $site->id;

        Http::fake(function (Request $request) {
            $url = $request->url();
            if (str_contains($url, 'coolify.example')) {
                return Http::response('', 200);
            }

            if ($request->method() === 'GET' && preg_match('#/client/v4/zones(\?|$)#', $url) === 1) {
                $zones = [
                    'gone.test' => ['id' => 'zone-gone', 'name' => 'gone.test', 'status' => 'active'],
                    'codron.co' => ['id' => 'zone-codron', 'name' => 'codron.co', 'status' => 'active'],
                ];
                $name = strtolower((string) ($request->data()['name'] ?? ''));

                return Http::response(['success' => true, 'result' => isset($zones[$name]) ? [$zones[$name]] : []], 200);
            }

            if ($request->method() === 'GET' && str_contains($url, '/dns_records')) {
                $name = (string) ($request->data()['name'] ?? '');

                return Http::response(['success' => true, 'result' => [['id' => 'rec-'.$name, 'type' => 'A', 'name' => $name, 'content' => '72.62.117.147']]], 200);
            }

            if ($request->method() === 'DELETE' && str_contains($url, '/dns_records/')) {
                return Http::response(['success' => true, 'result' => ['id' => 'x']], 200);
            }

            return Http::response(['success' => false, 'errors' => [['message' => 'unexpected '.$url]]], 404);
        });

        $this->actingAs($this->operator())
            ->delete(route('ops.sites.purge', $site))
            ->assertRedirect(route('ops.sites'))
            ->assertSessionHas('status', __('sites.flash.purged'));

        $this->assertDatabaseMissing('sites', ['id' => $id]);

        foreach ([
            'zone-gone/dns_records/rec-shop.gone.test',
            'zone-gone/dns_records/rec-www.shop.gone.test',
            'zone-codron/dns_records/rec-amber-harbor.codron.co',
        ] as $path) {
            Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && str_ends_with($request->url(), $path));
        }
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE' && preg_match('#/zones/[^/]+$#', $request->url()) === 1);

        $audit = AuditLog::query()->where('action', 'site.purged')->where('subject_id', $id)->firstOrFail();
        $this->assertSame(['shop.gone.test', 'www.shop.gone.test', 'amber-harbor.codron.co'], $audit->after['released_hosts'] ?? null);
        // `??` would treat a stored null as missing; check the key, then the value.
        $this->assertArrayHasKey('dns_error', $audit->after);
        $this->assertNull($audit->after['dns_error']);
    }

    public function test_purge_finishes_when_cloudflare_fails_after_coolify_is_gone(): void
    {
        $this->seedCloudflare();
        $site = $this->site(['status' => SiteStatus::Active, 'primary_domain' => 'shop.gone.test']);
        $site->domains()->create(['domain' => 'shop.gone.test', 'is_primary' => true]);
        $id = $site->id;

        Http::fake(function (Request $request) {
            return str_contains($request->url(), 'coolify.example')
                ? Http::response('', 200)
                : Http::response(['success' => false, 'errors' => [['message' => 'Cloudflare down']]], 500);
        });

        $this->actingAs($this->operator())
            ->delete(route('ops.sites.purge', $site))
            ->assertRedirect(route('ops.sites'));

        $this->assertDatabaseMissing('sites', ['id' => $id]);
        $audit = AuditLog::query()->where('action', 'site.purged')->where('subject_id', $id)->firstOrFail();
        $this->assertNotNull($audit->after['dns_error'] ?? null);
    }

    public function test_purge_continues_when_coolify_app_is_already_gone(): void
    {
        $site = $this->site(['status' => SiteStatus::Active]);
        $id = $site->id;

        Http::fake([
            'https://coolify.example/api/v1/applications/'.self::APP.'*' => Http::response(['message' => 'Not found'], 404),
        ]);

        $this->actingAs($this->operator())
            ->delete(route('ops.sites.purge', $site))
            ->assertRedirect(route('ops.sites'));

        $this->assertDatabaseMissing('sites', ['id' => $id]);
    }

    public function test_purge_aborts_when_coolify_delete_fails(): void
    {
        $site = $this->site(['status' => SiteStatus::Active]);

        Http::fake([
            'https://coolify.example/api/v1/applications/'.self::APP.'*' => Http::response(['message' => 'Busy'], 500),
        ]);

        $this->actingAs($this->operator())
            ->from(route('ops.sites.show', $site))
            ->delete(route('ops.sites.purge', $site))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseHas('sites', ['id' => $site->id]);
        $this->assertNull($site->fresh()->deleted_at);
    }

    public function test_bulk_purge_hard_deletes_selected_sites(): void
    {
        $keep = $this->site(['name' => 'Keep', 'coolify_app_uuid' => 'keep-app']);
        $gone = $this->site([
            'name' => 'Gone',
            'slug' => 'gone-site',
            'primary_domain' => 'gone.example.test',
            'coolify_app_uuid' => 'gone-app',
        ]);

        Http::fake([
            'https://coolify.example/api/v1/applications/gone-app*' => Http::response('', 200),
        ]);

        $this->actingAs($this->operator())
            ->from(route('ops.sites'))
            ->post(route('ops.sites.bulk.purge'), ['site_ids' => [$gone->id]])
            ->assertRedirect(route('ops.sites'));

        $this->assertDatabaseMissing('sites', ['id' => $gone->id]);
        $this->assertDatabaseHas('sites', ['id' => $keep->id]);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && str_contains($request->url(), 'delete_volumes=true'));
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'keep-app'));
    }

    public function test_json_bulk_purge_queues_a_background_job(): void
    {
        $gone = $this->site(['name' => 'Gone', 'slug' => 'gone-json', 'primary_domain' => 'gone-json.example.test', 'coolify_app_uuid' => 'gone-json-app']);

        Queue::fake();
        Http::fake(['https://coolify.example/api/v1/applications/gone-json-app*' => Http::response('', 200)]);

        $response = $this->actingAs($this->superAdmin())
            ->postJson(route('ops.sites.bulk.purge'), ['site_ids' => [$gone->id]])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('job.type', 'sites.bulk_purge');

        // Nothing is deleted inside the request.
        $this->assertDatabaseHas('sites', ['id' => $gone->id]);
        Http::assertNothingSent();

        $job = OpsBackgroundJob::query()->findOrFail($response->json('job.id'));
        $message = app(OpsJobRunner::class)->run($job);

        $this->assertDatabaseMissing('sites', ['id' => $gone->id]);
        $this->assertStringContainsString(__('sites.flash.bulk_purged'), $message);
    }

    public function test_bulk_purge_keeps_going_after_an_unexpected_error(): void
    {
        $broken = $this->site(['name' => 'Broken', 'slug' => 'broken', 'primary_domain' => 'broken.example.test', 'coolify_app_uuid' => 'broken-app']);
        $fine = $this->site(['name' => 'Fine', 'slug' => 'fine', 'primary_domain' => 'fine.example.test', 'coolify_app_uuid' => 'fine-app']);

        Http::fake(['https://coolify.example/api/v1/applications/*' => Http::response('', 200)]);

        // The unexpected error comes from a collaborator, not from inside Http::fake:
        // an exception thrown in the Guzzle handler segfaults this PHP build.
        $landing = \Mockery::mock(SiteLanding::class);
        $landing->shouldReceive('releaseHostDns')->andReturnUsing(function (Site $site): ?string {
            if ($site->name === 'Broken') {
                throw new \RuntimeException('socket exploded');
            }

            return null;
        });
        $this->app->instance(SiteLanding::class, $landing);

        $result = app(SiteLifecycle::class)->purgeMany(
            collect([$broken, $fine]),
            $this->operator(),
            '127.0.0.1',
        );

        $this->assertSame(1, $result['ok']);
        $this->assertSame(1, $result['failed']);
        $this->assertSame(['Broken: '.__('sites.flash.purge_unexpected')], $result['errors']);
        $this->assertDatabaseHas('sites', ['id' => $broken->id]);
        $this->assertDatabaseMissing('sites', ['id' => $fine->id]);
    }

    public function test_live_sync_one_probes_the_site_homepage(): void
    {
        $site = $this->site([
            'status' => SiteStatus::Active,
            'primary_domain' => 'one.example.test',
        ]);

        Http::fake([
            'https://one.example.test/*' => Http::response('<html><head><link rel="icon" href="/icon.png"></head></html>', 200),
        ]);

        $this->actingAs($this->operator())
            ->from(route('ops.sites.show', $site))
            ->post(route('ops.sites.live-sync.one', $site))
            ->assertRedirect(route('ops.sites.show', $site))
            ->assertSessionHas('status', __('sites.flash.live_synced', ['ok' => 1, 'failed' => 0]));

        $this->assertSame(200, $site->fresh()->last_live_http_status);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'https://one.example.test/');
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'coolify.example'));
    }

    public function test_viewer_cannot_activate_or_purge(): void
    {
        $site = $this->site(['status' => SiteStatus::Active]);
        Http::fake();

        $this->actingAs($this->viewer())
            ->post(route('ops.sites.deactivate', $site))
            ->assertForbidden();
        $this->actingAs($this->viewer())
            ->delete(route('ops.sites.purge', $site))
            ->assertForbidden();
        $this->actingAs($this->viewer())
            ->post(route('ops.sites.live-sync.one', $site))
            ->assertForbidden();

        $this->assertDatabaseHas('sites', ['id' => $site->id, 'status' => SiteStatus::Active->value]);
        Http::assertNothingSent();
    }

    public function test_deactivate_is_blocked_without_coolify_app(): void
    {
        $site = Site::factory()->create([
            'status' => SiteStatus::Active,
            'coolify_app_uuid' => null,
        ]);
        Http::fake();

        $this->actingAs($this->operator())
            ->from(route('ops.sites.show', $site))
            ->post(route('ops.sites.deactivate', $site))
            ->assertRedirect()
            ->assertSessionHas('error', __('sites.flash.deactivate_blocked'));

        Http::assertNothingSent();
        $this->assertSame(SiteStatus::Active, $site->fresh()->status);
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create();
        $user->assignRole(OpsRole::SuperAdmin->value);

        return $user;
    }

    private function seedCloudflare(): void
    {
        CloudflareSetting::factory()->create([
            'account_id' => 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4',
            'api_token' => 'cf-lifecycle-token',
            'origin_ipv4' => '72.62.117.147',
            'wildcard_domain' => 'codron.co',
            'is_enabled' => true,
            'is_default' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function site(array $overrides = []): Site
    {
        $connection = CoolifyConnection::factory()->create([
            'base_url' => 'https://coolify.example',
            'api_token' => self::TOKEN,
        ]);

        return Site::factory()->create(array_merge([
            'status' => SiteStatus::Active,
            'coolify_app_uuid' => self::APP,
            'coolify_connection_id' => $connection->id,
        ], $overrides));
    }

    private function operator(): User
    {
        $user = User::factory()->create();
        $user->assignRole(OpsRole::Operator->value);

        return $user;
    }

    private function viewer(): User
    {
        $user = User::factory()->create();
        $user->assignRole(OpsRole::Viewer->value);

        return $user;
    }
}
