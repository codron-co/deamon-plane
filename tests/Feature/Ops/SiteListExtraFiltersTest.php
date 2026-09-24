<?php

namespace Tests\Feature\Ops;

use App\Enums\OpsRole;
use App\Models\CoolifyConnection;
use App\Models\CoolifyServer;
use App\Models\Site;
use App\Models\SiteThemeInstallation;
use App\Models\Theme;
use App\Models\User;
use App\Services\Coolify\CoolifySiteTargetSync;
use App\Services\Coolify\Dto\CoolifyApplication;
use App\Support\Lists\ListFragment;
use App\Support\Lists\SiteListColumns;
use App\Support\Lists\SiteSavedViews;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Sites list: theme-in-use, CMS version, auto-deploy, server and stale-health filters,
 * threaded through the scope, the panel, chips, saved views and bulk "all matching".
 */
class SiteListExtraFiltersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Http::preventStrayRequests();
    }

    public function test_theme_id_matches_the_plane_install_or_the_reported_theme(): void
    {
        $kuzey = Theme::factory()->create(['theme_id' => 'kuzey', 'name' => 'Kuzey']);
        $installed = Site::factory()->create(['name' => 'Kurulu']);
        SiteThemeInstallation::factory()->active()->create(['site_id' => $installed->id, 'theme_id' => $kuzey->id]);
        $reported = Site::factory()->create(['name' => 'Bildirilen', 'last_health_payload' => ['active_theme_id' => 'kuzey']]);
        Site::factory()->create(['name' => 'Baska', 'last_health_payload' => ['active_theme_id' => 'guney']]);
        Site::factory()->create(['name' => 'Temasiz']);

        $this->assertEqualsCanonicalizing(
            [$installed->id, $reported->id],
            Site::query()->matchingListFilters(themeId: 'kuzey')->pluck('id')->all(),
        );
        $this->assertSame(4, Site::query()->matchingListFilters(themeId: "bad id'")->count());
    }

    public function test_theme_id_renders_as_a_select_with_catalog_and_reported_options(): void
    {
        Theme::factory()->create(['theme_id' => 'kuzey', 'name' => 'Kuzey']);
        Site::factory()->create(['name' => 'Bildirilen', 'last_health_payload' => ['active_theme_id' => 'yerel']]);

        $this->actingAs($this->operator())
            ->get(route('ops.sites', ['theme_id' => 'kuzey']))
            ->assertOk()
            ->assertSee('id="sites-filter-theme-id"', false)
            ->assertSee('name="theme_id"', false)
            ->assertSee('<option value="kuzey" selected>Kuzey (kuzey)</option>', false)
            ->assertSee(e(__('sites.theme_id_reported_option', ['theme' => 'yerel'])), false)
            ->assertSee('name="theme_id" value="kuzey"', false)
            ->assertSee(__('sites.filter_theme_id'));
    }

    public function test_cms_outdated_compares_with_the_newest_version_in_the_fleet(): void
    {
        $old = Site::factory()->create(['last_health_payload' => ['deamon_version' => '1.2.9']]);
        $newest = Site::factory()->create(['last_health_payload' => ['deamon_version' => 'v1.2.32']]);
        $mid = Site::factory()->create(['last_health_payload' => ['version' => '1.2.30']]);
        $unknown = Site::factory()->create(['last_health_payload' => ['status' => 'ok']]);
        $none = Site::factory()->create();

        $this->assertSame('1.2.32', Site::reportedDeamonVersions()['newest']);
        $this->assertEqualsCanonicalizing(
            [$old->id, $mid->id],
            Site::query()->matchingListFilters(cms: 'outdated')->pluck('id')->all(),
        );
        $this->assertEqualsCanonicalizing(
            [$unknown->id, $none->id],
            Site::query()->matchingListFilters(cms: 'unknown')->pluck('id')->all(),
        );
        $this->assertNotContains($newest->id, Site::query()->matchingListFilters(cms: 'outdated')->pluck('id')->all());
    }

    public function test_cms_outdated_compares_within_the_same_branch(): void
    {
        $mainCurrent = Site::factory()->create(['channel' => 'main', 'last_health_payload' => ['deamon_version' => '1.2.29']]);
        $mainOld = Site::factory()->create(['channel' => 'main', 'last_health_payload' => ['deamon_version' => '1.2.27']]);
        $alphaNew = Site::factory()->create(['channel' => 'alpha', 'last_health_payload' => ['deamon_version' => '1.2.33']]);

        $this->assertSame(
            [$mainOld->id],
            Site::query()->matchingListFilters(cms: 'outdated')->pluck('id')->all(),
        );
        $this->assertNotContains($mainCurrent->id, Site::query()->matchingListFilters(cms: 'outdated')->pluck('id')->all());
        $this->assertNotContains($alphaNew->id, Site::query()->matchingListFilters(cms: 'outdated')->pluck('id')->all());
    }

    public function test_cms_filter_chip_and_option_name_the_baseline_version(): void
    {
        Site::factory()->create(['last_health_payload' => ['deamon_version' => '1.2.9']]);
        Site::factory()->create(['last_health_payload' => ['deamon_version' => '1.2.32']]);
        $label = __('sites.cms_states.outdated_version', ['version' => '1.2.32']);

        $this->actingAs($this->operator())
            ->get(route('ops.sites', ['cms' => 'outdated']))
            ->assertOk()
            ->assertSee('name="cms" value="outdated" form="sites-list-filters" data-ops-list-filter checked', false)
            ->assertSee('ops-filter-chips', false)
            ->assertSee($label);

        // A region re-query still labels the chip.
        $this->actingAs($this->operator())
            ->withHeader(ListFragment::HEADER, ListFragment::VALUE)
            ->get(route('ops.sites', ['cms' => 'outdated']))
            ->assertOk()
            ->assertSee($label);
    }

    public function test_auto_deploy_filter_reads_the_mirror_columns(): void
    {
        $on = Site::factory()->create(['coolify_auto_deploy' => true]);
        $off = Site::factory()->create(['coolify_auto_deploy' => false, 'coolify_pinned_sha' => str_repeat('c', 40)]);
        $unknown = Site::factory()->create(['coolify_auto_deploy' => null]);

        $this->assertSame([$on->id], Site::query()->matchingListFilters(autoDeploy: 'on')->pluck('id')->all());
        $this->assertSame([$off->id], Site::query()->matchingListFilters(autoDeploy: 'off')->pluck('id')->all());
        $this->assertSame([$unknown->id], Site::query()->matchingListFilters(autoDeploy: 'unknown')->pluck('id')->all());
        $this->assertSame([$off->id], Site::query()->matchingListFilters(autoDeploy: 'pinned')->pluck('id')->all());

        $this->actingAs($this->operator())
            ->get(route('ops.sites', ['auto_deploy' => 'pinned']))
            ->assertOk()
            ->assertSee('name="auto_deploy" value="pinned" form="sites-list-filters" data-ops-list-filter checked', false)
            ->assertSee(__('sites.auto_deploy_states.pinned'));
    }

    public function test_bulk_all_matching_honours_the_auto_deploy_filter(): void
    {
        $target = Site::factory()->create(['name' => 'Kapali', 'coolify_auto_deploy' => false]);
        $spared = Site::factory()->create(['name' => 'Acik', 'coolify_auto_deploy' => true]);

        $this->actingAs($this->admin())
            ->from(route('ops.sites', ['auto_deploy' => 'off']))
            ->post(route('ops.sites.bulk.purge'), [
                'all' => '1',
                'filter_auto_deploy' => 'off',
                'confirmed' => '1',
            ])
            ->assertRedirect();

        $this->assertNull(Site::withTrashed()->find($target->id));
        $this->assertNotNull(Site::query()->find($spared->id));
    }

    public function test_server_filter_matches_the_site_server_uuid_and_labels_the_connection(): void
    {
        $first = CoolifyConnection::factory()->create(['name' => 'Birinci']);
        $second = CoolifyConnection::factory()->create(['name' => 'Ikinci', 'is_default' => false]);
        CoolifyServer::query()->create(['coolify_connection_id' => $first->id, 'uuid' => 'srv-aaa', 'name' => 'hetzner-1']);
        CoolifyServer::query()->create(['coolify_connection_id' => $second->id, 'uuid' => 'srv-bbb', 'name' => 'hetzner-2']);

        $onA = Site::factory()->create(['coolify_connection_id' => $first->id, 'coolify_server_uuid' => 'srv-aaa']);
        Site::factory()->create(['coolify_connection_id' => $second->id, 'coolify_server_uuid' => 'srv-bbb']);

        $this->assertSame([$onA->id], Site::query()->matchingListFilters(server: 'srv-aaa')->pluck('id')->all());

        $this->actingAs($this->operator())
            ->get(route('ops.sites', ['server' => 'srv-aaa']))
            ->assertOk()
            ->assertSee('id="sites-filter-server"', false)
            ->assertSee('<option value="srv-aaa" selected>hetzner-1 · Birinci</option>', false)
            ->assertSee('name="filter_server" value="srv-aaa"', false);
    }

    public function test_bulk_all_matching_honours_the_server_filter(): void
    {
        $target = Site::factory()->create(['coolify_server_uuid' => 'srv-aaa']);
        $spared = Site::factory()->create(['coolify_server_uuid' => 'srv-bbb']);

        $this->actingAs($this->admin())
            ->post(route('ops.sites.bulk.purge'), [
                'all' => '1',
                'filter_server' => 'srv-aaa',
                'confirmed' => '1',
            ])
            ->assertRedirect();

        $this->assertNull(Site::withTrashed()->find($target->id));
        $this->assertNotNull(Site::query()->find($spared->id));
    }

    public function test_stale_filter_lists_missing_or_day_old_health(): void
    {
        $fresh = Site::factory()->create(['last_health_at' => now()->subHours(2)]);
        $old = Site::factory()->create(['last_health_at' => now()->subHours(30)]);
        $never = Site::factory()->create(['last_health_at' => null]);

        $ids = Site::query()->matchingListFilters(stale: 'stale')->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$old->id, $never->id], $ids);
        $this->assertNotContains($fresh->id, $ids);

        $this->actingAs($this->operator())
            ->get(route('ops.sites', ['stale' => 'stale']))
            ->assertOk()
            ->assertSee('name="stale" value="stale" form="sites-list-filters" data-ops-list-filter checked', false)
            ->assertSee(__('sites.stale_states.stale', ['hours' => Site::STALE_HEALTH_HOURS]));
    }

    public function test_bulk_filter_inputs_are_length_validated(): void
    {
        // The bulk request bounds the new filter inputs like the old ones.
        $this->actingAs($this->admin())
            ->post(route('ops.sites.bulk.purge'), [
                'all' => '1',
                'filter_theme_id' => str_repeat('a', 101),
                'confirmed' => '1',
            ])
            ->assertSessionHasErrors('filter_theme_id');
    }

    public function test_a_saved_view_keeps_the_new_filters(): void
    {
        Site::factory()->create();
        $user = $this->operator();

        $this->actingAs($user)
            ->post(route('ops.sites.list-views.store'), [
                'name' => 'Eski CMS',
                'theme_id' => 'kuzey',
                'cms' => 'outdated',
                'auto_deploy' => 'off',
                'server' => 'srv-aaa',
                'stale' => 'stale',
                'columns' => ['site'],
            ])
            ->assertRedirect();

        $stored = $user->fresh()->listPreference(SiteListColumns::LIST_KEY);
        $this->assertSameJsonObject([
            'theme_id' => 'kuzey',
            'cms' => 'outdated',
            'auto_deploy' => 'off',
            'server' => 'srv-aaa',
            'stale' => 'stale',
        ], $stored['views'][0]['filters']);

        $this->actingAs($user->fresh())
            ->get(route('ops.sites', ['view' => $stored['views'][0]['id']]))
            ->assertOk()
            ->assertSee('name="cms" value="outdated" form="sites-list-filters" data-ops-list-filter checked', false)
            ->assertSee('name="stale" value="stale" form="sites-list-filters" data-ops-list-filter checked', false)
            ->assertSee('<option value="srv-aaa" selected>srv-aaa</option>', false)
            ->assertSee('name="auto_deploy" value="off"', false);
    }

    public function test_invalid_values_are_dropped_from_saved_filters(): void
    {
        $this->assertSame([], SiteSavedViews::sanitizeFilters([
            'theme_id' => '../x',
            'cms' => 'newest',
            'auto_deploy' => 'maybe',
            'server' => 'a b',
            'stale' => 'fresh',
        ]));
    }

    public function test_the_coolify_sync_mirrors_auto_deploy_and_pin_without_touching_updated_at(): void
    {
        $connection = CoolifyConnection::factory()->create();
        $site = Site::factory()->create([
            'coolify_connection_id' => $connection->id,
            'coolify_app_uuid' => 'app-1',
            'coolify_auto_deploy' => null,
        ]);
        $site->forceFill(['updated_at' => now()->subDay()])->saveQuietly();
        $updatedAt = $site->fresh()->updated_at;

        $app = CoolifyApplication::fromArray([
            'uuid' => 'app-1',
            'name' => 'x',
            'is_auto_deploy_enabled' => false,
            'git_commit_sha' => str_repeat('d', 40),
        ]);

        (new CoolifySiteTargetSync)->fillSite($site, $app, $connection);

        $site->refresh();
        $this->assertFalse($site->coolify_auto_deploy);
        $this->assertSame(str_repeat('d', 40), $site->coolify_pinned_sha);
        $this->assertNotNull($site->coolify_deploy_settings_at);
        $this->assertTrue($updatedAt->equalTo($site->updated_at));
    }

    private function operator(): User
    {
        $user = User::factory()->create();
        $user->assignRole(OpsRole::Operator->value);

        return $user;
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole(OpsRole::SuperAdmin->value);

        return $user;
    }
}
