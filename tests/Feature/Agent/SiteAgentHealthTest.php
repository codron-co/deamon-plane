<?php

namespace Tests\Feature\Agent;

use App\Enums\Channel;
use App\Enums\OpsRole;
use App\Enums\SiteStatus;
use App\Jobs\CheckSiteHealthJob;
use App\Jobs\DispatchSiteHealthChecksJob;
use App\Models\Site;
use App\Models\User;
use App\Services\Agent\AgentHealthReason;
use App\Services\Agent\AgentHealthResult;
use App\Services\Agent\AgentHealthStatus;
use App\Services\Agent\ControlPlaneAgentContract;
use App\Services\Agent\SiteAgentClient;
use App\Services\Agent\SiteHealthChecker;
use App\Support\ControlPlaneAgentSignature;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schedule;
use Tests\TestCase;

class SiteAgentHealthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Http::preventStrayRequests();
    }

    public function test_happy_health_persists_version_and_signs_canonical_string(): void
    {
        $site = $this->activeSite();
        $secret = (string) $site->agent_secret_encrypted;

        Http::fake([
            'https://shop.izyem.example.test/internal/control/v1/health' => Http::response([
                'deamon_version' => '1.4.0',
                'channel_hint' => 'beta',
                'active_theme_id' => 'izyem',
                'php' => '8.3.0',
                'queue_ok' => true,
            ], 200),
        ]);

        $this->actingAs($this->user(OpsRole::Operator))
            ->post(route('ops.sites.health', $site))
            ->assertRedirect(route('ops.sites.edit', $site))
            ->assertSessionHas('status');

        $site->refresh();

        $this->assertNotNull($site->last_health_at);
        $this->assertTrue($site->last_health_payload['ok']);
        $this->assertSame(AgentHealthStatus::Ok, $site->last_health_payload['status']);
        $this->assertSame('1.4.0', $site->reportedDeamonVersion());
        $this->assertSame('izyem', $site->last_health_payload['active_theme_id']);
        $this->assertTrue($site->last_health_payload['queue_ok']);
        $this->assertSame(SiteStatus::Active, $site->status);

        Http::assertSent(function (Request $request) use ($secret): bool {
            $header = static function (string $name) use ($request): string {
                $value = $request->header($name);

                return is_array($value) ? (string) ($value[0] ?? '') : (string) $value;
            };

            $timestamp = $header(ControlPlaneAgentContract::HEADER_TIMESTAMP);
            $nonce = $header(ControlPlaneAgentContract::HEADER_NONCE);
            $signature = $header(ControlPlaneAgentContract::HEADER_SIGNATURE);
            $expected = ControlPlaneAgentSignature::sign($secret, $timestamp, $nonce, '');

            return $request->method() === 'GET'
                && $request->url() === 'https://shop.izyem.example.test/internal/control/v1/health'
                && $request->hasHeader('X-Deamon-Timestamp')
                && $request->hasHeader('X-Deamon-Nonce')
                && $request->hasHeader('X-Deamon-Signature')
                && ! $request->hasHeader('X-Control-Plane-Timestamp')
                && ! $request->hasHeader('X-Control-Plane-Nonce')
                && ! $request->hasHeader('X-Control-Plane-Signature')
                && hash_equals($expected, $signature)
                && ! str_contains($request->url(), $secret)
                && ! str_contains($signature, $secret);
        });

        $this->assertSecretsStayPrivate($site, $secret);
    }

    public function test_invalid_secret_is_not_leaked_on_bad_signature(): void
    {
        $site = $this->activeSite();
        $secret = (string) $site->agent_secret_encrypted;

        Http::fake([
            '*' => Http::response(['message' => 'Unauthorized'], 401),
        ]);

        $this->actingAs($this->user(OpsRole::Operator))
            ->post(route('ops.sites.health', $site))
            ->assertRedirect(route('ops.sites.edit', $site))
            ->assertSessionHas('error');

        $site->refresh();

        $this->assertSame(AgentHealthStatus::Unhealthy, $site->last_health_payload['status']);
        $this->assertSame(AgentHealthReason::BadSignature, $site->last_health_payload['reason']);
        $this->assertSame(SiteStatus::Active, $site->status);

        $this->assertSecretsStayPrivate($site, $secret);
    }

    public function test_timeout_marks_unhealthy_without_changing_site_status(): void
    {
        $site = $this->activeSite();
        $secret = (string) $site->agent_secret_encrypted;

        $this->mock(SiteAgentClient::class, function ($mock) use ($site): void {
            $mock->shouldReceive('health')
                ->once()
                ->withArgs(fn (Site $polled): bool => $polled->is($site))
                ->andReturn(AgentHealthResult::failure(
                    AgentHealthReason::Timeout,
                    'Agent health timed out.',
                ));
        });

        CheckSiteHealthJob::dispatch((string) $site->id);

        $site->refresh();

        $this->assertSame(AgentHealthStatus::Unhealthy, $site->last_health_payload['status']);
        $this->assertSame(AgentHealthReason::Timeout, $site->last_health_payload['reason']);
        $this->assertSame(SiteStatus::Active, $site->status);
        $this->assertArrayNotHasKey('agent_secret', $site->last_health_payload);
        $this->assertStringNotContainsString($secret, (string) json_encode($site->last_health_payload));
    }

    public function test_queue_ok_false_is_unhealthy(): void
    {
        $site = $this->activeSite();

        Http::fake([
            '*' => Http::response([
                'deamon_version' => '1.4.0',
                'queue_ok' => false,
            ], 200),
        ]);

        app(SiteHealthChecker::class)->check($site);
        $site->refresh();

        $this->assertFalse($site->last_health_payload['ok']);
        $this->assertSame(AgentHealthReason::QueueUnhealthy, $site->last_health_payload['reason']);
        $this->assertSame('1.4.0', $site->reportedDeamonVersion());
    }

    public function test_missing_secret_skips_http_and_marks_needs_secret(): void
    {
        $site = $this->activeSite([
            'agent_secret_encrypted' => null,
        ]);

        Http::fake();

        $this->actingAs($this->user(OpsRole::Operator))
            ->post(route('ops.sites.health', $site))
            ->assertRedirect(route('ops.sites.edit', $site))
            ->assertSessionHas('error');

        $site->refresh();

        $this->assertSame(AgentHealthStatus::NeedsSecret, $site->last_health_payload['status']);
        Http::assertNothingSent();
        $this->assertNull($site->agent_secret_encrypted);
    }

    public function test_viewer_cannot_trigger_on_demand_health(): void
    {
        $site = $this->activeSite();

        Http::fake();

        $this->actingAs($this->user(OpsRole::Viewer))
            ->post(route('ops.sites.health', $site))
            ->assertForbidden();

        $this->actingAs($this->user(OpsRole::Viewer))
            ->get(route('ops.sites.edit', $site))
            ->assertOk()
            ->assertSee('Agent health', false)
            ->assertDontSee('action="'.route('ops.sites.health', $site).'"', false);

        $site->refresh();
        $this->assertNull($site->last_health_at);
        Http::assertNothingSent();
    }

    public function test_dispatch_job_queues_per_site_checks(): void
    {
        Queue::fake();

        $one = $this->activeSite();
        $two = $this->activeSite([
            'slug' => 'other',
            'primary_domain' => 'other.example.test',
            'agent_base_url' => 'https://other.example.test',
        ]);

        (new DispatchSiteHealthChecksJob)->handle();

        Queue::assertPushed(CheckSiteHealthJob::class, 2);
        Queue::assertPushed(CheckSiteHealthJob::class, fn (CheckSiteHealthJob $job): bool => $job->siteId === (string) $one->id);
        Queue::assertPushed(CheckSiteHealthJob::class, fn (CheckSiteHealthJob $job): bool => $job->siteId === (string) $two->id);
    }

    public function test_health_poll_is_scheduled(): void
    {
        $events = collect(Schedule::events());

        $this->assertTrue($events->contains(
            static fn ($event): bool => str_contains((string) $event->description, 'DispatchSiteHealthChecksJob')
                || ($event->getSummaryForDisplay() === 'ops-site-agent-health'),
        ));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function activeSite(array $overrides = []): Site
    {
        return Site::factory()->withSecrets()->create(array_merge([
            'slug' => 'izyem',
            'name' => 'Izyem',
            'primary_domain' => 'shop.izyem.example.test',
            'agent_base_url' => 'https://shop.izyem.example.test',
            'channel' => Channel::Main,
            'status' => SiteStatus::Active,
            'coolify_app_uuid' => 'coolify-app-1',
        ], $overrides));
    }

    private function assertSecretsStayPrivate(Site $site, string $secret): void
    {
        $site->refresh();
        $this->assertArrayNotHasKey('agent_secret_encrypted', $site->toArray());
        $this->assertArrayNotHasKey('app_key_encrypted', $site->toArray());
        $this->assertStringNotContainsString($secret, (string) json_encode($site->last_health_payload));

        $html = $this->get(route('ops.sites.edit', $site))->assertOk()->getContent();
        $this->assertStringNotContainsString($secret, $html);
        $this->assertStringNotContainsString((string) $site->app_key_encrypted, $html);
    }

    private function user(OpsRole $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);

        return $user;
    }
}
