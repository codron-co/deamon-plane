<?php

namespace Tests\Feature\Ops;

use App\Enums\DeploymentStatus;
use App\Enums\DeploymentTrigger;
use App\Enums\SiteStatus;
use App\Jobs\ConfigureSiteMailJob;
use App\Jobs\PushPlatformMailJob;
use App\Jobs\ReconcileMailPushJob;
use App\Models\Deployment;
use App\Models\MailServer;
use App\Models\PlatformMailSetting;
use App\Models\Site;
use App\Services\Agent\ControlPlaneAgentContract;
use App\Services\Coolify\Dto\CoolifyDeployment;
use App\Services\Mail\PlatformMailConfigurer;
use App\Services\Mail\SiteMailConfigurer;
use App\Services\Sites\SiteProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * 2026-10-27: four sites provisioned in one evening never got the platform SMTP,
 * so password reset, admin invites, weekly reports and bulletins did not send.
 * A finished provision deploy now queues the push, and an hourly pass retries
 * whatever a site has not accepted.
 */
class ProvisionMailPushTest extends TestCase
{
    use RefreshDatabase;

    public function test_provision_success_queues_the_platform_mail_push(): void
    {
        Queue::fake();
        $this->readySettings();
        $site = $this->provisioningSite();

        $this->succeed($site, DeploymentTrigger::Create);

        Queue::assertPushed(PushPlatformMailJob::class, fn (PushPlatformMailJob $job): bool => $job->siteId === (string) $site->id
            && $job->delay !== null);
        Queue::assertNotPushed(ConfigureSiteMailJob::class);
    }

    public function test_site_with_mail_server_also_gets_its_own_smtp(): void
    {
        Queue::fake();
        $this->readySettings();
        $site = $this->provisioningSite([
            'mail_server_id' => MailServer::factory()->hostingerReady('hapi-token')->create()->id,
        ]);

        $this->succeed($site, DeploymentTrigger::Create);

        Queue::assertPushed(PushPlatformMailJob::class);
        Queue::assertPushed(ConfigureSiteMailJob::class, fn (ConfigureSiteMailJob $job): bool => $job->siteId === (string) $site->id);
    }

    public function test_a_site_that_already_accepted_the_push_is_not_pushed_again(): void
    {
        Queue::fake();
        $this->readySettings();
        $site = $this->provisioningSite(['platform_mail_pushed_at' => now()->subDay()]);

        $this->succeed($site, DeploymentTrigger::ChannelSwitch);

        Queue::assertNotPushed(PushPlatformMailJob::class);
    }

    public function test_a_failed_earlier_push_is_retried_after_the_deploy(): void
    {
        Queue::fake();
        $this->readySettings();
        $site = $this->provisioningSite([
            'platform_mail_pushed_at' => now()->subDays(2),
            'platform_mail_push_failed_at' => now()->subDay(),
            'platform_mail_push_error' => 'timeout',
        ]);

        $this->succeed($site, DeploymentTrigger::Create);

        Queue::assertPushed(PushPlatformMailJob::class);
    }

    public function test_nothing_is_queued_while_platform_mail_is_not_set_up(): void
    {
        Queue::fake();
        $site = $this->provisioningSite();

        $this->succeed($site, DeploymentTrigger::Create);

        Queue::assertNotPushed(PushPlatformMailJob::class);
    }

    public function test_reconcile_pushes_only_active_sites_still_missing_platform_mail(): void
    {
        $this->readySettings();
        $missing = $this->activeSite('missing.example.test');
        $failed = $this->activeSite('failed.example.test', [
            'platform_mail_pushed_at' => now()->subDays(2),
            'platform_mail_push_failed_at' => now()->subDay(),
            'platform_mail_push_error' => 'http_500',
        ]);
        $done = $this->activeSite('done.example.test', ['platform_mail_pushed_at' => now()->subHour()]);
        $archived = $this->activeSite('archived.example.test', ['status' => SiteStatus::Archived]);

        Http::preventStrayRequests();
        Http::fake([
            'https://*.example.test/*' => Http::response(['ok' => true], 200),
        ]);

        (new ReconcileMailPushJob)->handle(
            app(PlatformMailConfigurer::class),
            app(SiteMailConfigurer::class),
        );

        $path = ControlPlaneAgentContract::platformMailConfigurePath();
        $hosts = Http::recorded()
            ->map(fn (array $pair): string => (string) parse_url($pair[0]->url(), PHP_URL_HOST))
            ->sort()
            ->values()
            ->all();
        $this->assertSame(['failed.example.test', 'missing.example.test'], $hosts);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), $path)
            && str_contains($request->body(), '"enabled":true'));

        $this->assertNotNull($missing->fresh()->platform_mail_pushed_at);
        $this->assertNull($failed->fresh()->platform_mail_push_failed_at);
        $this->assertTrue($done->fresh()->platform_mail_pushed_at->lt(now()->subMinutes(30)));
        $this->assertNull($archived->fresh()->platform_mail_pushed_at);
    }

    public function test_reconcile_records_a_failure_and_keeps_going(): void
    {
        $this->readySettings();
        $down = $this->activeSite('down.example.test');
        $up = $this->activeSite('up.example.test');

        Http::preventStrayRequests();
        Http::fake([
            'https://down.example.test/*' => Http::response([], 503),
            'https://up.example.test/*' => Http::response(['ok' => true], 200),
        ]);

        (new ReconcileMailPushJob)->handle(
            app(PlatformMailConfigurer::class),
            app(SiteMailConfigurer::class),
        );

        $this->assertNull($down->fresh()->platform_mail_pushed_at);
        $this->assertNotNull($down->fresh()->platform_mail_push_failed_at);
        $this->assertNotNull($up->fresh()->platform_mail_pushed_at);
    }

    public function test_reconcile_does_nothing_without_ready_platform_mail(): void
    {
        $this->activeSite('idle.example.test');
        Http::preventStrayRequests();
        Http::fake();

        (new ReconcileMailPushJob)->handle(
            app(PlatformMailConfigurer::class),
            app(SiteMailConfigurer::class),
        );

        Http::assertNothingSent();
    }

    private function succeed(Site $site, DeploymentTrigger $trigger): void
    {
        $deployment = Deployment::factory()->create([
            'site_id' => $site->id,
            'status' => DeploymentStatus::InProgress,
            'trigger' => $trigger,
        ]);

        app(SiteProvisioner::class)->markSucceeded($site, $deployment, new CoolifyDeployment('dep-1', 'finished', null, null, []));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function provisioningSite(array $attributes = []): Site
    {
        return Site::factory()->withSecrets()->create(array_merge([
            'status' => SiteStatus::Provisioning,
            'primary_domain' => 'new-site.example.test',
            'agent_base_url' => 'https://new-site.example.test',
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function activeSite(string $host, array $attributes = []): Site
    {
        return Site::factory()->withSecrets()->create(array_merge([
            'status' => SiteStatus::Active,
            'primary_domain' => $host,
            'agent_base_url' => 'https://'.$host,
        ], $attributes));
    }

    private function readySettings(): PlatformMailSetting
    {
        return PlatformMailSetting::query()->create([
            'enabled' => true,
            'host' => 'smtp.example.com',
            'port' => 465,
            'encryption' => 'ssl',
            'username' => 'u@example.com',
            'password' => 'super-secret-smtp',
            'from_address' => 'noreply@example.com',
            'from_name' => 'Test',
            'default_admin_recipient' => 'ops@example.com',
            'notifications' => [],
        ]);
    }
}
