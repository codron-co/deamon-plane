<?php

namespace Tests\Feature\Ops;

use App\Enums\CmsPublishStatus;
use App\Enums\OpsRole;
use App\Enums\SiteStatus;
use App\Models\Site;
use App\Models\User;
use App\Services\Agent\ControlPlaneAgentContract;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SitePublishStateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_publish_state_is_a_separate_column_from_the_coolify_lifecycle(): void
    {
        // The blind spot this feature exists for: Coolify says active, the CMS says draft.
        $site = Site::factory()->withSecrets()->create([
            'name' => 'Yayin Testi',
            'status' => SiteStatus::Active,
            'cms_site_status' => CmsPublishStatus::Draft,
        ]);

        $html = $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.sites'))
            ->assertOk()
            ->assertSee(__('sites.columns.publish'), false)
            ->assertSee(__('sites.publish.states.draft'), false)
            ->getContent();

        $this->assertStringContainsString('data-publish-chip', $html);
        // Plane lifecycle "Aktif" and CMS publish "Taslak" must both be readable.
        $this->assertStringContainsString(SiteStatus::Active->label(), $html);
    }

    public function test_a_site_the_agent_never_reported_reads_unknown_not_draft(): void
    {
        Site::factory()->create(['cms_site_status' => null]);

        $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.sites'))
            ->assertOk()
            ->assertSee(__('sites.publish.states.unknown'), false);
    }

    public function test_operator_publishes_a_site_through_the_signed_agent(): void
    {
        $site = Site::factory()->withSecrets()->create([
            'status' => SiteStatus::Active,
            'cms_site_status' => CmsPublishStatus::Draft,
        ]);

        Http::fake([
            '*'.ControlPlaneAgentContract::SITE_STATUS_PATH => Http::response([
                'ok' => true,
                'site_status' => 'published',
                'label' => 'Yayında',
                'changed' => true,
            ]),
        ]);

        $this->actingAs($this->user(OpsRole::Operator))
            ->from(route('ops.sites.show', $site))
            ->post(route('ops.sites.publish-status', $site), ['publish_status' => 'published'])
            ->assertRedirect(route('ops.sites.show', $site))
            ->assertSessionHas('status');

        $this->assertSame(CmsPublishStatus::Published, $site->fresh()->publishStatus());
        $this->assertNotNull($site->fresh()->cms_site_status_at);
        $this->assertDatabaseHas('audit_logs', [
            'subject_id' => $site->id,
            'action' => 'site.published',
        ]);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), ControlPlaneAgentContract::SITE_STATUS_PATH)
                && $request->hasHeader(ControlPlaneAgentContract::HEADER_SIGNATURE)
                && $request->data()['status'] === 'published';
        });
    }

    public function test_the_mirror_records_what_the_cms_confirmed_not_what_plane_asked_for(): void
    {
        $site = Site::factory()->withSecrets()->create(['cms_site_status' => null]);

        // A CMS that refuses to publish still answers with its real state.
        Http::fake([
            '*' => Http::response(['ok' => true, 'site_status' => 'draft', 'changed' => false]),
        ]);

        $this->actingAs($this->user(OpsRole::Operator))
            ->post(route('ops.sites.publish-status', $site), ['publish_status' => 'published'])
            ->assertRedirect();

        $this->assertSame(CmsPublishStatus::Draft, $site->fresh()->publishStatus());
    }

    public function test_a_failed_agent_call_leaves_the_mirror_untouched(): void
    {
        $site = Site::factory()->withSecrets()->create([
            'cms_site_status' => CmsPublishStatus::Draft,
        ]);

        Http::fake(['*' => Http::response(['message' => 'kapali'], 500)]);

        $this->actingAs($this->user(OpsRole::Operator))
            ->from(route('ops.sites.show', $site))
            ->post(route('ops.sites.publish-status', $site), ['publish_status' => 'published'])
            ->assertRedirect(route('ops.sites.show', $site))
            ->assertSessionHas('error');

        $this->assertSame(CmsPublishStatus::Draft, $site->fresh()->publishStatus());
        $this->assertDatabaseMissing('audit_logs', [
            'subject_id' => $site->id,
            'action' => 'site.published',
        ]);
    }

    public function test_an_old_cms_without_the_route_gets_an_actionable_message(): void
    {
        $site = Site::factory()->withSecrets()->create(['cms_site_status' => null]);

        Http::fake(['*' => Http::response('Not Found', 404)]);

        $this->actingAs($this->user(OpsRole::Operator))
            ->from(route('ops.sites.show', $site))
            ->post(route('ops.sites.publish-status', $site), ['publish_status' => 'published'])
            ->assertRedirect()
            ->assertSessionHas('error', __('sites.publish.errors.unsupported_cms'));
    }

    public function test_a_site_without_an_agent_secret_cannot_be_published(): void
    {
        $site = Site::factory()->create(['cms_site_status' => null]);

        Http::fake();

        $this->actingAs($this->user(OpsRole::Operator))
            ->from(route('ops.sites.show', $site))
            ->post(route('ops.sites.publish-status', $site), ['publish_status' => 'published'])
            ->assertRedirect()
            ->assertSessionHas('error', __('sites.publish.needs_secret'));

        Http::assertNothingSent();
    }

    public function test_viewer_cannot_change_publish_state(): void
    {
        $site = Site::factory()->withSecrets()->create([
            'cms_site_status' => CmsPublishStatus::Draft,
        ]);

        Http::fake();

        $this->actingAs($this->user(OpsRole::Viewer))
            ->post(route('ops.sites.publish-status', $site), ['publish_status' => 'published'])
            ->assertForbidden();

        $this->assertSame(CmsPublishStatus::Draft, $site->fresh()->publishStatus());
        Http::assertNothingSent();
    }

    public function test_an_unknown_publish_value_is_rejected(): void
    {
        $site = Site::factory()->withSecrets()->create(['cms_site_status' => null]);

        Http::fake();

        $this->actingAs($this->user(OpsRole::Operator))
            ->from(route('ops.sites.show', $site))
            ->post(route('ops.sites.publish-status', $site), ['publish_status' => 'archived'])
            ->assertSessionHasErrors('publish_status');

        Http::assertNothingSent();
    }

    public function test_bulk_unpublish_hits_every_selected_site(): void
    {
        $sites = Site::factory()->count(3)->withSecrets()->create([
            'cms_site_status' => CmsPublishStatus::Published,
        ]);

        Http::fake([
            '*' => Http::response(['ok' => true, 'site_status' => 'draft', 'changed' => true]),
        ]);

        $this->actingAs($this->user(OpsRole::Operator))
            ->from(route('ops.sites'))
            ->post(route('ops.sites.bulk.publish-status'), [
                'publish_status' => 'draft',
                'site_ids' => $sites->pluck('id')->all(),
            ])
            ->assertRedirect(route('ops.sites'))
            ->assertSessionHas('status');

        foreach ($sites as $site) {
            $this->assertSame(CmsPublishStatus::Draft, $site->fresh()->publishStatus());
        }

        Http::assertSentCount(3);
    }

    public function test_bulk_all_respects_the_publish_filter(): void
    {
        $draft = Site::factory()->withSecrets()->create(['cms_site_status' => CmsPublishStatus::Draft]);
        $published = Site::factory()->withSecrets()->create(['cms_site_status' => CmsPublishStatus::Published]);

        Http::fake([
            '*' => Http::response(['ok' => true, 'site_status' => 'published', 'changed' => true]),
        ]);

        $this->actingAs($this->user(OpsRole::Operator))
            ->from(route('ops.sites'))
            ->post(route('ops.sites.bulk.publish-status'), [
                'publish_status' => 'published',
                'all' => '1',
                'filter_publish' => 'draft',
            ])
            ->assertRedirect(route('ops.sites'));

        Http::assertSentCount(1);
        $this->assertSame(CmsPublishStatus::Published, $draft->fresh()->publishStatus());
        $this->assertSame(CmsPublishStatus::Published, $published->fresh()->publishStatus());
    }

    public function test_bulk_json_queues_a_background_job(): void
    {
        $site = Site::factory()->withSecrets()->create(['cms_site_status' => CmsPublishStatus::Draft]);

        Http::fake();

        $this->actingAs($this->user(OpsRole::Operator))
            ->postJson(route('ops.sites.bulk.publish-status'), [
                'publish_status' => 'published',
                'site_ids' => [$site->id],
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('job.type', 'sites.bulk_publish_status');
    }

    public function test_bulk_with_only_secretless_sites_says_so_instead_of_reporting_failures(): void
    {
        $site = Site::factory()->create(['cms_site_status' => null]);

        Http::fake();

        $this->actingAs($this->user(OpsRole::Operator))
            ->from(route('ops.sites'))
            ->post(route('ops.sites.bulk.publish-status'), [
                'publish_status' => 'published',
                'site_ids' => [$site->id],
            ])
            ->assertRedirect()
            ->assertSessionHas('error', __('sites.publish.bulk_needs_secret', ['count' => 1]));

        Http::assertNothingSent();
    }

    public function test_site_detail_offers_the_opposite_action_and_confirms_unpublish(): void
    {
        $published = Site::factory()->withSecrets()->create([
            'status' => SiteStatus::Active,
            'cms_site_status' => CmsPublishStatus::Published,
        ]);

        $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.sites.show', $published))
            ->assertOk()
            ->assertSee(__('sites.publish.unpublish'), false)
            ->assertSee('data-confirm-danger="true"', false)
            ->assertSee(route('ops.sites.publish-status', $published), false);

        $draft = Site::factory()->withSecrets()->create([
            'status' => SiteStatus::Active,
            'cms_site_status' => CmsPublishStatus::Draft,
        ]);

        $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.sites.show', $draft))
            ->assertOk()
            ->assertSee(__('sites.publish.publish'), false);
    }

    public function test_health_poll_mirrors_the_reported_publish_state(): void
    {
        $site = Site::factory()->withSecrets()->create(['cms_site_status' => null]);

        Http::fake([
            '*' => Http::response([
                'deamon_version' => '1.2.16',
                'queue_ok' => true,
                'site_status' => 'published',
            ]),
        ]);

        $this->actingAs($this->user(OpsRole::Operator))
            ->post(route('ops.sites.health', $site))
            ->assertRedirect();

        $this->assertSame(CmsPublishStatus::Published, $site->fresh()->publishStatus());
    }

    public function test_a_failing_health_poll_does_not_wipe_a_known_publish_state(): void
    {
        $site = Site::factory()->withSecrets()->create([
            'cms_site_status' => CmsPublishStatus::Published,
        ]);

        Http::fake(['*' => Http::response('gateway down', 502)]);

        $this->actingAs($this->user(OpsRole::Operator))
            ->post(route('ops.sites.health', $site))
            ->assertRedirect();

        $this->assertSame(
            CmsPublishStatus::Published,
            $site->fresh()->publishStatus(),
            'An unreachable CMS says nothing about publish state.',
        );
    }

    public function test_a_cms_that_stops_reporting_publish_state_clears_the_mirror(): void
    {
        $site = Site::factory()->withSecrets()->create([
            'cms_site_status' => CmsPublishStatus::Published,
        ]);

        // Healthy poll, no site_status: an older CMS. Claiming "Yayında" would be a lie.
        Http::fake(['*' => Http::response(['deamon_version' => '1.2.9', 'queue_ok' => true])]);

        $this->actingAs($this->user(OpsRole::Operator))
            ->post(route('ops.sites.health', $site))
            ->assertRedirect();

        $this->assertNull($site->fresh()->publishStatus());
    }

    private function user(OpsRole $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);

        return $user;
    }
}
