<?php

namespace Tests\Feature\Ops;

use App\Enums\Channel;
use App\Enums\OpsRole;
use App\Enums\SiteStatus;
use App\Models\Site;
use App\Models\Theme;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteDetailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_operator_can_open_site_detail_separately_from_edit(): void
    {
        $site = Site::factory()->create([
            'name' => 'Detail Site',
            'slug' => 'detail-site',
            'channel' => Channel::Alpha,
            'last_health_payload' => ['deamon_version' => '1.8.4'],
        ]);

        $html = $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.sites.show', $site))
            ->assertOk()
            ->assertSee('Operations center', false)
            ->assertSee('Overview', false)
            ->assertSee('Live release', false)
            ->assertSee('Technical identifiers', false)
            ->assertSee('href="#deployments"', false)
            ->assertSee('href="#infrastructure"', false)
            ->assertSee('href="#danger"', false)
            ->assertSee('role="tablist"', false)
            ->assertSee('role="tab"', false)
            ->assertSee('role="tabpanel"', false)
            ->assertSee('aria-controls="overview"', false)
            ->assertSee('aria-selected="true"', false)
            ->assertSee('aria-selected="false"', false)
            ->assertSee('data-site-tabs', false)
            ->assertSee('data-site-panel', false)
            ->assertSee('alpha', false)
            ->assertSee('1.8.4', false)
            ->assertSee('data-favicon-host="'.$site->primary_domain.'"', false)
            ->assertSee('href="https://'.$site->primary_domain.'"', false)
            ->assertSee('href="https://'.$site->primary_domain.'/admin"', false)
            ->assertSee(__('sites.menu.home'), false)
            ->assertSee(__('sites.menu.admin'), false)
            ->assertSee(__('sites.menu.sync'), false)
            ->assertSee(__('sites.menu.settings'), false)
            ->assertSee(__('sites.menu.edit'), false)
            ->assertSee('data-ops-action-menu', false)
            ->assertSee(route('ops.sites.edit', $site), false)
            ->assertSee('js/ops-ui.js', false)
            ->getContent();

        $this->assertNotSame(route('ops.sites.show', $site), route('ops.sites.edit', $site));
        $this->assertDoesNotMatchRegularExpression('/data-site-panel[^>]*\bhidden\b/', $html);
        $this->assertDoesNotMatchRegularExpression('/id="(overview|deployments|theme|infrastructure|danger)"[^>]*\bhidden\b/', $html);
        $this->assertMatchesRegularExpression('/id="danger"/', $html);
        $this->assertMatchesRegularExpression('/aria-controls="danger"/', $html);
        $this->assertStringContainsString('class="site-hint"', $html);
        $this->assertStringNotContainsString('class="field-hint"', $html);
        $this->assertStringNotContainsString(__('sites.detail.no_action'), $html);
        $this->assertStringNotContainsString(__('sites.detail.no_action_hint'), $html);
        $this->assertStringNotContainsString('class="site-open-links"', $html);
    }

    public function test_sites_index_targets_detail_and_displays_branch_with_reported_version(): void
    {
        $site = Site::factory()->create([
            'name' => 'Versioned Site',
            'slug' => 'versioned-site',
            'primary_domain' => 'versioned.example.test',
            'channel' => Channel::Beta,
            'last_health_payload' => ['version' => '2.3.1'],
        ]);
        $unknown = Site::factory()->create([
            'name' => 'Unversioned Site',
            'slug' => 'unversioned-site',
            'primary_domain' => 'unversioned.example.test',
            'last_health_payload' => null,
        ]);

        $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.sites'))
            ->assertOk()
            ->assertSee('Repo branch', false)
            ->assertSee('2.3.1', false)
            ->assertSee(__('sites.version_unknown'), false)
            ->assertSee('name="q"', false)
            ->assertSee('name="channel"', false)
            ->assertSee('name="status"', false)
            ->assertSee('data-ops-list-toolbar', false)
            ->assertSee('data-favicon-host="'.$site->primary_domain.'"', false)
            ->assertSee('data-favicon-host="'.$unknown->primary_domain.'"', false)
            ->assertSee('data-href="'.route('ops.sites.show', $site).'"', false)
            ->assertSee('class="ops-row-actions"', false)
            ->assertSee('href="https://'.$site->primary_domain.'"', false)
            ->assertSee('aria-label="'.__('sites.columns.open_live', ['domain' => $site->primary_domain]).'"', false)
            ->assertSee(route('ops.sites.edit', $site), false)
            ->assertDontSee('data-href="'.route('ops.sites.edit', $site).'"', false)
            ->assertSee('data-confirm="'.__('site_ops.bulk.confirm_auto_toggle').'"', false)
            ->assertSee(__('sites.columns.live'), false)
            ->assertSee(__('sites.live.sync'), false)
            ->assertSee('data-confirm="'.__('sites.detail.sync_confirm_all').'"', false);
    }

    public function test_theme_surfaces_use_agent_health_when_plane_has_no_installation(): void
    {
        Theme::factory()->publicCatalog()->create([
            'theme_id' => 'izyem',
            'name' => 'Izyem',
        ]);
        $site = Site::factory()->create([
            'name' => 'Health Theme Site',
            'slug' => 'health-theme-site',
            'primary_domain' => 'health-theme.example.test',
            'last_health_payload' => ['active_theme_id' => 'izyem', 'deamon_version' => '1.9.0'],
        ]);

        $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.sites.show', $site))
            ->assertOk()
            ->assertSee('izyem', false)
            ->assertSee('Izyem', false)
            ->assertSee(__('sites.themes.via_health'), false)
            ->assertDontSee(__('sites.themes.empty'), false)
            ->assertDontSee(__('sites.themes.empty_title'), false);

        $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.sites'))
            ->assertOk()
            ->assertSee('izyem', false);
    }

    public function test_mutating_site_actions_ask_for_confirm(): void
    {
        $site = Site::factory()->create([
            'name' => 'Confirm Site',
            'slug' => 'confirm-site',
            'status' => SiteStatus::Draft,
        ]);

        $html = $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.sites.show', $site))
            ->assertOk()
            ->assertSee('data-confirm="'.__('sites.provision.confirm', ['name' => $site->name]).'"', false)
            ->getContent();

        $this->assertStringNotContainsString('window.confirm', $html);
    }

    public function test_viewer_can_open_detail_but_does_not_get_edit_or_danger_tab(): void
    {
        $site = Site::factory()->create([
            'name' => 'Read Only Detail',
            'slug' => 'read-only-detail',
        ]);

        $html = $this->actingAs($this->user(OpsRole::Viewer))
            ->get(route('ops.sites.show', $site))
            ->assertOk()
            ->assertSee('Read Only Detail', false)
            ->assertDontSee('Edit site', false)
            ->assertDontSee(__('sites.menu.soft_delete'), false)
            ->assertDontSee(__('sites.menu.hard_delete'), false)
            ->assertDontSee('href="#danger"', false)
            ->getContent();

        $this->assertDoesNotMatchRegularExpression('/id="danger"/', $html);
        $this->assertDoesNotMatchRegularExpression('/aria-controls="danger"/', $html);
        $this->assertDoesNotMatchRegularExpression('/data-site-panel[^>]*\bhidden\b/', $html);
    }

    private function user(OpsRole $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);

        return $user;
    }
}
