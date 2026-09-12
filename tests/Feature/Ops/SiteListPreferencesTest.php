<?php

namespace Tests\Feature\Ops;

use App\Enums\Channel;
use App\Enums\CmsPublishStatus;
use App\Enums\OpsRole;
use App\Models\Site;
use App\Models\User;
use App\Support\Lists\SiteListColumns;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteListPreferencesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_default_columns_render_and_hidden_ones_do_not(): void
    {
        Site::factory()->create();

        // Assert on the header links, not the label text: words like "Live" and
        // "Updated" also appear in toolbar actions and would match anywhere.
        $html = $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.sites'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('sort=publish', $html);
        $this->assertStringContainsString('sort=live', $html);
        $this->assertStringNotContainsString('sort=updated', $html);
        $this->assertStringNotContainsString('sort=health', $html);
    }

    public function test_operator_picks_columns_and_they_persist_in_the_database(): void
    {
        Site::factory()->create();
        $user = $this->user(OpsRole::Operator);

        $this->actingAs($user)
            ->from(route('ops.sites'))
            ->post(route('ops.sites.list-preferences'), [
                'columns' => ['site', 'publish', 'updated'],
            ])
            ->assertRedirect(route('ops.sites'))
            ->assertSessionHas('status');

        $this->assertSame(
            ['site', 'publish', 'updated'],
            $user->fresh()->listPreference(SiteListColumns::LIST_KEY)['columns'],
        );

        // Persisted in the DB, not a cookie: a brand-new session sees the same table.
        $html = $this->actingAs($user->fresh())
            ->get(route('ops.sites'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('sort=updated', $html);
        $this->assertStringNotContainsString('sort=live', $html);
        $this->assertStringNotContainsString('sort=channel', $html);
    }

    public function test_unknown_columns_are_dropped_and_an_empty_pick_falls_back_to_defaults(): void
    {
        $user = $this->user(OpsRole::Operator);

        $this->actingAs($user)
            ->from(route('ops.sites'))
            ->post(route('ops.sites.list-preferences'), [
                'columns' => ['site', 'totally_made_up'],
            ])
            ->assertRedirect();

        $this->assertSame(['site'], $user->fresh()->listPreference(SiteListColumns::LIST_KEY)['columns']);

        $this->actingAs($user->fresh())
            ->from(route('ops.sites'))
            ->post(route('ops.sites.list-preferences'), ['columns' => []])
            ->assertRedirect();

        $this->assertSame(
            SiteListColumns::defaults(),
            $user->fresh()->listPreference(SiteListColumns::LIST_KEY)['columns'],
        );
    }

    public function test_the_site_column_cannot_be_hidden(): void
    {
        $user = $this->user(OpsRole::Operator);

        $this->actingAs($user)
            ->from(route('ops.sites'))
            ->post(route('ops.sites.list-preferences'), ['columns' => ['publish', 'live']])
            ->assertRedirect();

        $columns = $user->fresh()->listPreference(SiteListColumns::LIST_KEY)['columns'];
        $this->assertContains('site', $columns, 'The identity column carries the row link and stays visible.');
        // Catalogue order is restored regardless of the order posted.
        $this->assertSame(['site', 'publish', 'live'], $columns);
    }

    public function test_reset_restores_default_columns_and_sort(): void
    {
        $user = $this->user(OpsRole::Operator);
        $user->saveListPreference(SiteListColumns::LIST_KEY, [
            'columns' => ['site'],
            'sort' => ['key' => 'publish', 'dir' => 'desc'],
        ]);

        $this->actingAs($user)
            ->from(route('ops.sites'))
            ->delete(route('ops.sites.list-preferences.reset'))
            ->assertRedirect()
            ->assertSessionHas('status');

        $stored = $user->fresh()->listPreference(SiteListColumns::LIST_KEY);
        $this->assertSame(SiteListColumns::defaults(), $stored['columns']);
        $this->assertSame(['key' => 'site', 'dir' => 'asc'], $stored['sort']);
    }

    public function test_viewer_may_save_their_own_layout(): void
    {
        $viewer = $this->user(OpsRole::Viewer);

        $this->actingAs($viewer)
            ->from(route('ops.sites'))
            ->post(route('ops.sites.list-preferences'), ['columns' => ['site', 'domain']])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertSame(['site', 'domain'], $viewer->fresh()->listPreference(SiteListColumns::LIST_KEY)['columns']);
    }

    public function test_headers_are_sort_links_and_unsortable_ones_are_not(): void
    {
        Site::factory()->create();

        $html = $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.sites'))
            ->assertOk()
            ->assertSee('aria-sort="ascending"', false)
            ->getContent();

        $this->assertStringContainsString('sort=publish', $html);
        $this->assertStringContainsString('sort=domain', $html);
        // `app` is computed at render time, so it must not offer a dead sort link.
        $this->assertStringNotContainsString('sort=app', $html);
    }

    public function test_clicking_a_header_sorts_and_flips_direction(): void
    {
        Site::factory()->create(['name' => 'Bravo', 'primary_domain' => 'bravo.example.test']);
        Site::factory()->create(['name' => 'Alpha', 'primary_domain' => 'alpha.example.test']);
        $user = $this->user(OpsRole::Operator);

        $ascending = $this->actingAs($user)
            ->get(route('ops.sites', ['sort' => 'site', 'dir' => 'asc']))
            ->assertOk()
            ->getContent();
        $this->assertLessThan(
            strpos($ascending, 'Bravo'),
            strpos($ascending, 'Alpha'),
            'Ascending by site must put Alpha first.',
        );

        $descending = $this->actingAs($user)
            ->get(route('ops.sites', ['sort' => 'site', 'dir' => 'desc']))
            ->assertOk()
            ->assertSee('aria-sort="descending"', false)
            ->getContent();
        $this->assertLessThan(
            strpos($descending, 'Alpha'),
            strpos($descending, 'Bravo'),
            'Descending by site must put Bravo first.',
        );
    }

    public function test_sorting_by_publish_state_orders_by_the_mirror_column(): void
    {
        Site::factory()->create(['name' => 'Published One', 'cms_site_status' => CmsPublishStatus::Published]);
        Site::factory()->create(['name' => 'Draft One', 'cms_site_status' => CmsPublishStatus::Draft]);

        $html = $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.sites', ['sort' => 'publish', 'dir' => 'desc']))
            ->assertOk()
            ->getContent();

        $this->assertLessThan(
            strpos($html, 'Draft One'),
            strpos($html, 'Published One'),
            'Descending publish sort puts published sites first.',
        );
    }

    public function test_a_chosen_sort_is_remembered_for_the_next_unqualified_visit(): void
    {
        Site::factory()->create(['name' => 'Bravo']);
        Site::factory()->create(['name' => 'Alpha']);
        $user = $this->user(OpsRole::Operator);

        $this->actingAs($user)
            ->get(route('ops.sites', ['sort' => 'site', 'dir' => 'desc']))
            ->assertOk();

        $this->assertSame(
            ['key' => 'site', 'dir' => 'desc'],
            $user->fresh()->listPreference(SiteListColumns::LIST_KEY)['sort'],
        );

        $html = $this->actingAs($user->fresh())
            ->get(route('ops.sites'))
            ->assertOk()
            ->assertSee('aria-sort="descending"', false)
            ->getContent();

        $this->assertLessThan(strpos($html, 'Alpha'), strpos($html, 'Bravo'));
    }

    public function test_a_stored_sort_on_a_hidden_column_falls_back_to_the_default(): void
    {
        Site::factory()->create();
        $user = $this->user(OpsRole::Operator);
        $user->saveListPreference(SiteListColumns::LIST_KEY, [
            'columns' => ['site', 'domain'],
            'sort' => ['key' => 'publish', 'dir' => 'desc'],
        ]);

        // Sorting by a column with no header would leave the operator no way to see
        // or undo the sort, so it reverts to the default rather than sorting invisibly.
        $this->actingAs($user->fresh())
            ->get(route('ops.sites'))
            ->assertOk()
            ->assertSee('aria-sort="ascending"', false)
            ->assertDontSee('aria-sort="descending"', false);
    }

    public function test_sort_links_keep_the_active_filters_and_drop_the_page(): void
    {
        Site::factory()->count(2)->create(['channel' => Channel::Alpha]);

        $html = $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.sites', ['channel' => 'alpha', 'page' => 1]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('channel=alpha', $html);
        // A new sort must start at page one rather than a page the sort may not have.
        $this->assertStringNotContainsString('page=1', $html);
    }

    private function user(OpsRole $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);

        return $user;
    }
}
