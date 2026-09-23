<?php

namespace Tests\Feature\Ops;

use App\Enums\OpsRole;
use App\Enums\SiteStatus;
use App\Models\CoolifyConnection;
use App\Models\Site;
use App\Models\User;
use App\Support\Lists\ListFragment;
use App\Support\Lists\SiteListColumns;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Sites list wave 4: row quick actions, the single preset segment and the
 * list-only keyboard shortcuts the overlay declares.
 */
class SitesListQuickActionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_row_menu_offers_check_redeploy_and_coolify_to_an_operator(): void
    {
        [$site, $coolifyUrl] = $this->coolifySite();

        $html = $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.sites', ['view' => 'all']))
            ->assertOk()
            ->getContent();

        $menu = $this->rowMenu($html);

        $this->assertStringContainsString('action="'.route('ops.sites.health', $site).'"', $menu);
        $this->assertStringContainsString('name="return" value="list"', $menu);
        $this->assertStringContainsString(e(__('sites.row_actions.check')), $menu);
        $this->assertStringContainsString(e(__('sites.row_actions.redeploy')), $menu);
        $this->assertStringContainsString('href="'.e($coolifyUrl).'"', $menu);
        $this->assertStringContainsString(e(__('sites.menu.open_coolify')), $menu);

        // The redeploy form keeps the detail page's confirmation, word for word.
        $this->assertMatchesRegularExpression(
            '/<form\s+method="POST"\s+action="'.preg_quote(route('ops.sites.deploy', $site), '/').'"[^>]*'
            .'data-confirm="'.preg_quote(e(__('site_ops.redeploy.confirm', ['name' => $site->name])), '/').'"[^>]*'
            .'data-confirm-title="'.preg_quote(e(__('site_ops.redeploy.confirm_title')), '/').'"[^>]*'
            .'data-confirm-label="'.preg_quote(e(__('sites.menu.redeploy')), '/').'"[^>]*'
            .'data-confirm-danger="false"/',
            $menu,
        );
        $this->assertStringContainsString('data-row-action', $html);
    }

    public function test_row_menu_hides_mutating_actions_from_a_viewer(): void
    {
        [$site, $coolifyUrl] = $this->coolifySite();

        $html = $this->actingAs($this->user(OpsRole::Viewer))
            ->get(route('ops.sites', ['view' => 'all']))
            ->assertOk()
            ->getContent();

        $menu = $this->rowMenu($html);

        $this->assertStringNotContainsString(route('ops.sites.health', $site), $menu);
        $this->assertStringNotContainsString(route('ops.sites.deploy', $site), $menu);
        $this->assertStringNotContainsString(e(__('sites.row_actions.redeploy')), $menu);
        // A link out is not a mutation: the detail page shows it to viewers too.
        $this->assertStringContainsString('href="'.e($coolifyUrl).'"', $menu);
    }

    public function test_row_menu_skips_redeploy_and_coolify_without_a_coolify_app(): void
    {
        $site = Site::factory()->create(['name' => 'No Coolify', 'coolify_app_uuid' => null]);

        $menu = $this->rowMenu($this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.sites', ['view' => 'all']))
            ->assertOk()
            ->getContent());

        $this->assertStringContainsString(route('ops.sites.health', $site), $menu);
        $this->assertStringNotContainsString(route('ops.sites.deploy', $site), $menu);
        $this->assertStringNotContainsString('data-row-coolify', $menu);
    }

    public function test_row_health_check_returns_to_the_list(): void
    {
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['deamon_version' => '1.4.0'], 200)]);
        $site = Site::factory()->withSecrets()->create([
            'primary_domain' => 'row.example.test',
            'agent_base_url' => 'https://row.example.test',
            'status' => SiteStatus::Active,
        ]);
        $list = route('ops.sites', ['health' => 'unhealthy']);

        $this->actingAs($this->user(OpsRole::Operator))
            ->from($list)
            ->post(route('ops.sites.health', $site), ['return' => 'list'])
            ->assertRedirect($list);

        $this->actingAs($this->user(OpsRole::Operator))
            ->post(route('ops.sites.health', $site))
            ->assertRedirect(route('ops.sites.show', $site));
    }

    public function test_one_segment_holds_quick_filters_builtin_and_saved_views(): void
    {
        Site::factory()->create();
        $user = $this->userWithView('segview00001', 'Betalar', ['channel' => 'beta']);

        $html = $this->actingAs($user)
            ->get(route('ops.sites', ['view' => 'all']))
            ->assertOk()
            ->getContent();

        $this->assertSame(1, substr_count($html, 'data-sites-segment-wrap'));
        $this->assertStringNotContainsString('data-ops-saved-views', $html);
        $segment = $this->segment($html);

        foreach ([
            __('sites.saved_views.all'),
            __('sites.filter_panel.quick_unhealthy'),
            __('sites.filter_panel.quick_failed'),
            __('sites.filter_panel.quick_git'),
            __('sites.saved_views.error'),
            __('sites.saved_views.unpublished'),
            __('sites.saved_views.dockerfile'),
            'Betalar',
        ] as $label) {
            $this->assertStringContainsString(e($label), $segment);
        }

        // "All" appears once, not once per former row.
        $this->assertSame(1, substr_count($segment, '>'.e(__('sites.saved_views.all')).'</a>'));
        $this->assertStringContainsString('href="'.e(route('ops.sites', ['view' => 'all'])).'"', $segment);
        $this->assertStringContainsString('href="'.e(route('ops.sites', ['health' => 'unhealthy'])).'"', $segment);

        // Saved views keep an accessible delete control with a confirmation.
        $this->assertStringContainsString('action="'.route('ops.sites.list-views.destroy', 'segview00001').'"', $segment);
        $this->assertStringContainsString('aria-label="'.e(__('sites.saved_views.delete')).': Betalar"', $segment);
        $this->assertStringContainsString('data-confirm="'.e(__('sites.saved_views.delete_confirm', ['name' => 'Betalar'])).'"', $segment);

        // Built-ins cannot be deleted.
        $this->assertSame(1, substr_count($segment, 'plane-segment-delete-btn'));
    }

    public function test_segment_marks_exactly_the_active_preset(): void
    {
        Site::factory()->create();
        $user = $this->userWithView('segview00002', 'Betalar', ['channel' => 'beta']);

        $cases = [
            [['view' => 'all'], 'all'],
            [['health' => 'unhealthy'], 'quick-unhealthy'],
            [['deploy' => 'failed'], 'quick-failed'],
            [['view' => 'error', 'status' => 'error'], 'error'],
            [['view' => 'segview00002'], 'segview00002'],
        ];

        foreach ($cases as [$query, $expected]) {
            $segment = $this->segment($this->actingAs($user)->get(route('ops.sites', $query))->assertOk()->getContent());

            preg_match_all('/<a\s+class="plane-segment-item\s+is-active\s*"[^>]*data-ops-list-view="([^"]+)"[^>]*aria-current="page"/', $segment, $matches);
            $this->assertSame([$expected], $matches[1], 'Active preset for '.json_encode($query));
        }

        // A search on top of a quick filter is no longer that quick filter.
        $segment = $this->segment($this->actingAs($user)->get(route('ops.sites', ['health' => 'unhealthy', 'q' => 'x']))->getContent());
        $this->assertStringNotContainsString('aria-current', $segment);
    }

    public function test_a_fetched_region_carries_the_fresh_segment(): void
    {
        Site::factory()->create();
        $user = $this->userWithView('segview00003', 'Betalar', ['channel' => 'beta']);

        $html = $this->actingAs($user)
            ->withHeader(ListFragment::HEADER, ListFragment::VALUE)
            ->get(route('ops.sites', ['view' => 'segview00003']))
            ->assertOk()
            ->assertHeader('X-Ops-List-Region', '1')
            ->getContent();

        $this->assertStringContainsString('<template data-sites-segment-next>', $html);
        $this->assertMatchesRegularExpression('/data-ops-list-view="segview00003"\s+aria-current="page"/', $html);

        // The full page renders the toolbar copy only.
        $page = $this->flushHeaders()->actingAs($user)->get(route('ops.sites', ['view' => 'all']))->getContent();
        $this->assertStringNotContainsString('data-sites-segment-next', $page);
    }

    public function test_sites_overlay_lists_the_list_shortcuts_and_the_toggle_is_wired(): void
    {
        $html = $this->actingAs($this->user(OpsRole::Operator, 'tr'))
            ->get(route('ops.sites'))
            ->assertOk()
            ->assertSee(trans('ops.shortcuts.list_group', [], 'tr'), false)
            ->assertSee(trans('ops.shortcuts.filters', [], 'tr'), false)
            ->assertSee(trans('ops.shortcuts.clear_search', [], 'tr'), false)
            ->getContent();

        $this->assertStringContainsString('data-ops-shortcuts-key="f"', $html);
        $this->assertStringContainsString('data-ops-shortcut-filters', $html);
        $this->assertStringContainsString('aria-keyshortcuts="F"', $html);

        // Elsewhere the overlay does not promise a key that does nothing.
        $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.domains'))
            ->assertOk()
            ->assertDontSee('data-ops-shortcuts-key="f"', false);
    }

    /**
     * @return array{0: Site, 1: string}
     */
    private function coolifySite(): array
    {
        $connection = CoolifyConnection::factory()->create([
            'base_url' => 'https://coolify.example.test',
            'is_default' => true,
            'default_project_uuid' => 'z8ocg8k04ww8osssccc088c0',
            'default_environment_uuid' => 'sns276euzsz2fprqg3xgfz17',
        ]);
        $site = Site::factory()->create([
            'name' => 'Row Menu',
            'coolify_app_uuid' => 'a3p6sgysfwjqhv4yntth1n85',
            'coolify_connection_id' => $connection->id,
        ]);
        $url = (string) $site->coolifyUiUrl();
        $this->assertStringContainsString('/application/a3p6sgysfwjqhv4yntth1n85', $url);

        return [$site, $url];
    }

    private function rowMenu(string $html): string
    {
        $this->assertMatchesRegularExpression('/<details class="[^"]*plane-row-menu[^"]*"[\s\S]*?<\/details>/', $html);
        preg_match('/<details class="[^"]*plane-row-menu[^"]*"[\s\S]*?<\/details>/', $html, $match);

        return $match[0];
    }

    private function segment(string $html): string
    {
        preg_match('/<nav class="plane-segment"[\s\S]*?<\/nav>/', $html, $match);
        $this->assertNotEmpty($match, 'The preset segment must render.');

        return $match[0];
    }

    /**
     * @param  array<string, string>  $filters
     */
    private function userWithView(string $id, string $name, array $filters): User
    {
        $user = $this->user(OpsRole::Operator);
        $user->saveListPreference(SiteListColumns::LIST_KEY, [
            'views' => [[
                'id' => $id,
                'name' => $name,
                'filters' => $filters,
                'columns' => ['site', 'updated'],
                'sort' => ['key' => 'updated', 'dir' => 'desc'],
            ]],
        ]);

        return $user;
    }

    private function user(OpsRole $role, ?string $locale = null): User
    {
        $user = User::factory()->create($locale === null ? [] : ['locale' => $locale]);
        $user->assignRole($role->value);

        return $user;
    }
}
