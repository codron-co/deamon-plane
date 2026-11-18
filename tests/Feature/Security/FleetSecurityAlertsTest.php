<?php

namespace Tests\Feature\Security;

use App\Enums\OpsRole;
use App\Jobs\SendOpsNotificationJob;
use App\Models\FleetAllowlistEntry;
use App\Models\OpsNotification;
use App\Models\PlatformMailSetting;
use App\Models\SiteSecurityEvent;
use App\Services\Mail\PlatformNotificationCatalog;
use App\Services\Security\FleetSecurityOverview;
use App\Services\Security\SecurityCollector;
use App\Services\Security\SecurityCorrelator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Feature\Security\Concerns\FakesSecuritySites;
use Tests\TestCase;

class FleetSecurityAlertsTest extends TestCase
{
    use FakesSecuritySites;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootSecurityFakes();
        Cache::flush();
    }

    public function test_the_same_ip_on_two_sites_makes_a_correlation_card(): void
    {
        $a = $this->activeSite('alpha');
        $b = $this->activeSite('beta');
        $c = $this->activeSite('gamma');
        $this->event($a, '203.0.113.7', 1);
        $this->event($b, '203.0.113.7', 2);
        $this->event($c, '198.51.100.9', 1);          // one site only
        $this->event($a, '192.0.2.44', 0);            // info does not count
        $this->event($b, '192.0.2.44', 0);

        $cards = app(SecurityCorrelator::class)->crossSite();

        $this->assertCount(1, $cards);
        $this->assertSame('203.0.113.7', $cards[0]['ip']);
        $this->assertSame(2, $cards[0]['sites_count']);
        $this->assertSame(1, $cards[0]['critical']);
        $this->assertEqualsCanonicalizing(['alpha', 'beta'], array_column($cards[0]['sites'], 'slug'));
        $this->assertFalse($cards[0]['blocked']);
    }

    public function test_correlation_respects_the_window(): void
    {
        $a = $this->activeSite('alpha');
        $b = $this->activeSite('beta');
        $this->event($a, '203.0.113.7', 1, now()->subDays(3));
        $this->event($b, '203.0.113.7', 1, now()->subDays(3));

        $this->assertCount(0, app(SecurityCorrelator::class)->crossSite(now()->subDay()));
        $this->assertCount(1, app(SecurityCorrelator::class)->crossSite(now()->subDays(7)));
    }

    public function test_overview_counts_events_sites_and_coverage(): void
    {
        $a = $this->activeSite('alpha');
        $b = $this->activeSite('beta');
        $this->event($a, '203.0.113.7', 2, null, 4);
        $this->event($b, '203.0.113.7', 1, null, 2);
        $this->event($b, '198.51.100.9', 1, now()->subDays(3));

        $overview = app(FleetSecurityOverview::class)->build();

        $this->assertSame(6, $overview['window_24h']['events']);
        $this->assertSame(4, $overview['window_24h']['critical']);
        $this->assertSame(7, $overview['window_7d']['events']);
        $this->assertSame('203.0.113.7', $overview['top_ips'][0]['ip']);
        $this->assertSame(2, $overview['top_ips'][0]['sites']);
        $this->assertSame('Alpha', $overview['top_sites'][0]['name']);
        $this->assertSame(1, count($overview['cross_site']));
        $this->assertSame(2, $overview['coverage']['pending']);
    }

    public function test_a_critical_event_mails_the_super_admin_once_per_window(): void
    {
        Queue::fake();
        $this->readyMail();
        $emin = $this->user(OpsRole::SuperAdmin);
        $this->user(OpsRole::Operator);               // operators are not the audience
        $site = $this->activeSite('alpha');
        $critical = $this->eventPayload('c1', ['severity' => 2, 'rule' => 'honeypot', 'ip' => '203.0.113.50']);

        $this->fakeSite('alpha', $this->summaryPayload([$critical]));
        app(SecurityCollector::class)->collect($site);

        $rows = OpsNotification::query()->get();
        $this->assertCount(1, $rows);
        $this->assertSame($emin->email, $rows[0]->recipient);
        $this->assertSame(PlatformNotificationCatalog::SECURITY_ALERT, $rows[0]->notification_key);
        $this->assertStringContainsString('honeypot', $rows[0]->body);
        $this->assertStringContainsString('203.0.113.50', $rows[0]->body);
        Queue::assertPushed(SendOpsNotificationJob::class, 1);

        // The same rule + IP again a minute later: new event id, no second mail.
        $this->fakeSite('alpha', $this->summaryPayload([$critical, $this->eventPayload('c2', ['severity' => 2, 'rule' => 'honeypot', 'ip' => '203.0.113.50'])]));
        app(SecurityCollector::class)->collect($site->fresh());

        $this->assertSame(1, OpsNotification::query()->count());
    }

    public function test_warnings_alone_do_not_mail(): void
    {
        Queue::fake();
        $this->readyMail();
        $this->user(OpsRole::SuperAdmin);
        $site = $this->activeSite('alpha');
        $this->fakeSite('alpha', $this->summaryPayload([$this->eventPayload('w1')]));

        app(SecurityCollector::class)->collect($site);

        $this->assertSame(0, OpsNotification::query()->count());
    }

    public function test_the_same_ip_reaching_a_second_site_mails_a_cross_site_alert_once(): void
    {
        Queue::fake();
        $this->readyMail();
        $this->user(OpsRole::SuperAdmin);
        $a = $this->activeSite('alpha');
        $b = $this->activeSite('beta');
        $this->fakeSite('alpha', $this->summaryPayload([$this->eventPayload('a1', ['ip' => '203.0.113.99'])]));
        $this->fakeSite('beta', $this->summaryPayload([$this->eventPayload('b1', ['ip' => '203.0.113.99'])]));

        app(SecurityCollector::class)->collect($a);
        $this->assertSame(0, OpsNotification::query()->count(), 'one site is not a cross-site pattern');

        app(SecurityCollector::class)->collect($b);
        $this->assertSame(1, OpsNotification::query()->count());
        $this->assertStringContainsString('203.0.113.99', OpsNotification::query()->value('body'));

        $this->fakeSite('beta', $this->summaryPayload([$this->eventPayload('b2', ['ip' => '203.0.113.99'])]));
        app(SecurityCollector::class)->collect($b->fresh());
        $this->assertSame(1, OpsNotification::query()->count(), 'suppressed inside the window');
    }

    public function test_an_allowlisted_ip_never_raises_a_cross_site_alert(): void
    {
        Queue::fake();
        $this->readyMail();
        $this->user(OpsRole::SuperAdmin);
        FleetAllowlistEntry::query()->create(['uid' => (string) Str::uuid(), 'ip' => '203.0.113.99', 'label' => 'Plane', 'status' => 'applied']);
        $a = $this->activeSite('alpha');
        $b = $this->activeSite('beta');
        $this->event($a, '203.0.113.99', 1);
        $this->fakeSite('beta', $this->summaryPayload([$this->eventPayload('b1', ['ip' => '203.0.113.99'])]));

        app(SecurityCollector::class)->collect($b);

        $this->assertSame(0, OpsNotification::query()->count());
    }

    public function test_no_mail_when_platform_mail_is_not_ready(): void
    {
        Queue::fake();
        $this->user(OpsRole::SuperAdmin);
        $site = $this->activeSite('alpha');
        $this->fakeSite('alpha', $this->summaryPayload([$this->eventPayload('c1', ['severity' => 2])]));

        app(SecurityCollector::class)->collect($site);

        $this->assertSame(0, OpsNotification::query()->count());
        $this->assertSame(1, SiteSecurityEvent::query()->count(), 'the event is stored even when mail cannot go out');
    }

    private function event($site, string $ip, int $severity, $at = null, int $count = 1): void
    {
        SiteSecurityEvent::query()->create([
            'site_id' => $site->id,
            'event_uid' => (string) Str::uuid(),
            'occurred_at' => $at ?? now()->subMinutes(10),
            'type' => 'probe',
            'severity' => $severity,
            'rule' => 'sensitive_probe',
            'ip' => $ip,
            'count' => $count,
        ]);
    }

    private function readyMail(): void
    {
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
}
