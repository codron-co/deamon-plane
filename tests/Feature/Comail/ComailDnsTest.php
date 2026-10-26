<?php

namespace Tests\Feature\Comail;

use App\Models\MailServer;
use App\Services\Cloudflare\CloudflareZoneService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Comail\Support\ComailTestHelpers;
use Tests\TestCase;

class ComailDnsTest extends TestCase
{
    use ComailTestHelpers;
    use RefreshDatabase;

    private const DKIM = 'v=DKIM1;k=rsa;t=s;s=email;p=MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAtestkey';

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpComail();
    }

    public function test_fresh_cloudflare_zone_gets_the_whole_record_set(): void
    {
        $this->cloudflareAccount();
        $this->cloudflare->zone('acme.test');
        $site = $this->comailSite();
        $this->comailDomain($site);

        $this->signed('POST', '/domains/acme.test/dns', $site)
            ->assertOk()
            ->assertJsonPath('domain', 'acme.test')
            ->assertJsonPath('dns_managed', true)
            ->assertJsonCount(8, 'records');

        $mx = $this->cloudflare->at('acme.test', 'MX');
        $this->assertCount(1, $mx);
        $this->assertSame('mail.example.test', $mx[0]['content']);
        $this->assertSame(10, $mx[0]['priority']);
        $this->assertSame('v=spf1 mx a:mail.example.test ~all', $this->cloudflare->at('acme.test', 'TXT')[0]['content']);
        $this->assertSame(self::DKIM, $this->cloudflare->at('dkim._domainkey.acme.test', 'TXT')[0]['content']);
        // A fresh domain is local mode: DMARC with reports plus the v3 security records.
        $this->assertSame('v=DMARC1; p=none; rua=mailto:dmarc@codron.co; fo=1', $this->cloudflare->at('_dmarc.acme.test', 'TXT')[0]['content']);
        $this->assertSame('31.97.79.49', $this->cloudflare->at('mta-sts.acme.test', 'A')[0]['content']);
        $this->assertStringStartsWith('v=STSv1; id=', $this->cloudflare->at('_mta-sts.acme.test', 'TXT')[0]['content']);
        $this->assertSame('v=TLSRPTv1; rua=mailto:tlsrpt@codron.co', $this->cloudflare->at('_smtp._tls.acme.test', 'TXT')[0]['content']);
        foreach (['mail', 'autodiscover', 'autoconfig'] as $name) {
            $cname = $this->cloudflare->at($name.'.acme.test', 'CNAME');
            $this->assertSame('mail.example.test', $cname[0]['content']);
            $this->assertFalse($cname[0]['proxied']);
        }
        $srv = collect($this->cloudflare->writesOf('POST'))->firstWhere('body.type', 'SRV');
        $this->assertSame(['priority' => 0, 'weight' => 1, 'port' => 443, 'target' => 'mail.example.test'], $srv['body']['data']);

        // Idempotent: a second apply writes nothing new.
        $writes = count($this->cloudflare->writes);
        $this->signed('POST', '/domains/acme.test/dns', $site)->assertOk();
        $this->assertCount($writes, $this->cloudflare->writes);
    }

    public function test_zone_with_existing_mx_keeps_the_customers_mail_until_activate(): void
    {
        $this->cloudflareAccount();
        $this->cloudflare->zone('acme.test');
        $this->cloudflare->record('acme.test', 'MX', 'acme.test', 'aspmx.l.google.com', 1);
        $this->cloudflare->record('acme.test', 'TXT', 'acme.test', 'v=spf1 include:_spf.google.com ~all');
        $this->cloudflare->record('acme.test', 'TXT', 'acme.test', 'google-site-verification=abc');
        $this->cloudflare->record('acme.test', 'TXT', '_dmarc.acme.test', 'v=DMARC1; p=reject');
        $this->cloudflare->record('acme.test', 'CNAME', 'autodiscover.acme.test', 'autodiscover.outlook.com');
        $site = $this->comailSite();
        $row = $this->comailDomain($site);

        $this->signed('POST', '/domains/acme.test/dns', $site)->assertOk()->assertJsonPath('mx_active', false);

        $this->assertSame(['aspmx.l.google.com'], array_column($this->cloudflare->at('acme.test', 'MX'), 'content'));
        $this->assertContains('v=spf1 include:_spf.google.com ~all', array_column($this->cloudflare->at('acme.test', 'TXT'), 'content'));
        $this->assertSame(['v=DMARC1; p=reject'], array_column($this->cloudflare->at('_dmarc.acme.test'), 'content'));
        $this->assertSame(['autodiscover.outlook.com'], array_column($this->cloudflare->at('autodiscover.acme.test'), 'content'));
        $this->assertCount(1, $this->cloudflare->at('dkim._domainkey.acme.test', 'TXT'));
        $this->assertCount(1, $this->cloudflare->at('mail.acme.test', 'CNAME'));
        $this->assertNull($row->fresh()->mx_active_at);

        $this->signed('POST', '/domains/acme.test/activate', $site)
            ->assertOk()
            ->assertJsonPath('mx_active', true);

        $this->assertSame(['mail.example.test'], array_column($this->cloudflare->at('acme.test', 'MX'), 'content'));
        $txt = array_column($this->cloudflare->at('acme.test', 'TXT'), 'content');
        $this->assertContains('v=spf1 mx a:mail.example.test ~all', $txt);
        $this->assertContains('google-site-verification=abc', $txt);
        $this->assertCount(1, array_filter($txt, static fn (string $value): bool => str_starts_with($value, 'v=spf1')));
        $this->assertSame(['mail.example.test'], array_column($this->cloudflare->at('autodiscover.acme.test'), 'content'));
        $this->assertSame(['mail.example.test'], array_column($this->cloudflare->at('autoconfig.acme.test'), 'content'));
        $this->assertSame(['v=DMARC1; p=reject'], array_column($this->cloudflare->at('_dmarc.acme.test'), 'content'));
        $this->assertNotNull($row->fresh()->mx_active_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'comail.mx_activated', 'subject_id' => $site->id]);
    }

    public function test_fresh_domain_with_an_spf_keeps_its_senders(): void
    {
        $this->cloudflareAccount();
        $this->cloudflare->zone('acme.test');
        $this->cloudflare->record('acme.test', 'TXT', 'acme.test', 'v=spf1 include:sendgrid.net ~all');
        $site = $this->comailSite();
        $this->comailDomain($site);

        $this->signed('POST', '/domains/acme.test/dns', $site)->assertOk();

        $this->assertSame(['v=spf1 mx a:mail.example.test include:sendgrid.net ~all'], array_column($this->cloudflare->at('acme.test', 'TXT'), 'content'));
        $this->assertCount(1, $this->cloudflare->at('acme.test', 'MX'));
    }

    public function test_non_cloudflare_zone_is_never_written_and_returns_the_manual_plan(): void
    {
        $this->cloudflareAccount();
        $site = $this->comailSite('elsewhere.test');
        $this->comailDomain($site, 'elsewhere.test');

        $this->signed('GET', '/domains/elsewhere.test/dns', $site)
            ->assertOk()
            ->assertJsonPath('dns_managed', false)
            ->assertJsonPath('records.0', [
                'key' => 'mx',
                'type' => 'MX',
                'name' => 'elsewhere.test',
                'value' => 'mail.example.test',
                'priority' => 10,
                'status' => 'missing',
            ])
            ->assertJsonPath('records.2.name', 'dkim._domainkey.elsewhere.test')
            ->assertJsonPath('records.7.value', '0 1 443 mail.example.test');

        $this->signed('POST', '/domains/elsewhere.test/dns', $site)->assertStatus(422)->assertJsonPath('error', 'validation_failed');
        $this->signed('POST', '/domains/elsewhere.test/activate', $site)->assertStatus(422)->assertJsonPath('error', 'validation_failed');
        $this->assertSame([], $this->cloudflare->writes);
    }

    public function test_dns_check_reports_ok_missing_and_different_and_sets_mx_active(): void
    {
        $site = $this->comailSite();
        $row = $this->comailDomain($site);
        $this->resolver
            ->set('MX', 'acme.test', [['target' => 'mail.example.test', 'priority' => 10]])
            ->set('TXT', 'acme.test', [['value' => 'v=spf1 mx ~all']])
            ->set('TXT', 'dkim._domainkey.acme.test', [['value' => 'v=DKIM1; k=rsa; t=s; s=email; p=MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAtestkey']])
            ->set('CNAME', 'autodiscover.acme.test', [['target' => 'autodiscover.outlook.com']]);

        $response = $this->signed('GET', '/domains/acme.test/dns', $site)->assertOk()->assertJsonPath('mx_active', true);
        $status = collect($response->json('records'))->pluck('status', 'key')->all();

        $this->assertSame('ok', $status['mx']);
        $this->assertSame('ok', $status['spf']);
        $this->assertSame('ok', $status['dkim']);
        $this->assertSame('missing', $status['dmarc']);
        $this->assertSame('missing', $status['cname_mail']);
        $this->assertSame('different', $status['cname_autodiscover']);
        $this->assertSame('autodiscover.outlook.com', collect($response->json('records'))->firstWhere('key', 'cname_autodiscover')['current']);
        $this->assertNotNull($row->fresh()->mx_active_at);
        $this->assertNotNull($row->fresh()->dns_checked_at);
        $this->signed('GET', '/overview', $site)->assertOk()->assertJsonPath('domains.0.webmail_url', null);

        $this->resolver->set('CNAME', 'mail.acme.test', [['target' => 'mail.example.test']]);
        $this->signed('GET', '/domains/acme.test/dns', $site)->assertOk();
        $this->signed('GET', '/overview', $site)->assertOk()->assertJsonPath('domains.0.webmail_url', 'https://mail.acme.test');

        $this->resolver->set('MX', 'acme.test', [['target' => 'mx1.hostinger.com', 'priority' => 5]]);
        $this->signed('GET', '/domains/acme.test/dns', $site)->assertOk()->assertJsonPath('mx_active', false);
        $this->assertNull($row->fresh()->mx_active_at);
    }

    public function test_domain_webmail_follows_mx_active_until_a_check_sees_no_mail_cname(): void
    {
        $site = $this->comailSite();
        $row = $this->comailDomain($site, attributes: ['mx_active_at' => now()]);

        $this->signed('GET', '/overview', $site)
            ->assertOk()
            ->assertJsonPath('webmail_url', 'https://mail.example.test')
            ->assertJsonPath('panel_url', 'https://mail.example.test/?panel=1')
            ->assertJsonPath('domains.0.mx_active', true)
            ->assertJsonPath('domains.0.webmail_url', 'https://mail.acme.test');

        $row->forceFill(['dns_check' => ['records' => [['key' => 'cname_mail', 'status' => 'missing']]]])->save();
        $this->signed('GET', '/overview', $site)->assertOk()->assertJsonPath('domains.0.webmail_url', null);

        $row->forceFill(['mx_active_at' => null, 'dns_check' => null])->save();
        $this->signed('GET', '/overview', $site)->assertOk()->assertJsonPath('domains.0.webmail_url', null);
    }

    public function test_cloudflare_template_skips_hostinger_mail_rows_when_comail_exists(): void
    {
        $account = $this->cloudflareAccount();
        $zoneId = $this->cloudflare->zone('fresh.test');

        app(CloudflareZoneService::class)->applyDefaults($account, $zoneId);

        $types = array_column($this->cloudflare->writesOf('POST'), 'body');
        $this->assertNotEmpty($types);
        $this->assertSame([], array_filter($types, static fn (array $body): bool => $body['type'] === 'MX' || str_contains((string) ($body['content'] ?? ''), 'hostinger')));
    }

    public function test_cloudflare_template_keeps_hostinger_mail_rows_without_comail(): void
    {
        MailServer::query()->delete();
        $account = $this->cloudflareAccount();
        $zoneId = $this->cloudflare->zone('fresh.test');

        app(CloudflareZoneService::class)->applyDefaults($account, $zoneId);

        $mx = array_filter(array_column($this->cloudflare->writesOf('POST'), 'body'), static fn (array $body): bool => $body['type'] === 'MX');
        $this->assertSame(['mx1.hostinger.com', 'mx2.hostinger.com'], array_values(array_column($mx, 'content')));
    }
}
