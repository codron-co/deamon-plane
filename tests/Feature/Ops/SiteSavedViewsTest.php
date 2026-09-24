<?php

namespace Tests\Feature\Ops;

use App\Enums\Channel;
use App\Enums\OpsRole;
use App\Enums\SiteStatus;
use App\Models\Site;
use App\Models\User;
use App\Support\Lists\ListFragment;
use App\Support\Lists\SiteListColumns;
use App\Support\Lists\SiteSavedViews;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteSavedViewsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_builtin_view_chips_are_plain_query_links(): void
    {
        Site::factory()->create();

        $html = $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.sites'))
            ->assertOk()
            ->assertSee(__('sites.saved_views.all'), false)
            ->assertSee(__('sites.saved_views.error'), false)
            ->assertSee(__('sites.saved_views.unpublished'), false)
            ->assertSee(__('sites.saved_views.dockerfile'), false)
            ->getContent();

        $this->assertStringContainsString(e(route('ops.sites', ['view' => 'all'])), $html);
        $this->assertStringContainsString(e(route('ops.sites', ['view' => 'error', 'status' => 'error'])), $html);
        $this->assertStringContainsString(e(route('ops.sites', ['view' => 'unpublished', 'publish' => 'draft'])), $html);
        $this->assertStringContainsString(e(route('ops.sites', ['view' => 'dockerfile', 'pack' => 'dockerfile'])), $html);
    }

    public function test_saving_a_view_persists_filters_columns_and_sort_in_the_database(): void
    {
        Site::factory()->create();
        $user = $this->user(OpsRole::Operator);

        $this->actingAs($user)
            ->from(route('ops.sites', ['channel' => 'beta', 'status' => 'error']))
            ->post(route('ops.sites.list-views.store'), [
                'name' => 'Beta hataları',
                'default' => '1',
                'channel' => 'beta',
                'status' => 'error',
                'columns' => ['site', 'updated'],
                'sort_key' => 'updated',
                'sort_dir' => 'desc',
            ])
            ->assertRedirect();

        $stored = $user->fresh()->listPreference(SiteListColumns::LIST_KEY);
        $this->assertCount(1, $stored['views']);
        $this->assertSame('Beta hataları', $stored['views'][0]['name']);
        $this->assertSameJsonObject(['channel' => 'beta', 'status' => 'error'], $stored['views'][0]['filters']);
        $this->assertSame(['site', 'updated'], $stored['views'][0]['columns']);
        $this->assertSameJsonObject(['key' => 'updated', 'dir' => 'desc'], $stored['views'][0]['sort']);
        $this->assertSame($stored['views'][0]['id'], $stored['default_view']);
    }

    public function test_a_default_view_applies_on_a_bare_sites_visit_including_hidden_columns(): void
    {
        Site::factory()->create(['name' => 'Broken', 'channel' => Channel::Beta, 'status' => SiteStatus::Error]);
        Site::factory()->create(['name' => 'Healthy', 'channel' => Channel::Main, 'status' => SiteStatus::Active]);
        $user = $this->user(OpsRole::Operator);
        $id = 'aabbccddeeff';
        $user->saveListPreference(SiteListColumns::LIST_KEY, [
            'columns' => ['site'],
            'views' => [[
                'id' => $id,
                'name' => 'Beta hataları',
                'filters' => ['channel' => 'beta', 'status' => 'error'],
                'columns' => ['site', 'updated'],
                'sort' => ['key' => 'updated', 'dir' => 'desc'],
            ]],
            'default_view' => $id,
        ]);

        $expected = route('ops.sites', [
            'channel' => 'beta',
            'status' => 'error',
            'view' => $id,
            'sort' => 'updated',
            'dir' => 'desc',
        ]);

        $this->actingAs($user)
            ->get(route('ops.sites'))
            ->assertRedirect($expected);

        $html = $this->actingAs($user->fresh())
            ->get($expected)
            ->assertOk()
            ->assertSee('Broken', false)
            ->assertDontSee('Healthy', false)
            ->getContent();

        $this->assertStringContainsString('sort=updated', $html);
        $this->assertStringNotContainsString('sort=live', $html);
        $this->assertStringContainsString('aria-current="page"', $html);
    }

    public function test_view_all_does_not_apply_the_default(): void
    {
        Site::factory()->create(['name' => 'Healthy', 'status' => SiteStatus::Active]);
        Site::factory()->create(['name' => 'Broken', 'status' => SiteStatus::Error]);
        $user = $this->user(OpsRole::Operator);
        $user->saveListPreference(SiteListColumns::LIST_KEY, [
            'views' => [[
                'id' => 'aabbccddeeff',
                'name' => 'Sorunlu',
                'filters' => ['status' => 'error'],
                'columns' => SiteListColumns::defaults(),
                'sort' => ['key' => 'site', 'dir' => 'asc'],
            ]],
            'default_view' => 'aabbccddeeff',
        ]);

        $this->actingAs($user)
            ->get(route('ops.sites', ['view' => 'all']))
            ->assertOk()
            ->assertSee('Healthy', false)
            ->assertSee('Broken', false);
    }

    public function test_a_dead_channel_in_a_saved_view_is_dropped(): void
    {
        Site::factory()->create(['name' => 'Broken', 'status' => SiteStatus::Error]);
        $user = $this->user(OpsRole::Operator);
        $id = 'deadchanview1';
        $user->saveListPreference(SiteListColumns::LIST_KEY, [
            'views' => [[
                'id' => $id,
                'name' => 'Eski dal',
                'filters' => ['channel' => 'gone-now', 'status' => 'error'],
                'columns' => SiteListColumns::defaults(),
                'sort' => ['key' => 'site', 'dir' => 'asc'],
            ]],
        ]);

        $html = $this->actingAs($user)
            ->get(route('ops.sites', ['view' => $id]))
            ->assertOk()
            ->assertSee('Broken', false)
            ->getContent();

        $this->assertStringNotContainsString('channel=gone-now', $html);
        $this->assertStringContainsString('status=error', $html);
    }

    public function test_the_dockerfile_view_filters_leftover_packs(): void
    {
        $legacy = Site::factory()->dockerfilePack()->create(['name' => 'Legacy Pack']);
        $compose = Site::factory()->create(['name' => 'Compose Pack']);

        $html = $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.sites', ['view' => 'dockerfile', 'pack' => 'dockerfile']))
            ->assertOk()
            ->assertSee('Legacy Pack', false)
            ->assertDontSee('Compose Pack', false)
            ->getContent();

        $this->assertStringContainsString((string) $legacy->id, $html);
        $this->assertStringNotContainsString((string) $compose->id, $html);
        $this->assertStringContainsString(__('sites.pack_states.dockerfile'), $html);
    }

    public function test_a_saved_view_survives_a_region_swap(): void
    {
        Site::factory()->create(['name' => 'Broken', 'status' => SiteStatus::Error]);
        Site::factory()->create(['name' => 'Healthy', 'status' => SiteStatus::Active]);
        $user = $this->user(OpsRole::Operator);
        $id = 'regionview0001';
        $user->saveListPreference(SiteListColumns::LIST_KEY, [
            'views' => [[
                'id' => $id,
                'name' => 'Hatalılar',
                'filters' => ['status' => 'error'],
                'columns' => ['site', 'updated'],
                'sort' => ['key' => 'updated', 'dir' => 'desc'],
            ]],
        ]);

        $html = $this->actingAs($user)
            ->withHeader(ListFragment::HEADER, ListFragment::VALUE)
            ->get(route('ops.sites', ['view' => $id, 'status' => 'error', 'sort' => 'updated', 'dir' => 'desc']))
            ->assertOk()
            ->assertHeader('X-Ops-List-Region', '1')
            ->assertSee('Broken', false)
            ->assertDontSee('Healthy', false)
            ->getContent();

        $this->assertStringContainsString('sort=updated', $html);
        $this->assertStringContainsString('Hatalılar', $html);
        $this->assertStringNotContainsString('ops-sidebar', $html);
    }

    public function test_a_bare_region_request_applies_the_default_without_redirecting(): void
    {
        Site::factory()->create(['name' => 'Broken', 'status' => SiteStatus::Error]);
        Site::factory()->create(['name' => 'Healthy', 'status' => SiteStatus::Active]);
        $user = $this->user(OpsRole::Operator);
        $user->saveListPreference(SiteListColumns::LIST_KEY, [
            'views' => [[
                'id' => 'fragdefault01',
                'name' => 'Hatalılar',
                'filters' => ['status' => 'error'],
                'columns' => ['site', 'updated'],
                'sort' => ['key' => 'updated', 'dir' => 'desc'],
            ]],
            'default_view' => 'fragdefault01',
        ]);

        $this->actingAs($user)
            ->withHeader(ListFragment::HEADER, ListFragment::VALUE)
            ->get(route('ops.sites'))
            ->assertOk()
            ->assertHeader('X-Ops-List-Region', '1')
            ->assertSee('Broken', false)
            ->assertDontSee('Healthy', false)
            ->assertSee('sort=updated', false);
    }

    public function test_a_sixth_view_is_rejected_unless_it_overwrites_a_name(): void
    {
        $user = $this->user(OpsRole::Operator);
        $views = [];
        for ($i = 1; $i <= 5; $i++) {
            $views[] = [
                'id' => sprintf('savedview%04d', $i),
                'name' => 'Görünüm '.$i,
                'filters' => ['status' => 'error'],
                'columns' => ['site'],
                'sort' => ['key' => 'site', 'dir' => 'asc'],
            ];
        }
        $user->saveListPreference(SiteListColumns::LIST_KEY, ['views' => $views]);

        $this->actingAs($user)
            ->from(route('ops.sites'))
            ->post(route('ops.sites.list-views.store'), [
                'name' => 'Altıncı',
                'status' => 'error',
                'columns' => ['site'],
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->actingAs($user->fresh())
            ->postJson(route('ops.sites.list-views.store'), [
                'name' => 'Altıncı',
                'status' => 'error',
                'columns' => ['site'],
            ])
            ->assertStatus(422)
            ->assertJson([
                'ok' => false,
                'message' => __('sites.saved_views.limit', ['max' => SiteSavedViews::MAX]),
            ]);

        $this->actingAs($user->fresh())
            ->postJson(route('ops.sites.list-views.store'), [
                'name' => 'Görünüm 1',
                'channel' => 'beta',
                'columns' => ['site', 'domain'],
            ])
            ->assertOk()
            ->assertJson(['ok' => true, 'refresh_list' => true]);

        $stored = $user->fresh()->listPreference(SiteListColumns::LIST_KEY)['views'];
        $this->assertCount(5, $stored);
        $this->assertSame(['channel' => 'beta'], $stored[0]['filters']);
    }

    public function test_saving_over_ajax_returns_the_layout_so_the_table_can_refresh(): void
    {
        $user = $this->user(OpsRole::Operator);

        $this->actingAs($user)
            ->postJson(route('ops.sites.list-views.store'), [
                'name' => 'Taslaklar',
                'publish' => 'draft',
                'columns' => ['site', 'publish'],
                'sort_key' => 'publish',
                'sort_dir' => 'asc',
            ])
            ->assertOk()
            ->assertJson([
                'ok' => true,
                'message' => __('sites.saved_views.saved'),
                'list' => SiteListColumns::LIST_KEY,
                'columns' => ['site', 'publish'],
                'refresh_list' => true,
            ]);
    }

    public function test_deleting_a_view_removes_it_and_clears_default(): void
    {
        $user = $this->user(OpsRole::Operator);
        $user->saveListPreference(SiteListColumns::LIST_KEY, [
            'views' => [[
                'id' => 'todeleteview1',
                'name' => 'Silinecek',
                'filters' => ['status' => 'error'],
                'columns' => ['site'],
                'sort' => ['key' => 'site', 'dir' => 'asc'],
            ]],
            'default_view' => 'todeleteview1',
        ]);

        $this->actingAs($user)
            ->deleteJson(route('ops.sites.list-views.destroy', 'todeleteview1'))
            ->assertOk()
            ->assertJson([
                'ok' => true,
                'refresh_list' => true,
            ]);

        $stored = $user->fresh()->listPreference(SiteListColumns::LIST_KEY);
        $this->assertSame([], $stored['views']);
        $this->assertNull($stored['default_view']);
    }

    public function test_views_are_per_user(): void
    {
        $owner = $this->user(OpsRole::Operator);
        $other = $this->user(OpsRole::Operator);
        $owner->saveListPreference(SiteListColumns::LIST_KEY, [
            'views' => [[
                'id' => 'ownerview0001',
                'name' => 'Sahibin',
                'filters' => ['status' => 'error'],
                'columns' => ['site'],
                'sort' => ['key' => 'site', 'dir' => 'asc'],
            ]],
            'default_view' => 'ownerview0001',
        ]);

        $this->actingAs($other)
            ->get(route('ops.sites'))
            ->assertOk()
            ->assertDontSee('Sahibin', false);
    }

    public function test_a_viewer_may_save_their_own_view(): void
    {
        $viewer = $this->user(OpsRole::Viewer);

        $this->actingAs($viewer)
            ->postJson(route('ops.sites.list-views.store'), [
                'name' => 'İzleyici',
                'status' => 'error',
                'columns' => ['site'],
            ])
            ->assertOk();

        $this->assertSame(
            'İzleyici',
            $viewer->fresh()->listPreference(SiteListColumns::LIST_KEY)['views'][0]['name'],
        );
    }

    public function test_unknown_filter_values_in_a_posted_view_are_dropped(): void
    {
        $user = $this->user(OpsRole::Operator);

        $this->actingAs($user)
            ->post(route('ops.sites.list-views.store'), [
                'name' => 'Temiz',
                'channel' => 'not-a-channel',
                'status' => 'error',
                'deploy' => 'exploded',
                'health' => 'kaboom',
                'app' => 'kaboom',
                'columns' => ['site'],
            ])
            ->assertRedirect();

        $this->assertSame(
            ['status' => 'error'],
            $user->fresh()->listPreference(SiteListColumns::LIST_KEY)['views'][0]['filters'],
        );
    }

    private function user(OpsRole $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);

        return $user;
    }
}
