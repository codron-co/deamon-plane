<?php

namespace Tests\Feature\Comail;

use App\Models\MailServer;
use App\Models\Site;
use App\Services\Comail\ComailWebmailHost;
use App\Services\Comail\ComailWebmailSso;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Comail\Support\ComailTestHelpers;
use Tests\Feature\Comail\Support\FakeMailcow;
use Tests\TestCase;

class ComailWebmailHostTest extends TestCase
{
    use ComailTestHelpers;
    use RefreshDatabase;

    private const SERVER_IP = '203.0.113.10';

    private const OWN = 'https://mail.acme.test';

    private Site $site;

    /** @var int|string|callable(): mixed status, or a callable that throws */
    private mixed $probeAnswer = 302;

    private int $probes = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpComail();
        $this->site = $this->comailSite();
        $this->comailDomain($this->site);
        $this->mailcow->addMailbox('info@acme.test');

        // mail.example.test is the server's base_url host.
        $this->resolver
            ->set('A', 'mail.example.test', [['ip' => self::SERVER_IP]])
            ->set('A', 'mail.acme.test', [['ip' => self::SERVER_IP]]);

        Http::fake([
            self::OWN.'/webmail/*' => function (Request $request) {
                $this->probes++;
                if (is_callable($this->probeAnswer)) {
                    ($this->probeAnswer)();
                }

                return Http::response('', (int) $this->probeAnswer, $this->probeAnswer === 302 ? ['Location' => '/webmail/?_task=login'] : []);
            },
        ]);
    }

    public function test_own_host_is_used_when_dns_points_at_the_server_and_the_probe_succeeds(): void
    {
        $this->assertSame(self::OWN, $this->host()->forAddress($this->server(), 'info@ACME.test'));

        Http::assertSent(fn (Request $request): bool => $request->url() === self::OWN.'/webmail/' && $request->method() === 'GET');

        $this->assertSame('https://mail.other.test', $this->hostFor('other.test', 200));
    }

    public function test_site_webmail_login_url_uses_the_own_host_and_the_token_still_redeems(): void
    {
        $response = $this->signed('POST', '/mailboxes/info@acme.test/webmail-login', $this->site)->assertOk();
        $url = (string) $response->json('url');

        $this->assertMatchesRegularExpression('~\A'.preg_quote(self::OWN, '~').'/webmail/\?_task=login&_comail_sso=[a-f0-9]{64}\z~', $url);

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $created = collect($this->mailcow->appPasswords)->firstWhere('name', ComailWebmailSso::APP_NAME);
        $this->assertSame(
            ['username' => 'info@acme.test', 'password' => $created['app_passwd']],
            app(ComailWebmailSso::class)->redeem((string) $query['_comail_sso']),
        );
    }

    public function test_ops_webmail_redirect_uses_the_own_host(): void
    {
        $admin = $this->opsUser();

        $response = $this->actingAs($admin)
            ->post(route('ops.sites.comail.webmail', [$this->site, 'info@acme.test']))
            ->assertStatus(302);

        $this->assertStringStartsWith(self::OWN.'/webmail/?_task=login&_comail_sso=', (string) $response->headers->get('Location'));
    }

    public function test_falls_back_when_dns_points_elsewhere_without_probing(): void
    {
        $this->resolver->set('A', 'mail.acme.test', [['ip' => '198.51.100.7']]);
        $this->assertSame(FakeMailcow::BASE, $this->host()->forDomain($this->server(), 'acme.test'));

        // One of several A records elsewhere is not good enough either.
        $this->resolver->set('A', 'mail.split.test', [['ip' => self::SERVER_IP], ['ip' => '198.51.100.7']]);
        $this->assertSame(FakeMailcow::BASE, $this->host()->forDomain($this->server(), 'split.test'));

        // No record at all.
        $this->assertSame(FakeMailcow::BASE, $this->host()->forDomain($this->server(), 'nothing.test'));

        $this->assertSame(0, $this->probes);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/webmail/'));
    }

    public function test_falls_back_when_the_server_ip_cannot_be_resolved(): void
    {
        $this->resolver->set('A', 'mail.example.test', []);

        $this->assertSame(FakeMailcow::BASE, $this->host()->forDomain($this->server(), 'acme.test'));
        $this->assertSame(0, $this->probes);
    }

    public function test_falls_back_when_tls_fails(): void
    {
        $this->probeAnswer = static function (): never {
            throw new ConnectionException('cURL error 60: SSL: no alternative certificate subject name matches target host name');
        };

        $this->assertSame(FakeMailcow::BASE, $this->host()->forDomain($this->server(), 'acme.test'));
        $this->assertSame(1, $this->probes);
    }

    public function test_falls_back_when_the_probe_times_out(): void
    {
        $this->probeAnswer = static function (): never {
            throw new ConnectionException('cURL error 28: Operation timed out after 4001 milliseconds');
        };

        $this->assertSame(FakeMailcow::BASE, $this->host()->forDomain($this->server(), 'acme.test'));
        $this->assertSame(1, $this->probes);
    }

    public function test_falls_back_on_other_http_statuses(): void
    {
        foreach ([404, 500, 301, 503] as $i => $status) {
            $this->probeAnswer = $status;
            $this->assertSame(FakeMailcow::BASE, $this->hostFor("s{$i}.test", $status), "status {$status}");
        }
    }

    public function test_invalid_or_server_domains_use_the_base_url_without_lookups(): void
    {
        foreach (['', 'no-at-sign', 'x@', 'x@bad domain', 'x@-bad.test', 'x@example.test'] as $address) {
            $this->assertSame(FakeMailcow::BASE, $this->host()->forAddress($this->server(), $address), $address);
        }

        $this->assertSame(0, $this->probes);
    }

    public function test_usable_answer_is_cached_for_30_minutes(): void
    {
        $this->assertSame(self::OWN, $this->host()->forDomain($this->server(), 'acme.test'));
        $this->assertSame(1, $this->probes);

        // The cached answer survives a broken probe until it expires.
        $this->probeAnswer = 500;
        $this->travel(ComailWebmailHost::POSITIVE_TTL - 5)->seconds();
        $this->assertSame(self::OWN, $this->host()->forDomain($this->server(), 'acme.test'));
        $this->assertSame(1, $this->probes);

        $this->travel(10)->seconds();
        $this->assertSame(FakeMailcow::BASE, $this->host()->forDomain($this->server(), 'acme.test'));
        $this->assertSame(2, $this->probes);
    }

    public function test_unusable_answer_is_cached_for_10_minutes(): void
    {
        $this->probeAnswer = 503;
        $this->assertSame(FakeMailcow::BASE, $this->host()->forDomain($this->server(), 'acme.test'));
        $this->assertSame(1, $this->probes);

        $this->probeAnswer = 302;
        $this->travel(ComailWebmailHost::NEGATIVE_TTL - 5)->seconds();
        $this->assertSame(FakeMailcow::BASE, $this->host()->forDomain($this->server(), 'acme.test'));
        $this->assertSame(1, $this->probes);

        $this->travel(10)->seconds();
        $this->assertSame(self::OWN, $this->host()->forDomain($this->server(), 'acme.test'));
        $this->assertSame(2, $this->probes);

        // A DNS mismatch is a cached negative too.
        $this->resolver->set('A', 'mail.moved.test', [['ip' => '198.51.100.7']]);
        $this->assertSame(FakeMailcow::BASE, $this->host()->forDomain($this->server(), 'moved.test'));
        $this->resolver->set('A', 'mail.moved.test', [['ip' => self::SERVER_IP]]);
        $this->assertSame(FakeMailcow::BASE, $this->hostFor('moved.test', 200));
    }

    public function test_overview_webmail_url_prefers_the_primary_domains_own_host(): void
    {
        $this->signed('GET', '/overview', $this->site)
            ->assertOk()
            ->assertJsonPath('webmail_url', self::OWN)
            ->assertJsonPath('panel_url', FakeMailcow::BASE.'/?panel=1');
    }

    public function test_overview_webmail_url_falls_back_to_the_base_url(): void
    {
        $this->resolver->set('A', 'mail.acme.test', [['ip' => '198.51.100.7']]);

        $this->signed('GET', '/overview', $this->site)
            ->assertOk()
            ->assertJsonPath('webmail_url', FakeMailcow::BASE);
    }

    public function test_overview_without_an_active_domain_uses_the_base_url(): void
    {
        $site = $this->comailSite('empty.test');

        $this->signed('GET', '/overview', $site)
            ->assertOk()
            ->assertJsonPath('webmail_url', FakeMailcow::BASE);
        $this->assertSame(0, $this->probes);
    }

    private function host(): ComailWebmailHost
    {
        return app(ComailWebmailHost::class);
    }

    private function server(): MailServer
    {
        return MailServer::comail();
    }

    /**
     * Resolves `$domain`'s own host for the probe with a stub answering `$status`.
     */
    private function hostFor(string $domain, int $status): string
    {
        $this->resolver->set('A', 'mail.'.$domain, [['ip' => self::SERVER_IP]]);
        Http::fake(['https://mail.'.$domain.'/webmail/*' => Http::response('', $status)]);

        return $this->host()->forDomain($this->server(), $domain);
    }
}
