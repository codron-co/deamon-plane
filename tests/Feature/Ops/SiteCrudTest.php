<?php

namespace Tests\Feature\Ops;

use App\Enums\Channel;
use App\Enums\OpsRole;
use App\Enums\SiteStatus;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteCrudTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_guest_is_redirected_from_sites_index(): void
    {
        $this->get(route('ops.sites'))->assertRedirect(route('login'));
    }

    public function test_operator_sees_empty_index(): void
    {
        $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.sites'))
            ->assertOk()
            ->assertSee('No sites yet', false)
            ->assertSee('New site', false);
    }

    public function test_operator_can_create_a_draft_site_without_secrets(): void
    {
        $operator = $this->user(OpsRole::Operator);

        $this->actingAs($operator)
            ->post(route('ops.sites.store'), $this->validPayload())
            ->assertRedirect();

        $site = Site::query()->where('slug', 'izyem')->first();

        $this->assertNotNull($site);
        $this->assertSame(SiteStatus::Draft, $site->status);
        $this->assertSame(Channel::Beta, $site->channel);
        $this->assertSame('shop.izyem.example.test', $site->primary_domain);
        $this->assertSame('no48ksggg0k8sk4o4w08gks8', $site->coolify_server_uuid);
        $this->assertNull($site->app_key_encrypted);
        $this->assertNull($site->agent_secret_encrypted);
        $this->assertNull($site->coolify_app_uuid);

        $this->assertDatabaseHas('site_domains', [
            'site_id' => $site->id,
            'domain' => 'shop.izyem.example.test',
            'is_primary' => 1,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $operator->id,
            'action' => 'site.created',
            'subject_id' => $site->id,
        ]);
    }

    public function test_create_rejects_channel_outside_allowlist(): void
    {
        $this->actingAs($this->user(OpsRole::Operator))
            ->post(route('ops.sites.store'), $this->validPayload([
                'channel' => 'nightly',
            ]))
            ->assertSessionHasErrors('channel');

        $this->assertDatabaseCount('sites', 0);
    }

    public function test_create_rejects_duplicate_slug_and_domain(): void
    {
        Site::factory()->create([
            'slug' => 'izyem',
            'primary_domain' => 'shop.izyem.example.test',
        ]);

        $this->actingAs($this->user(OpsRole::Operator))
            ->post(route('ops.sites.store'), $this->validPayload())
            ->assertSessionHasErrors(['slug', 'domain']);
    }

    public function test_status_and_secrets_cannot_be_mass_assigned_from_create(): void
    {
        $this->actingAs($this->user(OpsRole::Operator))
            ->post(route('ops.sites.store'), $this->validPayload([
                'status' => SiteStatus::Active->value,
                'app_key_encrypted' => 'base64:'.base64_encode(str_repeat('a', 32)),
                'agent_secret_encrypted' => 'should-not-persist',
            ]))
            ->assertRedirect();

        $site = Site::query()->where('slug', 'izyem')->first();

        $this->assertNotNull($site);
        $this->assertSame(SiteStatus::Draft, $site->status);
        $this->assertNull($site->app_key_encrypted);
        $this->assertNull($site->agent_secret_encrypted);
    }

    public function test_operator_can_update_draft_and_sync_primary_domain(): void
    {
        $operator = $this->user(OpsRole::Operator);
        $site = Site::factory()->create([
            'slug' => 'izyem',
            'name' => 'Izyem',
            'primary_domain' => 'old.izyem.example.test',
            'channel' => Channel::Main,
        ]);
        $site->domains()->create([
            'domain' => 'old.izyem.example.test',
            'is_primary' => true,
        ]);

        $this->actingAs($operator)
            ->put(route('ops.sites.update', $site), $this->validPayload([
                'name' => 'Izyem Shop',
                'domain' => 'new.izyem.example.test',
                'channel' => 'alpha',
                'notes' => 'Moved hostname',
            ]))
            ->assertRedirect(route('ops.sites.show', $site));

        $site->refresh();

        $this->assertSame('Izyem Shop', $site->name);
        $this->assertSame('new.izyem.example.test', $site->primary_domain);
        $this->assertSame(Channel::Alpha, $site->channel);
        $this->assertSame(SiteStatus::Draft, $site->status);
        $this->assertSame('new.izyem.example.test', $site->primaryDomainRecord?->domain);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'site.updated',
            'subject_id' => $site->id,
            'actor_user_id' => $operator->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'site.domain_changed',
            'subject_id' => $site->id,
        ]);
    }

    public function test_non_draft_site_keeps_slug_and_channel_on_update(): void
    {
        $site = Site::factory()->create([
            'slug' => 'locked-site',
            'name' => 'Locked',
            'primary_domain' => 'locked.example.test',
            'channel' => Channel::Main,
            'status' => SiteStatus::Active,
        ]);

        $this->actingAs($this->user(OpsRole::Operator))
            ->put(route('ops.sites.update', $site), $this->validPayload([
                'slug' => 'tampered',
                'name' => 'Locked renamed',
                'domain' => 'locked.example.test',
                'channel' => 'alpha',
            ]))
            ->assertRedirect();

        $site->refresh();

        $this->assertSame('locked-site', $site->slug);
        $this->assertSame(Channel::Main, $site->channel);
        $this->assertSame('Locked renamed', $site->name);
        $this->assertSame(SiteStatus::Active, $site->status);
    }

    public function test_operator_can_soft_delete_a_site(): void
    {
        $operator = $this->user(OpsRole::Operator);
        $site = Site::factory()->create(['slug' => 'gone']);

        $this->actingAs($operator)
            ->delete(route('ops.sites.destroy', $site))
            ->assertRedirect(route('ops.sites'));

        $this->assertSoftDeleted('sites', ['id' => $site->id]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'site.deleted',
            'subject_id' => $site->id,
            'actor_user_id' => $operator->id,
        ]);
    }

    public function test_viewer_can_read_but_cannot_write(): void
    {
        $site = Site::factory()->create([
            'name' => 'Readable Site',
            'slug' => 'readable',
        ]);
        $viewer = $this->user(OpsRole::Viewer);

        $this->actingAs($viewer)->get(route('ops.sites'))->assertOk()->assertSee('Readable Site', false);
        $this->actingAs($viewer)->get(route('ops.sites.edit', $site))->assertOk()->assertSee('Viewer role is read-only', false);
        $this->actingAs($viewer)->get(route('ops.sites.create'))->assertForbidden();
        $this->actingAs($viewer)->post(route('ops.sites.store'), $this->validPayload())->assertForbidden();
        $this->actingAs($viewer)->put(route('ops.sites.update', $site), $this->validPayload([
            'slug' => $site->slug,
            'domain' => $site->primary_domain,
        ]))->assertForbidden();
        $this->actingAs($viewer)->delete(route('ops.sites.destroy', $site))->assertForbidden();

        $this->assertNotSoftDeleted('sites', ['id' => $site->id]);
    }

    public function test_super_admin_can_create_and_delete(): void
    {
        $admin = $this->user(OpsRole::SuperAdmin);

        $this->actingAs($admin)
            ->post(route('ops.sites.store'), $this->validPayload())
            ->assertRedirect();

        $site = Site::query()->where('slug', 'izyem')->first();
        $this->assertNotNull($site);

        $this->actingAs($admin)
            ->delete(route('ops.sites.destroy', $site))
            ->assertRedirect(route('ops.sites'));

        $this->assertSoftDeleted('sites', ['id' => $site->id]);
    }

    public function test_index_filters_by_search_channel_and_status(): void
    {
        Site::factory()->create([
            'name' => 'Alpha Shop',
            'slug' => 'alpha-shop',
            'primary_domain' => 'alpha.example.test',
            'channel' => Channel::Alpha,
            'status' => SiteStatus::Draft,
        ]);
        Site::factory()->create([
            'name' => 'Main Store',
            'slug' => 'main-store',
            'primary_domain' => 'main.example.test',
            'channel' => Channel::Main,
            'status' => SiteStatus::Active,
        ]);

        $operator = $this->user(OpsRole::Operator);

        $this->actingAs($operator)
            ->get(route('ops.sites', ['q' => 'alpha']))
            ->assertOk()
            ->assertSee('Alpha Shop', false)
            ->assertDontSee('Main Store', false);

        $this->actingAs($operator)
            ->get(route('ops.sites', ['channel' => 'main']))
            ->assertOk()
            ->assertSee('Main Store', false)
            ->assertDontSee('Alpha Shop', false);

        $this->actingAs($operator)
            ->get(route('ops.sites', ['status' => 'draft']))
            ->assertOk()
            ->assertSee('Alpha Shop', false)
            ->assertDontSee('Main Store', false);
    }

    public function test_index_and_edit_show_dockerfile_pack_warning(): void
    {
        $legacy = Site::factory()->dockerfilePack()->create([
            'name' => 'Legacy Dockerfile Site',
            'slug' => 'legacy-df',
        ]);
        $compose = Site::factory()->create([
            'name' => 'Compose Site',
            'slug' => 'compose-ok',
            'notes' => null,
        ]);

        $operator = $this->user(OpsRole::Operator);

        $this->actingAs($operator)
            ->get(route('ops.sites'))
            ->assertOk()
            ->assertSee('Legacy Dockerfile Site', false)
            ->assertSee(__('ops.dockerfile_chip'), false)
            ->assertSee('Compose Site', false);

        $this->actingAs($operator)
            ->get(route('ops.sites.edit', $legacy))
            ->assertOk()
            ->assertSee(__('sites.edit.dockerfile'), false);

        $this->actingAs($operator)
            ->get(route('ops.sites.edit', $compose))
            ->assertOk()
            ->assertDontSee(__('ops.dockerfile_chip'), false)
            ->assertDontSee(__('sites.edit.dockerfile'), false);
    }

    public function test_create_form_renders_grouped_configuration_sections(): void
    {
        $operatorHtml = $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.sites.create'))
            ->assertOk()
            ->assertSee('id="site-identity-heading"', false)
            ->assertSee('id="site-domain-heading"', false)
            ->assertSee('id="site-placement-heading"', false)
            ->assertSee('id="site-git-heading"', false)
            ->assertSee('id="site-notes-heading"', false)
            ->assertSee(__('sites.form.placement'), false)
            ->assertSee(__('sites.form.repo_branch'), false)
            ->assertSee('name="slug"', false)
            ->assertSee('name="domain"', false)
            ->assertSee('name="channel"', false)
            ->assertSee('name="coolify_connection_id"', false)
            ->assertDontSee('name="advanced_server_uuid"', false)
            ->getContent();

        $sectionOrder = [
            strpos($operatorHtml, 'id="site-identity-heading"'),
            strpos($operatorHtml, 'id="site-domain-heading"'),
            strpos($operatorHtml, 'id="site-placement-heading"'),
            strpos($operatorHtml, 'id="site-git-heading"'),
            strpos($operatorHtml, 'id="site-notes-heading"'),
        ];

        foreach ($sectionOrder as $position) {
            $this->assertNotFalse($position);
        }

        $sorted = $sectionOrder;
        sort($sorted);
        $this->assertSame($sorted, $sectionOrder);

        $adminHtml = $this->actingAs($this->user(OpsRole::SuperAdmin))
            ->get(route('ops.sites.create'))
            ->assertOk()
            ->assertSee(__('sites.form.advanced_summary'), false)
            ->assertSee(__('sites.form.advanced_warning'), false)
            ->assertSee('name="advanced_server_uuid"', false)
            ->getContent();

        $this->assertStringContainsString('<details class="coolify-advanced ops-form-section"', $adminHtml);
        $this->assertStringNotContainsString('<details class="coolify-advanced ops-form-section" open', $adminHtml);
    }

    public function test_edit_form_is_configuration_only(): void
    {
        $site = Site::factory()->create([
            'name' => 'Config Only',
            'slug' => 'config-only',
            'coolify_app_uuid' => 'w553nh3qtdtf9520oazu9ckv',
        ]);

        $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.sites.edit', $site))
            ->assertOk()
            ->assertSee('id="site-identity-heading"', false)
            ->assertSee('id="site-domain-heading"', false)
            ->assertSee('id="site-placement-heading"', false)
            ->assertSee('id="site-git-heading"', false)
            ->assertSee(__('sites.form.placement'), false)
            ->assertSee(__('ops.actions.save_changes'), false)
            ->assertSee('name="slug"', false)
            ->assertSee('name="channel"', false)
            ->assertDontSee('id="coolify-ops-heading"', false)
            ->assertDontSee(__('site_ops.auto_deploy.on_button'), false)
            ->assertDontSee(__('site_ops.pin.pin_button'), false)
            ->assertDontSee(__('site_ops.pack.button'), false)
            ->assertDontSee(route('ops.sites.auto-deploy', $site), false)
            ->assertDontSee(route('ops.sites.pin', $site), false)
            ->assertDontSee(route('ops.sites.compose', $site), false);
    }

    public function test_create_validation_errors_render_near_fields_and_at_form_level(): void
    {
        $this->actingAs($this->user(OpsRole::Operator))
            ->from(route('ops.sites.create'))
            ->followingRedirects()
            ->post(route('ops.sites.store'), [
                'slug' => '',
                'name' => '',
                'domain' => '',
                'channel' => 'beta',
            ])
            ->assertOk()
            ->assertSee(__('sites.form.errors'), false)
            ->assertSee('class="field-error"', false)
            ->assertSee(__('sites.form.identity'), false);
    }

    public function test_index_and_edit_render_confirm_modal_for_destroy(): void
    {
        $site = Site::factory()->create(['name' => 'Modal Site']);

        $html = $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.sites.show', $site))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-ops-confirm-modal', $html);
        $this->assertStringContainsString('data-confirm=', $html);
        $this->assertStringNotContainsString('window.confirm', $html);

        $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.sites.show', $site))
            ->assertOk()
            ->assertSee('data-confirm=', false)
            ->assertSee(__('sites.menu.soft_delete'), false)
            ->assertSee(__('sites.menu.hard_delete'), false)
            ->assertSee('data-ops-action-menu', false);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'slug' => 'izyem',
            'name' => 'Izyem',
            'domain' => 'shop.izyem.example.test',
            'channel' => 'beta',
            'coolify_server_uuid' => 'no48ksggg0k8sk4o4w08gks8',
            'notes' => 'Draft only',
        ], $overrides);
    }

    private function user(OpsRole $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);

        return $user;
    }
}
