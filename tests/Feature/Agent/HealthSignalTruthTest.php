<?php

namespace Tests\Feature\Agent;

use App\Enums\Channel;
use App\Enums\SiteStatus;
use App\Jobs\CheckSiteHealthJob;
use App\Jobs\DispatchSiteHealthChecksJob;
use App\Models\OpsNotification;
use App\Models\PlatformMailSetting;
use App\Models\Site;
use App\Models\User;
use App\Services\Agent\AgentHealthStatus;
use App\Services\Agent\SiteHealthChecker;
use App\Services\Mail\PlatformNotificationCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Audit P11 (S4-B01..B04): the health signal only raises an alert on real,
 * repeated evidence, and a failed poll does not erase what we knew.
 */
class HealthSignalTruthTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://shop.signal.example.test/internal/control/v1/health';

    private mixed $nextResponse = null;

    private int $sites = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        // One stub that answers whatever the test set last (Http::fake stubs stack
        // and the first match wins, so re-faking per poll would not change it).
        Http::fake([self::URL => fn () => $this->nextResponse]);
        Queue::fake();
        User::factory()->create(['email' => 'ops@example.test']);
        PlatformMailSetting::query()->create([
            'enabled' => true,
            'host' => 'smtp.example.test',
            'port' => 465,
            'encryption' => 'ssl',
            'username' => 'mailer@example.test',
            'password' => 'test-password',
            'from_address' => 'noreply@example.test',
            'from_name' => 'Deamon Plane',
            'notifications' => [],
        ]);
    }

    public function test_one_failed_poll_does_not_alert_but_two_in_a_row_do(): void
    {
        $site = $this->site();
        $this->poll($site, $this->healthy());
        $this->assertSame(AgentHealthStatus::Ok, $site->fresh()->last_health_notify_status);

        $this->poll($site, Http::response('', 500));
        $this->assertSame(0, $this->downAlerts());
        $this->assertSame(1, $site->fresh()->health_fail_streak);

        $this->poll($site, Http::response('', 500));
        $this->assertSame(1, $this->downAlerts());
        $this->assertSame(AgentHealthStatus::Unhealthy, $site->fresh()->last_health_notify_status);

        $this->poll($site, Http::response('', 500));
        $this->assertSame(1, $this->downAlerts(), 'A site that stays down is not re-alerted every poll.');

        $this->poll($site, $this->healthy());
        $this->assertSame(0, $site->fresh()->health_fail_streak);
        $this->assertSame(1, OpsNotification::query()->where('notification_key', PlatformNotificationCatalog::SITE_UP)->count());
    }

    public function test_a_throttled_poll_neither_alerts_nor_counts(): void
    {
        $site = $this->site();
        $this->poll($site, $this->healthy());

        foreach (range(1, 3) as $attempt) {
            $this->poll($site, Http::response(['message' => 'Too Many Attempts.'], 429));
        }

        $fresh = $site->fresh();
        $this->assertSame(0, $fresh->health_fail_streak);
        $this->assertSame(AgentHealthStatus::Ok, $fresh->last_health_notify_status);
        $this->assertSame(0, $this->downAlerts());
    }

    public function test_failures_while_deploying_do_not_count(): void
    {
        $site = $this->site();
        $this->poll($site, $this->healthy());
        $site->forceFill(['status' => SiteStatus::Deploying])->save();

        foreach (range(1, 3) as $attempt) {
            $this->poll($site, Http::response('', 502));
        }

        $this->assertSame(0, $site->fresh()->health_fail_streak);
        $this->assertSame(0, $this->downAlerts());
    }

    public function test_a_failed_poll_keeps_the_last_known_version_and_theme(): void
    {
        $site = $this->site();
        $this->poll($site, $this->healthy());
        $this->poll($site, Http::response('', 500));

        $fresh = $site->fresh();
        $this->assertFalse($fresh->last_health_payload['ok']);
        $this->assertSame('1.2.33', $fresh->reportedDeamonVersion());
        $this->assertSame('signal', $fresh->last_health_payload['active_theme_id']);
    }

    public function test_a_web_page_answering_200_does_not_verify_the_secret(): void
    {
        $site = $this->site();
        $this->poll($site, Http::response('<!DOCTYPE html><html><body>Shop</body></html>', 200, ['Content-Type' => 'text/html']));

        $fresh = $site->fresh();
        $this->assertFalse($fresh->agentSecretIsVerified());
        $this->assertSame('unverified', $fresh->agentSecretFleetState());
        $this->assertSame(0, Site::query()->verifiedAgentSecret()->count());
        $this->assertSame(1, Site::query()->unverifiedAgentSecret()->count());

        $this->poll($site, $this->healthy());

        $this->assertTrue($site->fresh()->agentSecretIsVerified());
        $this->assertSame(1, Site::query()->verifiedAgentSecret()->count());
        $this->assertSame(0, Site::query()->unverifiedAgentSecret()->count());
    }

    public function test_only_sites_with_a_running_cms_are_polled(): void
    {
        $polled = [
            $this->site(['status' => SiteStatus::Active]),
            $this->site(['status' => SiteStatus::Error]),
            $this->site(['status' => SiteStatus::Deploying]),
        ];
        foreach ([SiteStatus::Stopped, SiteStatus::Draft, SiteStatus::Provisioning] as $status) {
            $this->site(['status' => $status]);
        }

        (new DispatchSiteHealthChecksJob)->handle();

        Queue::assertPushed(CheckSiteHealthJob::class, 3);
        foreach ($polled as $site) {
            Queue::assertPushed(CheckSiteHealthJob::class, fn (CheckSiteHealthJob $job): bool => $job->siteId === $site->id);
        }
    }

    private function poll(Site $site, mixed $response): void
    {
        $this->nextResponse = $response;
        app(SiteHealthChecker::class)->check($site->fresh());
    }

    private function healthy(): mixed
    {
        return Http::response([
            'deamon_version' => '1.2.33',
            'active_theme_id' => 'signal',
            'queue_ok' => true,
        ], 200);
    }

    private function downAlerts(): int
    {
        return OpsNotification::query()->where('notification_key', PlatformNotificationCatalog::SITE_DOWN)->count();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function site(array $overrides = []): Site
    {
        $n = ++$this->sites;

        return Site::factory()->withSecrets()->create(array_merge([
            'slug' => 'signal-'.$n,
            'primary_domain' => $n === 1 ? 'shop.signal.example.test' : 'shop'.$n.'.signal.example.test',
            'agent_base_url' => $n === 1 ? 'https://shop.signal.example.test' : 'https://shop'.$n.'.signal.example.test',
            'channel' => Channel::Main,
            'status' => SiteStatus::Active,
            'coolify_app_uuid' => 'signal-app-'.$n,
        ], $overrides));
    }
}
