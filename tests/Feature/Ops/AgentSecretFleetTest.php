<?php

namespace Tests\Feature\Ops;

use App\Enums\OpsRole;
use App\Enums\SiteStatus;
use App\Models\AuditLog;
use App\Models\CoolifyConnection;
use App\Models\OpsBackgroundJob;
use App\Models\Site;
use App\Models\User;
use App\Services\Fleet\FleetDashboardKpis;
use App\Services\Ops\OpsJobRunner;
use App\Support\Lists\ListFragment;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The fleet agent-secret card splits yok / doğrulanmamış / tamam, and the
 * Sites filter plus bulk inject resolve the same three buckets. A stored
 * secret the CMS has never answered 200 for is unverified, not ok. Bulk
 * inject never rotates, and the value never appears in HTML, audit or widget.
 */
class AgentSecretFleetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Http::preventStrayRequests();
    }

    public function test_a_stored_secret_without_a_cms_200_is_unverified_not_ok(): void
    {
        $missing = $this->missingSite('Yok Site');
        $unverified = $this->unverifiedSite('Doğrulanmamış Site');
        $ok = $this->verifiedSite('Tamam Site');

        $this->assertSame('missing', $missing->agentSecretFleetState());
        $this->assertSame('unverified', $unverified->agentSecretFleetState());
        $this->assertSame('ok', $ok->agentSecretFleetState());
        $this->assertFalse($unverified->agentSecretIsVerified());
        $this->assertTrue($ok->agentSecretIsVerified());

        $counts = app(FleetDashboardKpis::class)->agentSecretCounts();
        $this->assertSame(1, $counts['missing']);
        $this->assertSame(1, $counts['unverified']);
        $this->assertSame(1, $counts['ok']);

        $this->assertSame(1, Site::query()->matchingListFilters('', '', '', '', '', 'missing')->count());
        $this->assertSame(1, Site::query()->matchingListFilters('', '', '', '', '', 'unverified')->count());
        $this->assertSame(1, Site::query()->matchingListFilters('', '', '', '', '', 'ok')->count());

        $ok->forceFill([
            'last_health_payload' => ['ok' => false, 'status' => 'unhealthy', 'reason' => 'timeout'],
        ])->save();
        $this->assertSame('unverified', $ok->fresh()->agentSecretFleetState());
    }

    public function test_the_card_counts_equal_the_filtered_row_counts(): void
    {
        $this->missingSite('Yok Bir');
        $this->missingSite('Yok İki');
        $this->unverifiedSite('Bekleyen');
        $this->verifiedSite('Hazır');
        Site::factory()->count(2)->withSecrets()->create([
            'last_health_payload' => ['ok' => false, 'status' => 'unhealthy', 'reason' => 'timeout'],
        ]);

        $counts = app(FleetDashboardKpis::class)->agentSecretCounts();

        $this->assertSame(
            $counts['missing'],
            Site::query()->matchingListFilters('', '', '', '', '', 'missing')->count(),
        );
        $this->assertSame(
            $counts['unverified'],
            Site::query()->matchingListFilters('', '', '', '', '', 'unverified')->count(),
        );
        $this->assertSame(
            $counts['ok'],
            Site::query()->matchingListFilters('', '', '', '', '', 'ok')->count(),
        );
        $this->assertSame(2, $counts['missing']);
        $this->assertSame(3, $counts['unverified']);
        $this->assertSame(1, $counts['ok']);
    }

    public function test_the_fleet_card_splits_the_three_buckets_and_links_the_filters(): void
    {
        $missing = $this->missingSite('Eksik Gizli');
        $unverified = $this->unverifiedSite('Bekleyen Gizli');
        $ok = $this->verifiedSite('Hazır Gizli');

        $html = $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.fleet'))
            ->assertOk()
            ->assertSee(__('fleet.kpis.agent_secret'), false)
            ->assertSee(__('fleet.kpis.agent_missing', ['count' => 1]), false)
            ->assertSee(__('fleet.kpis.agent_unverified', ['count' => 1]), false)
            ->assertSee(__('fleet.kpis.agent_ok', ['count' => 1]), false)
            ->assertSee(route('ops.sites', ['agent' => 'missing']), false)
            ->assertSee(route('ops.sites', ['agent' => 'unverified']), false)
            ->assertSee($missing->name, false)
            ->assertSee($unverified->name, false)
            ->assertDontSee($ok->name, false)
            ->assertSee(__('sites.agent.bulk'), false)
            ->assertSee('data-confirm-danger="true"', false)
            ->getContent();

        $this->assertStringNotContainsString((string) $unverified->agent_secret_encrypted, $html);
        $this->assertStringNotContainsString((string) $ok->agent_secret_encrypted, $html);
    }

    public function test_the_sites_filter_lists_only_the_matching_bucket(): void
    {
        $missing = $this->missingSite('Filtre Yok');
        $unverified = $this->unverifiedSite('Filtre Bekleyen');
        $ok = $this->verifiedSite('Filtre Tamam');

        $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.sites', ['agent' => 'missing']))
            ->assertOk()
            ->assertSee($missing->name)
            ->assertDontSee($unverified->name)
            ->assertDontSee($ok->name)
            ->assertSee(__('sites.agent_states.missing'))
            ->assertSee('name="filter_agent" value="missing"', false);

        $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.sites', ['agent' => 'unverified']))
            ->assertOk()
            ->assertSee($unverified->name)
            ->assertDontSee($missing->name)
            ->assertDontSee($ok->name);
    }

    public function test_an_unknown_agent_filter_widens_instead_of_emptying(): void
    {
        $this->missingSite('Geniş Site');

        $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.sites', ['agent' => 'kaboom']))
            ->assertOk()
            ->assertSee('Geniş Site');
    }

    public function test_bulk_inject_only_targets_sites_missing_a_secret(): void
    {
        CoolifyConnection::factory()->create();
        Http::fake([
            'https://coolify.test/*' => Http::response([], 200),
        ]);

        $missing = $this->missingSite('Yazılacak', 'coolify-app-missing');
        $kept = $this->unverifiedSite('Dokunulmayacak', 'coolify-app-kept');
        $previous = (string) $kept->agent_secret_encrypted;

        $this->actingAs($this->user(OpsRole::Operator))
            ->post(route('ops.sites.bulk.agent-secret'), [
                'all' => '1',
                'filter_agent' => 'missing',
            ])
            ->assertRedirect();

        $missing->refresh();
        $kept->refresh();
        $this->assertTrue($missing->hasAgentSecret());
        $this->assertSame($previous, (string) $kept->agent_secret_encrypted);
        $this->assertNotSame('', (string) $missing->agent_secret_encrypted);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'site.agent_secret_injected',
            'subject_id' => $missing->id,
        ]);
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'site.agent_secret_rotated',
            'subject_id' => $kept->id,
        ]);

        $secret = (string) $missing->agent_secret_encrypted;
        $audit = AuditLog::query()->where('action', 'site.agent_secret_injected')->first();
        $this->assertNotNull($audit);
        $this->assertStringNotContainsString($secret, (string) json_encode($audit->after));
        $this->assertStringNotContainsString($secret, (string) json_encode($audit->before));

        $html = $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.sites.show', $missing))
            ->assertOk()
            ->getContent();
        $this->assertStringNotContainsString($secret, $html);
    }

    public function test_a_mixed_selection_skips_sites_that_already_have_a_secret(): void
    {
        CoolifyConnection::factory()->create();
        Http::fake([
            'https://coolify.test/*' => Http::response([], 200),
        ]);

        $missing = $this->missingSite('Karışık Yok', 'coolify-app-mix-missing');
        $kept = $this->verifiedSite('Karışık Tamam', 'coolify-app-mix-ok');
        $previous = (string) $kept->agent_secret_encrypted;

        $this->actingAs($this->user(OpsRole::Operator))
            ->post(route('ops.sites.bulk.agent-secret'), [
                'site_ids' => [$missing->id, $kept->id],
            ])
            ->assertRedirect();

        $this->assertTrue($missing->fresh()->hasAgentSecret());
        $this->assertSame($previous, (string) $kept->fresh()->agent_secret_encrypted);
        Http::assertSentCount(1);
    }

    public function test_json_bulk_inject_queues_a_plane_finished_job_without_leaking_the_secret(): void
    {
        CoolifyConnection::factory()->create();
        Http::fake([
            'https://coolify.test/*' => Http::response([], 200),
        ]);

        $missing = $this->missingSite('Kuyruk Yok', 'coolify-app-queue');

        Queue::fake();

        $response = $this->actingAs($this->user(OpsRole::Operator))
            ->postJson(route('ops.sites.bulk.agent-secret'), [
                'site_ids' => [$missing->id],
            ])
            ->assertOk()
            ->assertJsonPath('job.type', 'sites.bulk_inject_agent_secret');

        $job = OpsBackgroundJob::query()->findOrFail($response->json('job.id'));
        $this->assertFalse($job->triggersRemoteWork());

        app(OpsJobRunner::class)->run($job);

        $missing->refresh();
        $this->assertTrue($missing->hasAgentSecret());
        $secret = (string) $missing->agent_secret_encrypted;
        $this->assertStringNotContainsString($secret, (string) json_encode($response->json()));
        $this->assertStringNotContainsString($secret, (string) json_encode($job->fresh()->toWidget()));
    }

    public function test_a_viewer_sees_the_card_but_cannot_inject(): void
    {
        $this->missingSite('İzleyici Site');

        $this->actingAs($this->user(OpsRole::Viewer))
            ->get(route('ops.fleet'))
            ->assertOk()
            ->assertSee(__('fleet.kpis.agent_secret'))
            ->assertDontSee(__('sites.agent.bulk'));

        $this->actingAs($this->user(OpsRole::Viewer))
            ->post(route('ops.sites.bulk.agent-secret'), ['all' => '1', 'filter_agent' => 'missing'])
            ->assertForbidden();
    }

    public function test_filtered_copy_reads_as_turkish_for_a_turkish_operator(): void
    {
        $this->missingSite('Türkçe Yok');

        $user = $this->user(OpsRole::Operator);
        $user->locale = 'tr';
        $user->save();

        $this->actingAs($user)
            ->get(route('ops.sites', ['agent' => 'missing']))
            ->assertOk()
            ->assertSee('yok', false)
            ->assertSee('Gizli anahtar üret ve gönder', false)
            ->assertDontSee('Generate and inject secrets', false);
    }

    public function test_the_async_region_reships_the_agent_filter(): void
    {
        $this->missingSite('Bölge Yok');

        $this->actingAs($this->user(OpsRole::Operator))
            ->withHeader(ListFragment::HEADER, ListFragment::VALUE)
            ->get(route('ops.sites', ['agent' => 'missing']))
            ->assertOk()
            ->assertSee('name="filter_agent" value="missing"', false)
            ->assertDontSee('Deamon ops', false);
    }

    private function missingSite(string $name, string $uuid = 'coolify-app-missing-default'): Site
    {
        return Site::factory()->create([
            'name' => $name,
            'status' => SiteStatus::Active,
            'coolify_app_uuid' => $uuid,
            'agent_secret_encrypted' => null,
        ]);
    }

    private function unverifiedSite(string $name, string $uuid = 'coolify-app-unverified-default'): Site
    {
        return Site::factory()->withSecrets()->create([
            'name' => $name,
            'status' => SiteStatus::Active,
            'coolify_app_uuid' => $uuid,
            'last_health_at' => null,
            'last_health_payload' => null,
        ]);
    }

    private function verifiedSite(string $name, string $uuid = 'coolify-app-ok-default'): Site
    {
        return Site::factory()->withSecrets()->create([
            'name' => $name,
            'status' => SiteStatus::Active,
            'coolify_app_uuid' => $uuid,
            'last_health_at' => now(),
            'last_health_payload' => [
                'ok' => true,
                'status' => 'ok',
                'http_status' => 200,
            ],
        ]);
    }

    private function user(OpsRole $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);

        return $user;
    }
}
