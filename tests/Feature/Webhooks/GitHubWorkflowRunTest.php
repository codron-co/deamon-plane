<?php

namespace Tests\Feature\Webhooks;

use App\Enums\Channel;
use App\Enums\DeployGate;
use App\Enums\FleetRolloutStatus;
use App\Enums\SiteStatus;
use App\Jobs\AdvanceFleetRolloutJob;
use App\Jobs\SyncCoolifyEnvCatalogJob;
use App\Models\AuditLog;
use App\Models\CiBranchHead;
use App\Models\CoolifyConnection;
use App\Models\FleetRollout;
use App\Models\GithubSetting;
use App\Models\Site;
use App\Models\SiteThemeInstallation;
use App\Models\Theme;
use App\Support\GitHubWebhookSignature;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class GitHubWorkflowRunTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'plane-github-webhook-secret';

    private const CMS = 'codron-co/deamon';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Http::preventStrayRequests();
        Queue::fake([AdvanceFleetRolloutJob::class, SyncCoolifyEnvCatalogJob::class]);
        config(['ops.deamon.repository' => 'https://github.com/codron-co/deamon.git']);

        GithubSetting::factory()->create([
            'org' => 'deamon-themes',
            'webhook_secret' => self::SECRET,
        ]);
    }

    public function test_cms_push_records_the_branch_head(): void
    {
        $this->signedPost('push', [
            'ref' => 'refs/heads/main',
            'after' => 'head111',
            'repository' => ['full_name' => self::CMS],
        ])->assertOk()->assertJson(['updated' => true]);

        $head = CiBranchHead::for(self::CMS, 'main');
        $this->assertNotNull($head);
        $this->assertSame('head111', $head->head_sha);
        $this->assertNotNull($head->pushed_at);
        Queue::assertPushed(SyncCoolifyEnvCatalogJob::class);
    }

    public function test_green_run_for_the_head_starts_a_fleet_rollout(): void
    {
        $this->ciSite();
        $this->push('main', 'green111');

        $this->workflowRun('main', 'green111', 'success')
            ->assertOk()
            ->assertJson(['event' => 'workflow_run', 'updated' => true, 'ci' => 'promote']);

        $rollout = FleetRollout::query()->sole();
        $this->assertSame(Channel::Main, $rollout->channel);
        $this->assertSame('green111', $rollout->sha);
        $this->assertSame(FleetRolloutStatus::Canary, $rollout->status);
        Queue::assertPushed(AdvanceFleetRolloutJob::class, fn (AdvanceFleetRolloutJob $job): bool => $job->rolloutId === $rollout->id);

        $head = CiBranchHead::for(self::CMS, 'main');
        $this->assertSame('success', $head->ci_status);
        $this->assertSame('green111', $head->ci_sha);
        $this->assertTrue($rollout->auditLogs()->where('action', 'fleet_rollout.started')->exists());
    }

    public function test_red_run_is_recorded_and_audited_and_deploys_nothing(): void
    {
        $this->ciSite();
        $this->push('main', 'red111');

        $this->workflowRun('main', 'red111', 'failure')
            ->assertOk()
            ->assertJson(['updated' => false, 'ci' => 'red']);

        $this->assertSame(0, FleetRollout::query()->count());
        Queue::assertNotPushed(AdvanceFleetRolloutJob::class);

        $head = CiBranchHead::for(self::CMS, 'main');
        $this->assertSame('failure', $head->ci_status);
        $audit = AuditLog::query()->where('action', 'ci.run_failed')->sole();
        $this->assertSame('red111', $audit->after['sha']);
        $this->assertSame('failed', $audit->outcome());
    }

    public function test_green_run_for_an_older_commit_is_superseded(): void
    {
        $this->ciSite();
        $this->push('main', 'old111');
        $this->push('main', 'new222');

        $this->workflowRun('main', 'old111', 'success')
            ->assertOk()
            ->assertJson(['updated' => false, 'ci' => 'superseded']);

        $this->assertSame(0, FleetRollout::query()->count());
        $this->assertTrue(AuditLog::query()->where('action', 'ci.run_superseded')->exists());
        $this->assertSame('superseded', CiBranchHead::for(self::CMS, 'main')->ci_status);
    }

    public function test_green_run_without_a_recorded_push_is_held(): void
    {
        $this->ciSite();

        $this->workflowRun('main', 'unknown111', 'success')
            ->assertOk()
            ->assertJson(['updated' => false, 'ci' => 'head_unknown']);

        $this->assertSame(0, FleetRollout::query()->count());
        $this->assertTrue(AuditLog::query()->where('action', 'ci.run_head_unknown')->exists());
    }

    public function test_other_workflows_and_non_push_runs_are_ignored(): void
    {
        $this->ciSite();
        $this->push('main', 'sha111');

        $this->workflowRun('main', 'sha111', 'success', name: 'Deploy docs')
            ->assertOk()->assertJsonMissing(['ci' => 'promote']);
        $this->workflowRun('main', 'sha111', 'success', runEvent: 'pull_request')
            ->assertOk()->assertJsonMissing(['ci' => 'promote']);
        $this->workflowRun('main', 'sha111', 'success', action: 'requested')
            ->assertOk()->assertJsonMissing(['ci' => 'promote']);

        $this->assertSame(0, FleetRollout::query()->count());
        $this->assertNull(CiBranchHead::for(self::CMS, 'main')->ci_status);
    }

    public function test_run_of_an_unknown_repo_is_ignored(): void
    {
        $this->ciSite();

        $this->workflowRun('main', 'sha111', 'success', repo: 'someone/else')
            ->assertOk()->assertJson(['updated' => false]);

        $this->assertSame(0, FleetRollout::query()->count());
        $this->assertSame(0, CiBranchHead::query()->count());
    }

    public function test_non_channel_branch_of_the_cms_is_ignored(): void
    {
        $this->ciSite();
        $this->workflowRun('feature/x', 'sha111', 'success')->assertOk()->assertJson(['updated' => false]);

        $this->assertSame(0, FleetRollout::query()->count());
    }

    public function test_duplicate_delivery_is_ignored(): void
    {
        $this->ciSite();
        $this->push('main', 'dup111');

        $this->workflowRun('main', 'dup111', 'success', delivery: 'run-delivery-1')->assertJson(['updated' => true]);
        FleetRollout::query()->delete();
        $this->workflowRun('main', 'dup111', 'success', delivery: 'run-delivery-1')->assertJson(['updated' => false]);

        $this->assertSame(0, FleetRollout::query()->count());
    }

    public function test_rerun_of_the_same_green_commit_does_not_start_a_second_rollout(): void
    {
        $this->ciSite();
        $this->push('main', 'same111');

        $this->workflowRun('main', 'same111', 'success')->assertJson(['updated' => true]);
        $this->workflowRun('main', 'same111', 'success')->assertJson(['updated' => false]);

        $this->assertSame(1, FleetRollout::query()->count());
    }

    public function test_green_run_without_ci_gated_sites_starts_nothing(): void
    {
        $this->ciSite(['deploy_gate' => DeployGate::Coolify]);
        $this->push('main', 'none111');

        $this->workflowRun('main', 'none111', 'success')->assertJson(['updated' => false, 'ci' => 'promote']);

        $this->assertSame(0, FleetRollout::query()->count());
    }

    public function test_new_green_commit_supersedes_the_open_rollout_of_the_channel(): void
    {
        $this->ciSite();
        $this->push('main', 'first111');
        $this->workflowRun('main', 'first111', 'success');
        $first = FleetRollout::query()->sole();

        $this->push('main', 'second222');
        $this->workflowRun('main', 'second222', 'success')->assertJson(['updated' => true]);

        $this->assertSame(FleetRolloutStatus::Superseded, $first->fresh()->status);
        $this->assertNotNull($first->fresh()->finished_at);
        $this->assertSame(FleetRolloutStatus::Canary, FleetRollout::query()->where('sha', 'second222')->sole()->status);
    }

    public function test_ci_gated_theme_push_is_held_until_its_ci_run(): void
    {
        [$theme, $install] = $this->gatedTheme();

        $this->signedPost('push', [
            'ref' => 'refs/heads/main',
            'after' => 'theme222',
            'repository' => ['full_name' => $theme->repo_full_name],
        ])->assertOk()->assertJson(['updated' => false, 'fanout' => 0]);

        $this->assertSame('theme111', $theme->fresh()->latest_sha);
        $this->assertSame('theme222', CiBranchHead::for($theme->repo_full_name, 'main')->head_sha);
        Http::assertNothingSent();

        Http::fake([
            'https://gated.example.test/internal/control/v1/themes/update' => Http::response(['ok' => true, 'sha' => 'theme222'], 200),
        ]);

        $this->workflowRun('main', 'theme222', 'success', repo: $theme->repo_full_name)
            ->assertOk()
            ->assertJson(['updated' => true, 'fanout' => 1, 'ci' => 'promote']);

        $fresh = $theme->fresh();
        $this->assertSame('theme222', $fresh->latest_sha);
        $this->assertNotNull($fresh->last_synced_at);
        $this->assertSame('theme222', $install->fresh()->pinned_sha);
        $this->assertTrue($theme->auditLogs()->where('action', 'theme.ci_promoted')->exists());
    }

    public function test_ci_gated_theme_ignores_a_run_for_a_commit_that_is_not_the_head(): void
    {
        [$theme] = $this->gatedTheme();
        $this->signedPost('push', [
            'ref' => 'refs/heads/main',
            'after' => 'theme333',
            'repository' => ['full_name' => $theme->repo_full_name],
        ]);

        $this->workflowRun('main', 'theme222', 'success', repo: $theme->repo_full_name)
            ->assertOk()
            ->assertJson(['updated' => false, 'fanout' => 0, 'ci' => 'superseded']);

        $this->assertSame('theme111', $theme->fresh()->latest_sha);
        $this->assertTrue($theme->auditLogs()->where('action', 'theme.ci_held')->exists());
        Http::assertNothingSent();
    }

    public function test_ci_gated_theme_red_run_keeps_the_catalog(): void
    {
        [$theme] = $this->gatedTheme();
        $this->signedPost('push', [
            'ref' => 'refs/heads/main',
            'after' => 'theme444',
            'repository' => ['full_name' => $theme->repo_full_name],
        ]);

        $this->workflowRun('main', 'theme444', 'failure', repo: $theme->repo_full_name)
            ->assertOk()
            ->assertJson(['updated' => false, 'ci' => 'red']);

        $this->assertSame('theme111', $theme->fresh()->latest_sha);
        $this->assertTrue($theme->auditLogs()->where('action', 'theme.ci_failed')->exists());
    }

    public function test_theme_without_ci_gate_still_fans_out_on_push_and_ignores_runs(): void
    {
        [$theme] = $this->gatedTheme(ciGate: false);
        Http::fake([
            'https://gated.example.test/internal/control/v1/themes/update' => Http::response(['ok' => true, 'sha' => 'theme555'], 200),
        ]);

        $this->signedPost('push', [
            'ref' => 'refs/heads/main',
            'after' => 'theme555',
            'repository' => ['full_name' => $theme->repo_full_name],
        ])->assertOk()->assertJson(['updated' => true, 'fanout' => 1]);

        $this->workflowRun('main', 'theme555', 'success', repo: $theme->repo_full_name)
            ->assertOk()
            ->assertJson(['updated' => false, 'fanout' => 0]);

        $this->assertSame('theme555', $theme->fresh()->latest_sha);
        Http::assertSentCount(1);
    }

    public function test_theme_run_on_a_non_default_branch_is_ignored(): void
    {
        [$theme] = $this->gatedTheme();

        $this->workflowRun('feature/wip', 'theme666', 'success', repo: $theme->repo_full_name)
            ->assertOk()->assertJson(['updated' => false]);

        $this->assertSame(0, CiBranchHead::query()->count());
    }

    private function push(string $branch, string $sha): void
    {
        $this->signedPost('push', [
            'ref' => 'refs/heads/'.$branch,
            'after' => $sha,
            'repository' => ['full_name' => self::CMS],
        ])->assertOk();
    }

    private function workflowRun(
        string $branch,
        string $sha,
        string $conclusion,
        string $name = 'CI',
        string $runEvent = 'push',
        string $action = 'completed',
        string $repo = self::CMS,
        ?string $delivery = null,
    ): TestResponse {
        return $this->signedPost('workflow_run', [
            'action' => $action,
            'workflow_run' => [
                'name' => $name,
                'event' => $runEvent,
                'status' => 'completed',
                'conclusion' => $conclusion,
                'head_branch' => $branch,
                'head_sha' => $sha,
            ],
            'repository' => ['full_name' => $repo],
        ], delivery: $delivery);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function ciSite(array $attributes = []): Site
    {
        $connection = CoolifyConnection::factory()->create([
            'base_url' => 'https://coolify.example',
            'api_token' => 'token',
        ]);

        return Site::factory()->create(array_merge([
            'status' => SiteStatus::Active,
            'channel' => Channel::Main,
            'coolify_app_uuid' => 'app-'.fake()->unique()->lexify('??????'),
            'coolify_connection_id' => $connection->id,
            'deploy_gate' => DeployGate::Ci,
        ], $attributes));
    }

    /**
     * @return array{0: Theme, 1: SiteThemeInstallation}
     */
    private function gatedTheme(bool $ciGate = true): array
    {
        $theme = Theme::factory()->publicCatalog()->create([
            'repo_full_name' => 'deamon-themes/deamon-theme-gated',
            'theme_id' => 'gated',
            'default_ref' => 'main',
            'latest_sha' => 'theme111',
            'ci_gate' => $ciGate,
        ]);
        $site = Site::factory()->withSecrets()->create([
            'primary_domain' => 'gated.example.test',
            'agent_base_url' => 'https://gated.example.test',
            'last_health_payload' => ['deamon_version' => '1.2.32'],
        ]);
        $install = SiteThemeInstallation::factory()->active()->autoUpdate()->create([
            'site_id' => $site->id,
            'theme_id' => $theme->id,
            'ref' => 'main',
            'pinned_sha' => 'theme111',
        ]);

        return [$theme, $install];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function signedPost(string $event, array $payload, ?string $delivery = null): TestResponse
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        $server = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => GitHubWebhookSignature::sign(self::SECRET, $body),
            'HTTP_X_GITHUB_EVENT' => $event,
        ];
        if ($delivery !== null) {
            $server['HTTP_X_GITHUB_DELIVERY'] = $delivery;
        }

        return $this->call('POST', '/webhooks/github', [], [], [], $server, $body);
    }
}
