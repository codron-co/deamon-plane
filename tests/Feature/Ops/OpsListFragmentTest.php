<?php

namespace Tests\Feature\Ops;

use App\Enums\Channel;
use App\Enums\OpsRole;
use App\Enums\SiteStatus;
use App\Enums\ThemeVisibility;
use App\Models\CoolifyConnection;
use App\Models\CoolifyServer;
use App\Models\MailServer;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\Theme;
use App\Models\User;
use App\Support\Lists\ListFragment;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The list routes answer twice: a full page for a normal visit and only the table
 * region for the async toolbar. Both must apply the same filters, sort and
 * authorization, or the in-place update would show something the page would not.
 */
class OpsListFragmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_sites_fragment_returns_the_table_region_without_the_page_shell(): void
    {
        Site::factory()->create(['name' => 'Fragment Site']);

        $response = $this->actingAs($this->user(OpsRole::Operator))
            ->withHeader(ListFragment::HEADER, ListFragment::VALUE)
            ->get(route('ops.sites'))
            ->assertOk()
            ->assertHeader('X-Ops-List-Region', '1')
            ->assertHeader('Vary', ListFragment::HEADER)
            ->assertSee('Fragment Site')
            ->assertSee('sort=publish', false);

        $html = $response->getContent();

        $this->assertStringNotContainsString('<!DOCTYPE html>', $html);
        $this->assertStringNotContainsString('ops-sidebar', $html, 'The region must not repeat the app shell.');
        $this->assertStringNotContainsString('data-ops-list-toolbar', $html, 'The toolbar stays put so typing is never interrupted.');
    }

    public function test_a_normal_sites_visit_still_renders_the_whole_page(): void
    {
        $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.sites'))
            ->assertOk()
            ->assertHeader('X-Ops-List-Region', '0')
            ->assertSee('ops-sidebar', false)
            ->assertSee('data-ops-list-region', false)
            ->assertSee('data-ops-list-toolbar', false);
    }

    public function test_sites_fragment_applies_search_filters_and_sort(): void
    {
        Site::factory()->create(['name' => 'Alpha Site', 'channel' => Channel::Alpha]);
        Site::factory()->create(['name' => 'Beta Site', 'channel' => Channel::Beta]);

        $filtered = $this->actingAs($this->user(OpsRole::Operator))
            ->withHeader(ListFragment::HEADER, ListFragment::VALUE)
            ->get(route('ops.sites', ['q' => 'Alpha', 'channel' => 'alpha']))
            ->assertOk()
            ->assertSee('Alpha Site')
            ->assertDontSee('Beta Site');

        // The filter travels with the region, so "apply to everything matching the
        // filter" bulk actions cannot fall back to an unfiltered fleet.
        $filtered->assertSee('name="filter_q" value="Alpha"', false);

        $descending = $this->actingAs($this->user(OpsRole::Operator))
            ->withHeader(ListFragment::HEADER, ListFragment::VALUE)
            ->get(route('ops.sites', ['sort' => 'site', 'dir' => 'desc']))
            ->assertOk()
            ->assertSee('aria-sort="descending"', false)
            ->getContent();

        $this->assertLessThan(strpos($descending, 'Alpha Site'), strpos($descending, 'Beta Site'));
    }

    public function test_domains_themes_mail_servers_and_coolify_inventory_answer_the_same_fragment_contract(): void
    {
        $site = Site::factory()->create(['name' => 'Bound Site', 'primary_domain' => 'bound.example.test']);
        SiteDomain::factory()->create(['site_id' => $site->id, 'domain' => 'fragment.example.test']);
        Theme::factory()->create(['theme_id' => 'fragment-theme', 'visibility' => ThemeVisibility::PublicCatalog]);
        MailServer::factory()->create(['name' => 'Fragment Mail']);
        $connection = CoolifyConnection::factory()->create(['name' => 'Fragment Coolify']);
        CoolifyServer::query()->create([
            'coolify_connection_id' => $connection->id,
            'uuid' => 'frag-1',
            'name' => 'Fragment Edge',
            'ip' => '10.9.8.7',
            'is_active' => true,
        ]);

        $user = $this->user(OpsRole::Operator);

        $this->actingAs($user)
            ->withHeader(ListFragment::HEADER, ListFragment::VALUE)
            ->get(route('ops.domains', ['q' => 'fragment']))
            ->assertOk()
            ->assertHeader('X-Ops-List-Region', '1')
            ->assertSee('fragment.example.test')
            ->assertDontSee('ops-sidebar', false);

        $this->actingAs($user)
            ->withHeader(ListFragment::HEADER, ListFragment::VALUE)
            ->get(route('ops.themes', ['q' => 'fragment-theme']))
            ->assertOk()
            ->assertHeader('X-Ops-List-Region', '1')
            ->assertSee('fragment-theme')
            ->assertDontSee('ops-sidebar', false);

        $this->actingAs($user)
            ->withHeader(ListFragment::HEADER, ListFragment::VALUE)
            ->get(route('ops.mail-servers.index', ['q' => 'Fragment Mail']))
            ->assertOk()
            ->assertHeader('X-Ops-List-Region', '1')
            ->assertSee('Fragment Mail')
            ->assertDontSee('ops-sidebar', false)
            ->assertDontSee('ops-mail-state-row', false);

        $this->actingAs($user)
            ->withHeader(ListFragment::HEADER, ListFragment::VALUE)
            ->get(route('ops.coolify.show', [$connection, 'q' => 'Fragment Edge']))
            ->assertOk()
            ->assertHeader('X-Ops-List-Region', '1')
            ->assertSee('Fragment Edge')
            ->assertDontSee('ops-sidebar', false)
            ->assertDontSee('coolify-overview-heading', false);

        Site::factory()->create([
            'name' => 'Fragment Fleet',
            'status' => SiteStatus::Error,
        ]);

        $this->actingAs($user)
            ->withHeader(ListFragment::HEADER, ListFragment::VALUE)
            ->get(route('ops.fleet', ['q' => 'Fragment Fleet']))
            ->assertOk()
            ->assertHeader('X-Ops-List-Region', '1')
            ->assertSee('Fragment Fleet')
            ->assertDontSee('ops-sidebar', false)
            ->assertDontSee('data-ops-list-toolbar', false)
            ->assertDontSee(__('fleet.kpis.aria'));
    }

    public function test_the_clear_filters_link_ships_hidden_so_the_toolbar_can_reveal_it(): void
    {
        $user = $this->user(OpsRole::Operator);

        $this->actingAs($user)
            ->get(route('ops.sites'))
            ->assertOk()
            ->assertSee('data-ops-list-clear hidden>', false);

        $this->actingAs($user)
            ->get(route('ops.sites', ['channel' => 'alpha']))
            ->assertOk()
            ->assertSee('data-ops-list-clear>', false)
            ->assertDontSee('data-ops-list-clear hidden>', false);
    }

    public function test_every_list_pages_pagination_uses_the_shared_ops_pager(): void
    {
        Site::factory()->count(26)->create();
        SiteDomain::factory()->count(51)->create();
        Theme::factory()->count(26)->create();
        MailServer::factory()->count(26)->create();
        $user = $this->user(OpsRole::Operator);

        foreach (['ops.sites', 'ops.domains', 'ops.themes', 'ops.mail-servers.index'] as $route) {
            $this->actingAs($user)
                ->withHeader(ListFragment::HEADER, ListFragment::VALUE)
                ->get(route($route))
                ->assertOk()
                ->assertSee('class="ops-pagination"', false)
                ->assertSee('data-ops-list-focus="page:next"', false);
        }
    }

    public function test_the_fragment_header_never_bypasses_authentication(): void
    {
        $this->withHeader(ListFragment::HEADER, ListFragment::VALUE)
            ->get(route('ops.sites'))
            ->assertRedirect(route('login'));
    }

    private function user(OpsRole $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);

        return $user;
    }
}
