<?php

namespace Tests\Feature\Ops;

use App\Enums\DeploymentStatus;
use App\Enums\OpsRole;
use App\Models\AuditLog;
use App\Models\Deployment;
use App\Models\OpsBackgroundJob;
use App\Models\Site;
use App\Models\User;
use App\Support\Lists\ListFragment;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * /activity is the fleet log the jobs widget is not: every ops role, every
 * actor, no two-hour cap. Payloads stay redacted.
 */
class ActivityFeedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_a_guest_is_sent_to_sign_in(): void
    {
        $this->get(route('ops.activity'))->assertRedirect(route('login'));
        $this->withHeader(ListFragment::HEADER, ListFragment::VALUE)
            ->get(route('ops.activity'))
            ->assertRedirect(route('login'));
    }

    public function test_an_empty_feed_explains_itself(): void
    {
        $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.activity'))
            ->assertOk()
            ->assertSee(__('ops.activity.empty.title'))
            ->assertSee(__('ops.activity.empty.hint'))
            ->assertDontSee(__('ops.activity.empty.filtered_title'))
            ->assertSee('data-ops-list-toolbar', false)
            ->assertSee('data-ops-list-clear hidden>', false);
    }

    public function test_the_list_merges_jobs_deploys_and_audits_newest_first(): void
    {
        $site = Site::factory()->create(['name' => 'Beyazlar']);
        $operator = $this->user(OpsRole::Operator);

        AuditLog::factory()->create([
            'actor_user_id' => $operator->id,
            'subject_type' => Site::class,
            'subject_id' => $site->id,
            'action' => 'site.created',
            'created_at' => now()->subMinutes(30),
        ]);
        Deployment::factory()->create([
            'site_id' => $site->id,
            'status' => DeploymentStatus::Finished,
            'requested_by' => $operator->id,
            'created_at' => now()->subMinutes(10),
            'finished_at' => now()->subMinutes(10),
        ]);
        $this->job($operator, [
            'payload' => ['site_ids' => [$site->id], 'subject' => 'Canli senkron'],
        ]);

        $html = $this->actingAs($operator)
            ->get(route('ops.activity'))
            ->assertOk()
            ->assertSee('Canli senkron')
            ->assertSee('Beyazlar')
            ->assertSee(__('ops.activity.kinds.job'))
            ->assertSee(__('ops.activity.kinds.deployment'))
            ->assertSee(__('ops.activity.kinds.audit'))
            ->getContent();

        $this->assertLessThan(
            strpos($html, __('ops.activity.actions.site.created')),
            strpos($html, 'Canli senkron'),
            'The newest job must sort above the older audit.'
        );
    }

    public function test_a_viewer_sees_another_operators_job(): void
    {
        $author = $this->user(OpsRole::Operator);
        $viewer = $this->user(OpsRole::Viewer);
        $job = $this->job($author, [
            'payload' => ['subject' => 'Filo taramasi'],
        ]);

        $this->actingAs($viewer)
            ->get(route('ops.activity'))
            ->assertOk()
            ->assertSee('Filo taramasi')
            ->assertSee($author->name);

        $this->actingAs($viewer)
            ->get(route('ops.activity.jobs.show', $job))
            ->assertOk()
            ->assertSee('Filo taramasi')
            ->assertSee($author->name);

        $this->actingAs($viewer)
            ->getJson(route('ops.jobs.show', $job))
            ->assertForbidden();
    }

    public function test_kind_outcome_actor_and_site_filters_are_allowlisted(): void
    {
        $alpha = Site::factory()->create(['name' => 'Alpha Site']);
        $beta = Site::factory()->create(['name' => 'Beta Site']);
        $alice = $this->user(OpsRole::Operator);
        $bob = $this->user(OpsRole::Operator);

        $this->job($alice, [
            'status' => 'failed',
            'payload' => ['site_ids' => [$alpha->id], 'subject' => 'Alice sweep'],
        ]);
        Deployment::factory()->create([
            'site_id' => $beta->id,
            'status' => DeploymentStatus::Finished,
            'requested_by' => $bob->id,
        ]);
        AuditLog::factory()->create([
            'actor_user_id' => $bob->id,
            'subject_type' => Site::class,
            'subject_id' => $beta->id,
            'action' => 'site.published',
        ]);

        $user = $this->user(OpsRole::Operator);

        $this->actingAs($user)
            ->withHeader(ListFragment::HEADER, ListFragment::VALUE)
            ->get(route('ops.activity', ['kind' => 'job']))
            ->assertOk()
            ->assertSee('Alice sweep')
            ->assertDontSee('Beta Site');

        $this->actingAs($user)
            ->withHeader(ListFragment::HEADER, ListFragment::VALUE)
            ->get(route('ops.activity', ['outcome' => 'failed']))
            ->assertOk()
            ->assertSee('Alice sweep')
            ->assertDontSee(__('ops.activity.actions.site.published'));

        $this->actingAs($user)
            ->withHeader(ListFragment::HEADER, ListFragment::VALUE)
            ->get(route('ops.activity', ['actor' => $bob->id]))
            ->assertOk()
            ->assertSee('Beta Site')
            ->assertDontSee('Alice sweep');

        $this->actingAs($user)
            ->withHeader(ListFragment::HEADER, ListFragment::VALUE)
            ->get(route('ops.activity', ['site' => $alpha->id]))
            ->assertOk()
            ->assertSee('Alice sweep')
            ->assertDontSee('Beta Site');

        $this->actingAs($user)
            ->withHeader(ListFragment::HEADER, ListFragment::VALUE)
            ->get(route('ops.activity', ['kind' => 'kaboom', 'outcome' => 'nope', 'actor' => 'x', 'site' => 'not-a-ulid']))
            ->assertOk()
            ->assertSee('Alice sweep')
            ->assertSee('Beta Site');
    }

    public function test_search_is_literal_and_finds_a_site_deploy(): void
    {
        $hit = Site::factory()->create(['name' => 'Yuzde Site']);
        $miss = Site::factory()->create(['name' => 'Diger Site']);
        Deployment::factory()->create(['site_id' => $hit->id, 'status' => DeploymentStatus::Finished]);
        Deployment::factory()->create(['site_id' => $miss->id, 'status' => DeploymentStatus::Finished]);

        $this->actingAs($this->user(OpsRole::Operator))
            ->withHeader(ListFragment::HEADER, ListFragment::VALUE)
            ->get(route('ops.activity', ['q' => 'Yuzde']))
            ->assertOk()
            ->assertSee('Yuzde Site')
            ->assertDontSee('Diger Site');

        $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.activity', ['q' => '%']))
            ->assertOk()
            ->assertSee(__('ops.activity.empty.filtered_title'));
    }

    public function test_filtered_empty_names_the_feed_size_and_offers_clear(): void
    {
        Deployment::factory()->create(['status' => DeploymentStatus::Finished]);

        $html = $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.activity', ['kind' => 'job']))
            ->assertOk()
            ->assertSee(__('ops.activity.empty.filtered_title'))
            ->assertSee(__('ops.activity.empty.filtered_hint', ['total' => 1]))
            ->assertSee(__('ops.actions.clear_filters'))
            ->getContent();

        $this->assertStringContainsString('ops-filter-chips', $html);
    }

    public function test_the_async_region_omits_the_shell_and_keeps_the_pager(): void
    {
        Deployment::factory()->count(26)->create(['status' => DeploymentStatus::Finished]);

        $html = $this->actingAs($this->user(OpsRole::Operator))
            ->withHeader(ListFragment::HEADER, ListFragment::VALUE)
            ->get(route('ops.activity'))
            ->assertOk()
            ->assertHeader('X-Ops-List-Region', '1')
            ->assertHeader('Vary', ListFragment::HEADER)
            ->assertSee('class="ops-pagination"', false)
            ->assertSee('data-ops-list-focus="page:next"', false)
            ->getContent();

        $this->assertStringNotContainsString('<!DOCTYPE html>', $html);
        $this->assertStringNotContainsString('ops-sidebar', $html);
        $this->assertStringNotContainsString('data-ops-list-toolbar', $html);
    }

    public function test_rows_open_the_right_detail_and_secrets_stay_redacted(): void
    {
        $site = Site::factory()->create(['name' => 'Gizli Site']);
        $operator = $this->user(OpsRole::Operator);
        $deployment = Deployment::factory()->create([
            'site_id' => $site->id,
            'status' => DeploymentStatus::Failed,
            'error_message' => 'CONTROL_PLANE_AGENT_SECRET=super-secret',
        ]);
        $job = $this->job($operator, [
            'title' => 'Secret job',
            'message' => 'CONTROL_PLANE_AGENT_SECRET=super-secret',
            'payload' => ['site_ids' => [$site->id], 'agent_secret' => 'plain-secret'],
            'result' => ['token' => 'abc123'],
        ]);
        $audit = AuditLog::factory()->create([
            'actor_user_id' => $operator->id,
            'subject_type' => Site::class,
            'subject_id' => $site->id,
            'action' => 'site.updated',
            'after' => ['agent_secret' => 'plain-secret', 'name' => 'Gizli Site'],
        ]);

        $list = $this->actingAs($operator)
            ->get(route('ops.activity'))
            ->assertOk()
            ->assertSee('data-href="'.route('ops.sites.deployments.show', [$site, $deployment]).'"', false)
            ->assertSee('data-href="'.route('ops.activity.jobs.show', $job).'"', false)
            ->assertSee('data-href="'.route('ops.activity.audits.show', $audit).'"', false)
            ->assertDontSee('super-secret')
            ->assertDontSee('plain-secret')
            ->getContent();

        $this->assertStringContainsString('[redacted]', $list);

        $this->actingAs($operator)
            ->get(route('ops.activity.jobs.show', $job))
            ->assertOk()
            ->assertSee('[redacted]')
            ->assertDontSee('super-secret')
            ->assertDontSee('plain-secret')
            ->assertDontSee('abc123');

        $this->actingAs($operator)
            ->get(route('ops.activity.audits.show', $audit))
            ->assertOk()
            ->assertSee('[redacted]')
            ->assertSee('Gizli Site')
            ->assertDontSee('plain-secret');
    }

    public function test_nav_and_palette_offer_activity_but_never_the_jobs_json(): void
    {
        $operator = $this->user(OpsRole::Operator, 'tr');

        $this->actingAs($operator)
            ->get(route('ops.fleet'))
            ->assertOk()
            ->assertSee(route('ops.activity'), false)
            ->assertSee(__('ops.nav.activity', [], 'tr'))
            ->assertDontSee(__('ops.nav.activity', [], 'en'));

        $this->actingAs($operator)
            ->getJson(route('ops.palette'))
            ->assertOk()
            ->assertJsonFragment(['url' => route('ops.activity'), 'label' => trans('ops.nav.activity', [], 'tr')]);

        $urls = collect($this->actingAs($operator)->getJson(route('ops.palette'))->json('groups.0.items'))
            ->pluck('url');
        $this->assertNotContains(route('ops.jobs'), $urls);
    }

    public function test_turkish_copy_is_used_for_a_turkish_operator(): void
    {
        $this->actingAs($this->user(OpsRole::Operator, 'tr'))
            ->get(route('ops.activity'))
            ->assertOk()
            ->assertSee(__('ops.activity.title', [], 'tr'))
            ->assertSee(__('ops.activity.empty.title', [], 'tr'))
            ->assertSee(__('ops.activity.export_csv', [], 'tr'))
            ->assertDontSee(__('ops.activity.empty.title', [], 'en'));
    }

    public function test_csv_export_keeps_the_page_filters_and_redacts_secrets(): void
    {
        $site = Site::factory()->create(['name' => 'Csv Site']);
        $other = Site::factory()->create(['name' => 'Other Site']);
        $operator = $this->user(OpsRole::Operator, 'tr');

        $this->job($operator, [
            'status' => 'failed',
            'payload' => ['site_ids' => [$site->id], 'subject' => 'Csv sweep'],
            'message' => 'CONTROL_PLANE_AGENT_SECRET=super-secret',
        ]);
        Deployment::factory()->create([
            'site_id' => $other->id,
            'status' => DeploymentStatus::Finished,
            'requested_by' => $operator->id,
        ]);

        $this->actingAs($operator)
            ->get(route('ops.activity'))
            ->assertOk()
            ->assertSee(route('ops.activity.export'), false);

        $response = $this->actingAs($operator)
            ->get(route('ops.activity.export', ['outcome' => 'failed', 'site' => $site->id]))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('attachment; filename="activity-', (string) $response->headers->get('Content-Disposition'));

        $csv = $response->getContent();
        $this->assertStringContainsString('Csv sweep', $csv);
        $this->assertStringContainsString('Csv Site', $csv);
        $this->assertStringContainsString(__('ops.activity.outcomes.failed', [], 'tr'), $csv);
        $this->assertStringNotContainsString('Other Site', $csv);
        $this->assertStringNotContainsString('super-secret', $csv);
        $this->assertStringContainsString('[redacted]', $csv);
    }

    public function test_csv_export_is_capped_and_a_viewer_can_download_it(): void
    {
        config()->set('ops.activity.export_limit', 2);
        $viewer = $this->user(OpsRole::Viewer);
        Deployment::factory()->count(4)->create(['status' => DeploymentStatus::Finished]);

        $csv = ltrim((string) $this->actingAs($viewer)
            ->get(route('ops.activity.export'))
            ->assertOk()
            ->getContent(), "\xEF\xBB\xBF");

        $lines = preg_split("/\r\n|\n/", trim($csv)) ?: [];

        $this->assertCount(3, $lines);
    }

    public function test_a_guest_is_sent_to_sign_in_from_export(): void
    {
        $this->get(route('ops.activity.export'))->assertRedirect(route('login'));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function job(User $actor, array $overrides = []): OpsBackgroundJob
    {
        return OpsBackgroundJob::query()->create(array_merge([
            'type' => 'sites.live_sync',
            'title' => 'job',
            'status' => 'completed',
            'payload' => [],
            'actor_user_id' => $actor->id,
        ], $overrides));
    }

    private function user(OpsRole $role, ?string $locale = null): User
    {
        $user = User::factory()->create($locale === null ? [] : ['locale' => $locale]);
        $user->assignRole($role->value);

        return $user;
    }
}
