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
