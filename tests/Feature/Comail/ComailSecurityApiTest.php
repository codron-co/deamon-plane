<?php

namespace Tests\Feature\Comail;

use App\Jobs\EnsureComailDomainJob;
use App\Models\MailPackage;
use App\Models\Site;
use App\Models\SiteComailDomain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Comail\Support\ComailTestHelpers;
use Tests\TestCase;

class ComailSecurityApiTest extends TestCase
{
    use ComailTestHelpers;
    use RefreshDatabase;

    private const DKIM = 'v=DKIM1;k=rsa;t=s;s=email;p=MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAtestkey';

    /** @var array<string, int> policy host => HTTP status the fake answers */
    private array $policyHosts = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpComail();
        Http::fake([
            'https://mta-sts.*' => function ($request) {
                $host = (string) parse_url($request->url(), PHP_URL_HOST);
                $status = $this->policyHosts[$host] ?? 0;
                if ($status === 0) {
                    return Http::response('', 404);
                }

                return Http::response("version: STSv1\nmode: testing\nmx: mail.example.test\nmax_age: 86400\n", $status);
            },
        ]);
    }

    /**
     * A local-mode domain whose public DNS already shows every record.
     */
    private function healthyDomain(Site $site, string $domain = 'acme.test'): SiteComailDomain
    {
        $row = $this->comailDomain($site, $domain, ['mx_active_at' => now(), 'mta_sts_id' => '20261026120000']);
        $this->resolver
            ->set('MX', $domain, [['target' => 'mail.example.test', 'priority' => 10]])
            ->set('TXT', $domain, [['value' => 'v=spf1 mx a:mail.example.test ~all']])
            ->set('TXT', 'dkim._domainkey.'.$domain, [['value' => self::DKIM]])
            ->set('TXT', '_dmarc.'.$domain, [['value' => 'v=DMARC1; p=none; rua=mailto:dmarc@codron.co; fo=1']])
            ->set('A', 'mta-sts.'.$domain, [['ip' => '31.97.79.49']])
            ->set('TXT', '_mta-sts.'.$domain, [['value' => 'v=STSv1; id=20261026120000']])
            ->set('TXT', '_smtp._tls.'.$domain, [['value' => 'v=TLSRPTv1; rua=mailto:tlsrpt@codron.co']]);
        $this->policyHosts['mta-sts.'.$domain] = 200;

        return $row;
    }

    public function test_security_overview_reports_every_check_for_a_local_domain(): void
    {
        $site = $this->comailSite();
        $this->healthyDomain($site);
        $this->mailcow->rateLimits['acme.test'] = ['value' => '300', 'frame' => 'h'];

        $response = $this->signed('GET', '/security', $site)->assertOk();

        $response->assertJsonPath('antivirus', true)
            ->assertJsonPath('spam.default_score', '8,15')
            ->assertJsonPath('domains.0.domain', 'acme.test')
            ->assertJsonPath('domains.0.mode', 'local')
            ->assertJsonPath('domains.0.dmarc_policy', 'none')
            ->assertJsonPath('domains.0.rate_limit', ['value' => 300, 'frame' => 'h']);
        foreach (['mx', 'spf', 'dkim', 'dmarc', 'mta_sts', 'tls_rpt'] as $key) {
            $response->assertJsonPath('domains.0.checks.'.$key.'.status', 'ok');
        }
        $response->assertJsonPath('domains.0.checks.dmarc.expected', 'v=DMARC1; p=none; rua=mailto:dmarc@codron.co; fo=1');
    }

    public function test_security_overview_marks_missing_and_unserved_records(): void
    {
        $site = $this->comailSite();
        $this->healthyDomain($site);
        $this->resolver
            ->set('TXT', '_dmarc.acme.test', [['value' => 'v=DMARC1; p=reject']])
            ->set('TXT', '_smtp._tls.acme.test', []);
        // DNS is right but mailcow does not serve the policy for mta-sts.acme.test yet.
        $this->policyHosts['mta-sts.acme.test'] = 0;

        $this->signed('GET', '/security', $site)
            ->assertOk()
            ->assertJsonPath('domains.0.checks.dmarc.status', 'different')
            ->assertJsonPath('domains.0.checks.dmarc.current', 'v=DMARC1; p=reject')
            ->assertJsonPath('domains.0.checks.tls_rpt.status', 'missing')
            ->assertJsonPath('domains.0.checks.mta_sts.status', 'different')
            ->assertJsonPath('domains.0.rate_limit', ['value' => null, 'frame' => null]);
    }

    public function test_relay_domain_has_only_spf_and_dkim_checks(): void
    {
        $site = $this->comailSite();
        $this->comailDomain($site);
        $this->resolver
            ->set('MX', 'acme.test', [['target' => 'mx1.hostinger.com', 'priority' => 5]])
            ->set('TXT', 'dkim._domainkey.acme.test', [['value' => self::DKIM]]);

        $this->signed('GET', '/security', $site)
            ->assertOk()
            ->assertJsonPath('domains.0.mode', 'relay')
            ->assertJsonPath('domains.0.checks.mx.status', 'not_applicable')
            ->assertJsonPath('domains.0.checks.mx.current', 'mx1.hostinger.com')
            ->assertJsonPath('domains.0.checks.dkim.status', 'ok')
            ->assertJsonPath('domains.0.checks.spf.status', 'missing')
            ->assertJsonPath('domains.0.checks.dmarc.status', 'not_applicable')
            ->assertJsonPath('domains.0.checks.mta_sts.status', 'not_applicable')
            ->assertJsonPath('domains.0.checks.tls_rpt.status', 'not_applicable');
    }

    public function test_apply_on_a_local_domain_writes_security_records_policy_and_report_authorization(): void
    {
        $this->cloudflareAccount();
        $this->cloudflare->zone('acme.test');
        $this->cloudflare->zone('codron.co');
        $this->cloudflare->record('acme.test', 'TXT', '_dmarc.acme.test', 'v=DMARC1; p=none');
        $site = $this->comailSite();
        $this->comailDomain($site);

        $this->signed('POST', '/domains/acme.test/dns', $site)->assertOk();

        $this->assertSame(['v=DMARC1; p=none; rua=mailto:dmarc@codron.co; fo=1'], array_column($this->cloudflare->at('_dmarc.acme.test'), 'content'));
        $mtaSts = $this->cloudflare->at('mta-sts.acme.test');
        $this->assertSame('A', $mtaSts[0]['type']);
        $this->assertSame('31.97.79.49', $mtaSts[0]['content']);
        $this->assertFalse($mtaSts[0]['proxied']);
        $row = SiteComailDomain::query()->where('domain', 'acme.test')->firstOrFail();
        $this->assertMatchesRegularExpression('/\A\d{14}\z/', (string) $row->mta_sts_id);
        $this->assertSame(['v=STSv1; id='.$row->mta_sts_id], array_column($this->cloudflare->at('_mta-sts.acme.test'), 'content'));
        $this->assertSame(['v=TLSRPTv1; rua=mailto:tlsrpt@codron.co'], array_column($this->cloudflare->at('_smtp._tls.acme.test'), 'content'));
        $this->assertSame(['v=DMARC1'], array_column($this->cloudflare->at('acme.test._report._dmarc.codron.co'), 'content'));
        $this->assertNotNull($row->report_auth_at);

        $this->assertSame([
            'domain' => 'acme.test',
            'version' => 'STSv1',
            'mode' => 'testing',
            'mx' => 'mail.example.test',
            'max_age' => '86400',
            'active' => '1',
        ], $this->mailcow->mtaSts['acme.test']);

        // Idempotent.
        $writes = count($this->cloudflare->writes);
        $this->signed('POST', '/domains/acme.test/dns', $site)->assertOk();
        $this->assertCount($writes, $this->cloudflare->writes);
        $this->assertCount(1, array_keys($this->mailcow->paths(), '/add/mta-sts'));
    }

    public function test_existing_mailcow_policy_counts_as_present(): void
    {
        $this->cloudflareAccount();
        $this->cloudflare->zone('acme.test');
        $site = $this->comailSite();
        $this->comailDomain($site);
        $this->mailcow->mtaSts['acme.test'] = ['mode' => 'enforce'];

        $this->signed('POST', '/domains/acme.test/dns', $site)->assertOk();

        // Ops' enforce mode stays; the DNS records still follow.
        $this->assertSame(['mode' => 'enforce'], $this->mailcow->mtaSts['acme.test']);
        $this->assertCount(1, $this->cloudflare->at('_mta-sts.acme.test'));
    }

    public function test_customer_mta_sts_host_and_tls_report_are_left_alone(): void
    {
        $this->cloudflareAccount();
        $this->cloudflare->zone('acme.test');
        $this->cloudflare->record('acme.test', 'CNAME', 'mta-sts.acme.test', 'mta-sts.example-provider.test');
        $this->cloudflare->record('acme.test', 'TXT', '_smtp._tls.acme.test', 'v=TLSRPTv1; rua=mailto:tls@acme.test');
        $site = $this->comailSite();
        $this->comailDomain($site);

        $this->signed('POST', '/domains/acme.test/dns', $site)->assertOk();

        $this->assertSame(['mta-sts.example-provider.test'], array_column($this->cloudflare->at('mta-sts.acme.test'), 'content'));
        $this->assertSame([], $this->cloudflare->at('_mta-sts.acme.test'));
        $this->assertSame(['v=TLSRPTv1; rua=mailto:tls@acme.test'], array_column($this->cloudflare->at('_smtp._tls.acme.test'), 'content'));
    }

    public function test_relay_domain_gets_no_security_records(): void
    {
        $this->cloudflareAccount();
        $this->cloudflare->zone('acme.test');
        $this->cloudflare->record('acme.test', 'MX', 'acme.test', 'aspmx.l.google.com', 1);
        $site = $this->comailSite();
        $this->comailDomain($site);

        $this->signed('POST', '/domains/acme.test/dns', $site)->assertOk();

        $this->assertSame(['v=DMARC1; p=none'], array_column($this->cloudflare->at('_dmarc.acme.test'), 'content'));
        $this->assertSame([], $this->cloudflare->at('mta-sts.acme.test'));
        $this->assertSame([], $this->cloudflare->at('_smtp._tls.acme.test'));
        $this->assertNotContains('/add/mta-sts', $this->mailcow->paths());
    }

    public function test_dmarc_policy_is_written_audited_and_reported(): void
    {
        $this->cloudflareAccount();
        $this->cloudflare->zone('acme.test');
        $this->cloudflare->record('acme.test', 'TXT', '_dmarc.acme.test', 'v=DMARC1; p=none; rua=mailto:dmarc@codron.co; fo=1');
        $site = $this->comailSite();
        $this->healthyDomain($site);
        $this->resolver->set('TXT', '_dmarc.acme.test', [['value' => 'v=DMARC1; p=quarantine; rua=mailto:dmarc@codron.co; fo=1']]);

        $this->signed('POST', '/domains/acme.test/dmarc', $site, ['policy' => 'quarantine'])
            ->assertOk()
            ->assertJsonPath('domain', 'acme.test')
            ->assertJsonPath('dmarc_policy', 'quarantine')
            ->assertJsonPath('checks.dmarc.status', 'ok');

        $this->assertSame(['v=DMARC1; p=quarantine; rua=mailto:dmarc@codron.co; fo=1'], array_column($this->cloudflare->at('_dmarc.acme.test'), 'content'));
        $this->assertSame('quarantine', SiteComailDomain::query()->where('domain', 'acme.test')->value('dmarc_policy'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'comail.dmarc_policy', 'subject_id' => $site->id]);

        // Plane's own record may also be loosened again.
        $this->signed('POST', '/domains/acme.test/dmarc', $site, ['policy' => 'none'])->assertOk();
        $this->assertSame(['v=DMARC1; p=none; rua=mailto:dmarc@codron.co; fo=1'], array_column($this->cloudflare->at('_dmarc.acme.test'), 'content'));
    }

    public function test_customer_dmarc_keeps_its_tags_and_a_stricter_one_is_not_touched(): void
    {
        $this->cloudflareAccount();
        $this->cloudflare->zone('acme.test');
        $id = $this->cloudflare->record('acme.test', 'TXT', '_dmarc.acme.test', 'v=DMARC1; p=quarantine; rua=mailto:reports@acme.test; pct=50');
        $site = $this->comailSite();
        $this->healthyDomain($site);
        $this->resolver->set('TXT', '_dmarc.acme.test', [['value' => 'v=DMARC1; p=quarantine; rua=mailto:reports@acme.test; pct=50']]);

        $this->signed('POST', '/domains/acme.test/dmarc', $site, ['policy' => 'none'])
            ->assertOk()
            ->assertJsonPath('checks.dmarc.status', 'different');
        $this->assertSame('v=DMARC1; p=quarantine; rua=mailto:reports@acme.test; pct=50', $this->cloudflare->records[$id]['content']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'comail.dmarc_policy', 'subject_id' => $site->id]);

        $this->signed('POST', '/domains/acme.test/dmarc', $site, ['policy' => 'reject'])->assertOk();
        $this->assertSame(
            'v=DMARC1; p=reject; rua=mailto:reports@acme.test,mailto:dmarc@codron.co; pct=50; fo=1',
            $this->cloudflare->records[$id]['content'],
        );
    }

    public function test_dmarc_on_a_wildcard_subdomain_pins_the_wildcard_first(): void
    {
        $this->cloudflareAccount();
        $this->cloudflare->zone('acme.test');
        $this->cloudflare->record('acme.test', 'A', '*.acme.test', '203.0.113.10');
        $site = $this->comailSite('demo.acme.test');
        $this->healthyDomain($site, 'demo.acme.test');

        $this->signed('POST', '/domains/demo.acme.test/dmarc', $site, ['policy' => 'reject'])->assertOk();

        $this->assertSame(['203.0.113.10'], array_column($this->cloudflare->at('demo.acme.test', 'A'), 'content'));
        $this->assertSame(['v=DMARC1; p=reject; rua=mailto:dmarc@codron.co; fo=1'], array_column($this->cloudflare->at('_dmarc.demo.acme.test'), 'content'));
    }

    public function test_dmarc_needs_a_managed_zone_local_mode_and_a_valid_policy(): void
    {
        $site = $this->comailSite('elsewhere.test');
        $this->healthyDomain($site, 'elsewhere.test');

        $this->signed('POST', '/domains/elsewhere.test/dmarc', $site, ['policy' => 'reject'])
            ->assertStatus(422)
            ->assertJsonPath('error', 'dns_not_managed');
        $this->signed('POST', '/domains/elsewhere.test/dmarc', $site, ['policy' => 'strict'])
            ->assertStatus(422)
            ->assertJsonPath('error', 'validation_failed');

        $this->cloudflareAccount();
        $this->cloudflare->zone('relay.test');
        $relaySite = $this->comailSite('relay.test');
        $this->comailDomain($relaySite, 'relay.test');
        $this->resolver->set('MX', 'relay.test', [['target' => 'mx1.hostinger.com', 'priority' => 5]]);
        $this->signed('POST', '/domains/relay.test/dmarc', $relaySite, ['policy' => 'reject'])
            ->assertStatus(422)
            ->assertJsonPath('error', 'validation_failed');
        $this->assertSame([], $this->cloudflare->writes);
    }

    public function test_dmarc_of_another_sites_domain_is_not_found(): void
    {
        $site = $this->comailSite();
        $this->healthyDomain($site);
        $other = $this->comailSite('other.test');

        $this->signed('POST', '/domains/acme.test/dmarc', $other, ['policy' => 'reject'])
            ->assertNotFound()
            ->assertJsonPath('error', 'not_found');
        $this->assertDatabaseHas('audit_logs', ['action' => 'comail.access_denied', 'subject_id' => $other->id]);
    }

    public function test_unmanaged_local_domain_gets_the_policy_on_check(): void
    {
        $site = $this->comailSite('elsewhere.test');
        $this->comailDomain($site, 'elsewhere.test');
        $this->resolver->set('MX', 'elsewhere.test', [['target' => 'mail.example.test', 'priority' => 10]]);

        $this->signed('GET', '/domains/elsewhere.test/dns', $site)
            ->assertOk()
            ->assertJsonPath('mx_active', true);

        $this->assertSame('testing', $this->mailcow->mtaSts['elsewhere.test']['mode']);
        // The manual record list now carries the security records.
        $keys = array_column($this->signed('GET', '/domains/elsewhere.test/dns', $site)->json('records'), 'key');
        $this->assertContains('mta_sts', $keys);
        $this->assertContains('mta_sts_txt', $keys);
        $this->assertContains('tls_rpt', $keys);
    }

    public function test_removing_a_domain_drops_its_report_authorization(): void
    {
        $this->cloudflareAccount();
        $this->cloudflare->zone('codron.co');
        $this->cloudflare->record('codron.co', 'TXT', 'extra.test._report._dmarc.codron.co', 'v=DMARC1');
        $site = $this->comailSite();
        $this->comailDomain($site);
        $this->comailDomain($site, 'extra.test', ['is_primary' => false]);

        $this->signed('DELETE', '/domains/extra.test', $site)->assertOk();

        $this->assertSame([], $this->cloudflare->at('extra.test._report._dmarc.codron.co'));
    }

    public function test_new_mailcow_domain_gets_the_package_rate_limit(): void
    {
        $site = $this->comailSite();

        EnsureComailDomainJob::dispatchSync((string) $site->id, 'acme.test', true);
        $this->assertSame('300', $this->mailcow->domains['acme.test']['rl_value']);
        $this->assertSame('h', $this->mailcow->domains['acme.test']['rl_frame']);

        $package = MailPackage::query()->where('slug', 'business')->firstOrFail();
        $package->forceFill(['rate_limit_per_hour' => 1000])->save();
        $big = $this->comailSite('big.test', ['mail_package_id' => $package->id]);
        EnsureComailDomainJob::dispatchSync((string) $big->id, 'big.test', true);
        $this->assertSame('1000', $this->mailcow->domains['big.test']['rl_value']);

        // A re-sync never touches the limit (codron.co keeps its manual 1000/h).
        EnsureComailDomainJob::dispatchSync((string) $site->id, 'acme.test', true);
        $edits = array_filter($this->mailcow->calls, static fn (array $call): bool => $call['path'] === '/edit/domain');
        foreach ($edits as $edit) {
            $this->assertArrayNotHasKey('rl_value', $edit['body']['attr']);
        }
    }
}
