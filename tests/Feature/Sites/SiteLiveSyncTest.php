<?php

namespace Tests\Feature\Sites;

use App\Enums\OpsRole;
use App\Enums\SiteStatus;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SiteLiveSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Http::preventStrayRequests();
    }

    public function test_sites_index_shows_sync_live_sync_and_utf8_mark(): void
    {
        $site = Site::factory()->create([
            'name' => 'İzyem',
            'slug' => 'izyem-mark',
            'primary_domain' => 'izyem-mark.example.test',
            'last_live_http_status' => 200,
            'last_live_checked_at' => now(),
            'last_live_favicon_url' => 'https://izyem-mark.example.test/theme/favicon.png',
        ]);

        $html = $this->actingAs($this->operator())
            ->get(route('ops.sites'))
            ->assertOk()
            ->assertSee(__('sites.columns.live'), false)
            ->assertSee(__('sites.detail.sync'), false)
            ->assertSee(__('sites.live.sync'), false)
            ->assertSee('data-confirm="'.__('sites.detail.sync_confirm_all').'"', false)
            ->assertSee('data-confirm="'.__('sites.live.confirm').'"', false)
            ->assertSee('data-favicon-fallback="İ"', false)
            ->assertSee('data-favicon-src="'.$site->last_live_favicon_url.'"', false)
            ->assertSee('>200<', false)
            ->getContent();

        $this->assertStringContainsString('action="'.route('ops.sites.bulk.sync').'"', $html);
        $this->assertStringContainsString('action="'.route('ops.sites.live-sync').'"', $html);
        $this->assertStringNotContainsString("\u{FFFD}", $html);
        $this->assertDoesNotMatchRegularExpression('/href="[^"]*\/sites\/bulk\/(?:live-)?sync"/', $html);
    }

    public function test_viewer_does_not_see_sync_actions_and_cannot_live_sync(): void
    {
        Site::factory()->create([
            'name' => 'View Only',
            'primary_domain' => 'view-only.example.test',
        ]);
        Http::fake();

        $this->actingAs($this->viewer())
            ->get(route('ops.sites'))
            ->assertOk()
            ->assertDontSee(__('sites.live.sync'), false)
            ->assertDontSee(route('ops.sites.bulk.sync'), false)
            ->assertDontSee(route('ops.sites.live-sync'), false);

        $this->actingAs($this->viewer())
            ->post(route('ops.sites.live-sync'), ['all' => '1'])
            ->assertForbidden();

        $this->actingAs($this->viewer())
            ->post(route('ops.sites.bulk.sync'), ['all' => '1'])
            ->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_live_sync_stores_status_and_absolute_favicon_without_touching_secrets(): void
    {
        $site = Site::factory()->withSecrets()->create([
            'status' => SiteStatus::Active,
            'primary_domain' => 'live.example.test',
            'last_live_favicon_url' => 'https://old.example.test/old.ico',
        ]);
        $appKey = $site->app_key_encrypted;
        $agentSecret = $site->agent_secret_encrypted;

        Http::fake([
            'https://live.example.test/*' => Http::response(
                '<html><head><link rel="icon" href="/theme/favicon.png"></head></html>',
                200,
            ),
        ]);

        $this->actingAs($this->operator())
            ->from(route('ops.sites'))
            ->post(route('ops.sites.live-sync'), ['all' => '1'])
            ->assertRedirect(route('ops.sites'))
            ->assertSessionHas('status', __('sites.flash.live_synced', ['ok' => 1, 'failed' => 0]));

        $site->refresh();
        $this->assertSame(200, $site->last_live_http_status);
        $this->assertNotNull($site->last_live_checked_at);
        $this->assertSame('https://live.example.test/theme/favicon.png', $site->last_live_favicon_url);
        $this->assertSame(SiteStatus::Active, $site->status);
        $this->assertSame($appKey, $site->app_key_encrypted);
        $this->assertSame($agentSecret, $site->agent_secret_encrypted);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'https://live.example.test/'
            && $request->header('User-Agent')[0] === 'Deamon-Plane-LiveSync/1');
    }

    public function test_live_sync_stores_404_and_500_and_keeps_favicon_when_html_has_none(): void
    {
        $missing = Site::factory()->create([
            'primary_domain' => 'missing.example.test',
            'last_live_favicon_url' => 'https://missing.example.test/kept.ico',
        ]);
        $broken = Site::factory()->create([
            'primary_domain' => 'broken.example.test',
        ]);

        Http::fake([
            'https://missing.example.test/*' => Http::response('<html>not found</html>', 404),
            'https://broken.example.test/*' => Http::response('<html>error</html>', 500),
        ]);

        $this->actingAs($this->operator())
            ->from(route('ops.sites'))
            ->post(route('ops.sites.live-sync'), [
                'site_ids' => [$missing->id, $broken->id],
            ])
            ->assertRedirect(route('ops.sites'))
            ->assertSessionHas('status', __('sites.flash.live_synced', ['ok' => 0, 'failed' => 2]));

        $missing->refresh();
        $broken->refresh();
        $this->assertSame(404, $missing->last_live_http_status);
        $this->assertSame('https://missing.example.test/kept.ico', $missing->last_live_favicon_url);
        $this->assertSame(500, $broken->last_live_http_status);
        $this->assertNull($broken->last_live_favicon_url);
    }

    public function test_live_sync_stores_zero_when_connection_fails(): void
    {
        $site = Site::factory()->create([
            'primary_domain' => 'down.example.test',
        ]);

        $this->actingAs($this->operator())
            ->post(route('ops.sites.live-sync'), ['all' => '1'])
            ->assertRedirect();

        $site->refresh();
        $this->assertSame(0, $site->last_live_http_status);
        $this->assertNotNull($site->last_live_checked_at);
        $this->assertSame('Down', $site->liveHttpLabel());
        $this->assertSame('unhealthy', $site->liveHttpTone());
    }

    public function test_get_live_sync_does_not_probe(): void
    {
        Site::factory()->create(['primary_domain' => 'skip.example.test']);
        Http::fake();

        $this->actingAs($this->operator())
            ->get(route('ops.sites.live-sync.get'))
            ->assertRedirect(route('ops.sites'))
            ->assertSessionHas('status', __('sites.flash.live_sync_get'));

        Http::assertNothingSent();
        $this->assertNull(Site::query()->where('primary_domain', 'skip.example.test')->value('last_live_http_status'));
    }

    private function operator(): User
    {
        $operator = User::factory()->create();
        $operator->assignRole(OpsRole::Operator->value);

        return $operator;
    }

    private function viewer(): User
    {
        $viewer = User::factory()->create();
        $viewer->assignRole(OpsRole::Viewer->value);

        return $viewer;
    }
}
