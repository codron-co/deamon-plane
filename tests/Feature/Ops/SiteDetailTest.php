<?php

namespace Tests\Feature\Ops;

use App\Enums\Channel;
use App\Enums\DeploymentStatus;
use App\Enums\DeploymentTrigger;
use App\Enums\OpsRole;
use App\Enums\SiteStatus;
use App\Models\CoolifyConnection;
use App\Models\Deployment;
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
            ->assertSee('Overview', false)
            ->assertSee('Technical identifiers', false)
            ->assertSee(__('sites.detail.git'), false)
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
        $this->assertDoesNotMatchRegularExpression('/id="(overview|deployments|theme|admins|infrastructure|danger)"[^>]*\bhidden\b/', $html);
        $this->assertMatchesRegularExpression('/id="danger"/', $html);
        $this->assertMatchesRegularExpression('/aria-controls="danger"/', $html);
        $this->assertStringContainsString('class="site-hint"', $html);
        $this->assertStringContainsString('role="tooltip">'.__('sites.detail.overview_lede'), $html);
        $this->assertStringContainsString('role="tooltip">'.__('sites.detail.infrastructure_lede'), $html);
        $this->assertStringContainsString('role="tooltip">'.__('sites.themes.lede'), $html);
        $this->assertDoesNotMatchRegularExpression('/<p[^>]*>'.preg_quote(__('sites.detail.overview_lede'), '/').'/', $html);
        $this->assertStringNotContainsString('class="field-hint"', $html);
        $this->assertStringNotContainsString('class="site-section-kicker"', $html);
        $this->assertStringNotContainsString(__('sites.detail.operation'), $html);
        $this->assertStringNotContainsString(__('sites.detail.current_release'), $html);
        $this->assertStringNotContainsString(__('sites.detail.advanced'), $html);
        $this->assertStringNotContainsString(__('sites.themes.via_agent'), $html);
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
            // Bulk confirms name the selection scope, so the rendered body carries a count.
            ->assertSee('data-confirm="'.__('site_ops.bulk.confirm_auto_on', ['count' => 2]).'"', false)
            ->assertSee('data-confirm="'.__('site_ops.bulk.confirm_auto_off', ['count' => 2]).'"', false)
            ->assertSee(__('sites.columns.app'), false)
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

    public function test_show_recovers_stale_error_when_latest_deploy_finished(): void
    {
        $site = Site::factory()->create([
            'name' => 'Recover Site',
            'slug' => 'recover-site',
            'status' => SiteStatus::Error,
        ]);

        Deployment::factory()->create([
            'site_id' => $site->id,
            'channel' => Channel::Main,
            'trigger' => DeploymentTrigger::Create,
            'status' => DeploymentStatus::Finished,
            'started_at' => now()->subHour(),
            'finished_at' => now()->subMinutes(2),
            'error_message' => 'Timed out waiting for Coolify deployment.',
        ]);

        $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.sites.show', $site))
            ->assertOk()
            ->assertSee('status-chip status-active', false);

        $this->assertSame(SiteStatus::Active, $site->fresh()->status);
    }

    public function test_detail_offers_copy_for_site_id_and_coolify_uuids(): void
    {
        $site = Site::factory()->create([
            'name' => 'Copy Ids',
            'coolify_app_uuid' => 'app-copy-uuid-123456',
            'coolify_server_uuid' => 'srv-copy-uuid',
            'coolify_project_uuid' => 'prj-copy-uuid',
            'coolify_environment_uuid' => 'env-copy-uuid',
        ]);

        $html = $this->actingAs($this->user(OpsRole::Operator, 'tr'))
            ->get(route('ops.sites.show', $site))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-copy-value="'.$site->id.'"', $html);
        $this->assertStringContainsString('data-copy-value="app-copy-uuid-123456"', $html);
        $this->assertStringContainsString('data-copy-value="srv-copy-uuid"', $html);
        $this->assertStringContainsString('data-copy-value="prj-copy-uuid"', $html);
        $this->assertStringContainsString('data-copy-value="env-copy-uuid"', $html);
        $this->assertStringContainsString(trans('sites.detail.copy_site_id', [], 'tr'), $html);
        $this->assertStringContainsString(trans('sites.detail.copy_app_uuid', [], 'tr'), $html);
        $this->assertStringContainsString(trans('sites.detail.site_id', [], 'tr'), $html);
        $this->assertStringNotContainsString(trans('sites.detail.copy_site_id', [], 'en'), $html);
    }

    public function test_detail_offers_copy_for_the_coolify_deep_link(): void
    {
        $connection = CoolifyConnection::factory()->create([
            'base_url' => 'https://dev.codron.cloud',
            'is_default' => true,
            'default_project_uuid' => 'z8ocg8k04ww8osssccc088c0',
            'default_environment_uuid' => 'sns276euzsz2fprqg3xgfz17',
        ]);
        $site = Site::factory()->create([
            'name' => 'Copy Link',
            'coolify_app_uuid' => 'a3p6sgysfwjqhv4yntth1n85',
            'coolify_connection_id' => $connection->id,
            'coolify_project_uuid' => 'z8ocg8k04ww8osssccc088c0',
            'coolify_environment_uuid' => 'i0sw4kk0cogg4o08oscwcssk',
        ]);
        $url = $site->coolifyUiUrl();

        $html = $this->actingAs($this->user(OpsRole::Operator, 'tr'))
            ->get(route('ops.sites.show', $site))
            ->assertOk()
            ->getContent();

        $this->assertNotNull($url);
        $this->assertStringContainsString('data-copy-value="'.$url.'"', $html);
        $this->assertStringContainsString(trans('sites.detail.copy_coolify_url', [], 'tr'), $html);
        $this->assertStringContainsString(trans('sites.detail.coolify_url', [], 'tr'), $html);
        $this->assertStringNotContainsString(trans('sites.detail.copy_coolify_url', [], 'en'), $html);
        $this->assertStringContainsString('href="'.$url.'"', $html);
    }

    public function test_detail_does_not_offer_copy_for_missing_coolify_uuids(): void
    {
        $site = Site::factory()->create([
            'name' => 'No Uuid',
            'coolify_app_uuid' => null,
            'coolify_server_uuid' => null,
            'coolify_project_uuid' => null,
            'coolify_environment_uuid' => null,
        ]);

        $html = $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.sites.show', $site))
            ->assertOk()
            ->assertSee(__('sites.detail.copy_site_id'), false)
            ->getContent();

        $this->assertStringContainsString('data-copy-value="'.$site->id.'"', $html);
        $this->assertStringNotContainsString('data-copy-value=""', $html);
        $this->assertStringNotContainsString(__('sites.detail.copy_app_uuid'), $html);
        $this->assertStringNotContainsString(__('sites.detail.copy_coolify_url'), $html);
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

    private function user(OpsRole $role, ?string $locale = null): User
    {
        $user = User::factory()->create($locale === null ? [] : ['locale' => $locale]);
        $user->assignRole($role->value);

        return $user;
    }
}
