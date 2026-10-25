<?php

namespace Tests\Feature\Comail;

use App\Models\AuditLog;
use App\Models\MailPackage;
use App\Models\MailServer;
use App\Models\SiteComailDomain;
use App\Models\SiteDomain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Comail\Support\ComailTestHelpers;
use Tests\Feature\Comail\Support\FakeMailcow;
use Tests\TestCase;

class SiteComailApiTest extends TestCase
{
    use ComailTestHelpers;
    use RefreshDatabase;

    private const PASSWORD = 'Gizli-Parola-42x';

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpComail();
    }

    public function test_signature_is_required(): void
    {
        $site = $this->comailSite();

        $this->signed('GET', '/overview', $site, [], 'wrong-secret')
            ->assertUnauthorized()
            ->assertJsonPath('error', 'unauthorized');

        $this->getJson('/internal/site/v1/comail/overview')->assertUnauthorized();
        $this->assertSame([], $this->mailcow->calls);
    }

    public function test_without_comail_server_the_api_says_not_configured(): void
    {
        MailServer::query()->delete();
        $site = $this->comailSite();

        $this->signed('GET', '/mailboxes', $site)
            ->assertStatus(422)
            ->assertJsonPath('error', 'comail_not_configured')
            ->assertJsonStructure(['error', 'message']);
    }

    public function test_overview_reports_package_usage_client_settings_and_domains(): void
    {
        $site = $this->comailSite();
        $this->comailDomain($site);
        $this->mailcow->addMailbox('info@acme.test', 512, 2048);
        SiteDomain::query()->create(['site_id' => $site->id, 'domain' => 'acme.test', 'is_primary' => true]);
        SiteDomain::query()->create(['site_id' => $site->id, 'domain' => 'www.acme.test', 'is_www' => true]);
        SiteDomain::query()->create(['site_id' => $site->id, 'domain' => 'shop-extra.test']);
        SiteDomain::query()->create(['site_id' => $site->id, 'domain' => 'acme.codron.co', 'is_temporary' => true]);

        $response = $this->signed('GET', '/overview', $site)->assertOk();

        $response
            ->assertJsonPath('package.slug', 'free')
            ->assertJsonPath('package.mailbox_limit', 1)
            ->assertJsonPath('package.domain_limit', null)
            ->assertJsonPath('usage.mailboxes', 1)
            ->assertJsonPath('usage.aliases', 0)
            ->assertJsonPath('usage.domains', 1)
            ->assertJsonPath('usage.storage_allocated_mb', 512)
            ->assertJsonPath('usage.storage_used_bytes', 2048)
            ->assertJsonPath('webmail_url', FakeMailcow::BASE)
            ->assertJsonPath('panel_url', FakeMailcow::BASE.'/?panel=1')
            ->assertJsonPath('client.imap', ['host' => 'mail.example.test', 'port' => 993, 'security' => 'SSL'])
            ->assertJsonPath('client.smtp.port', 465)
            ->assertJsonPath('client.smtp_alt', ['host' => 'mail.example.test', 'port' => 587, 'security' => 'STARTTLS'])
            ->assertJsonPath('domains.0.domain', 'acme.test')
            ->assertJsonPath('domains.0.is_primary', true)
            ->assertJsonPath('domains.0.status', 'active')
            ->assertJsonPath('domains.0.mx_active', false)
            ->assertJsonPath('domains.0.dns_managed', false)
            ->assertJsonPath('domains.0.webmail_url', null)
            ->assertJsonPath('available_domains', ['shop-extra.test'])
            ->assertDontSee(FakeMailcow::KEY, false);
    }

    public function test_add_domain_requires_an_own_domain_and_respects_the_domain_limit(): void
    {
        $site = $this->comailSite();
        $this->comailDomain($site);
        SiteDomain::query()->create(['site_id' => $site->id, 'domain' => 'acme.test', 'is_primary' => true]);
        SiteDomain::query()->create(['site_id' => $site->id, 'domain' => 'second.test']);
        SiteDomain::query()->create(['site_id' => $site->id, 'domain' => 'third.test']);

        $this->signed('POST', '/domains', $site, ['domain' => 'someone-else.test'])
            ->assertStatus(422)
            ->assertJsonPath('error', 'domain_not_owned');

        $this->signed('POST', '/domains', $site, ['domain' => 'second.test'])
            ->assertCreated()
            ->assertJsonPath('domain', 'second.test')
            ->assertJsonPath('is_primary', false)
            ->assertJsonPath('status', 'active');
        $this->assertArrayHasKey('second.test', $this->mailcow->domains);
        $this->assertSame(10, $this->mailcow->domains['second.test']['aliases']);
        $this->assertSame(1024, $this->mailcow->domains['second.test']['quota']);
        $this->assertNotNull(SiteComailDomain::query()->where('domain', 'second.test')->value('dkim_txt'));
        $this->assertNotEmpty($this->configurePushes);

        MailPackage::default()->forceFill(['domain_limit' => 2])->save();
        $this->signed('POST', '/domains', $site, ['domain' => 'third.test'])
            ->assertStatus(422)
            ->assertJsonPath('error', 'limit_domains');
    }

    public function test_a_domain_of_another_site_cannot_be_added_or_touched(): void
    {
        $site = $this->comailSite();
        $other = $this->comailSite('other.test');
        $this->comailDomain($other, 'other.test');

        $this->signed('GET', '/domains/other.test/dns', $site)->assertNotFound()->assertJsonPath('error', 'not_found');
        $this->signed('DELETE', '/domains/other.test', $site)->assertNotFound();
        $this->signed('POST', '/domains/other.test/activate', $site)->assertNotFound();
    }

    public function test_remove_domain_refuses_primary_and_domains_with_mailboxes(): void
    {
        $site = $this->comailSite();
        $this->comailDomain($site);
        $this->comailDomain($site, 'second.test', ['is_primary' => false]);

        $this->signed('DELETE', '/domains/acme.test', $site)
            ->assertStatus(422)
            ->assertJsonPath('error', 'validation_failed');

        $this->mailcow->addMailbox('a@second.test');
        $this->signed('DELETE', '/domains/second.test', $site)
            ->assertStatus(422)
            ->assertJsonPath('error', 'domain_has_mailboxes');

        unset($this->mailcow->mailboxes['a@second.test']);
        $this->signed('DELETE', '/domains/second.test', $site)->assertOk()->assertJsonPath('ok', true);
        $this->assertArrayNotHasKey('second.test', $this->mailcow->domains);
        $this->assertDatabaseMissing('site_comail_domains', ['domain' => 'second.test']);
    }

    public function test_mailbox_lifecycle_with_limits_and_no_password_echo(): void
    {
        $site = $this->comailSite();
        $this->comailDomain($site);

        $this->signed('POST', '/mailboxes', $site, [
            'local_part' => 'Info',
            'domain' => 'acme.test',
            'name' => 'Bilgi',
            'password' => self::PASSWORD,
        ])
            ->assertCreated()
            ->assertJsonPath('address', 'info@acme.test')
            ->assertJsonPath('mailbox.address', 'info@acme.test')
            ->assertJsonPath('quota_mb', 1024)
            ->assertJsonPath('name', 'Bilgi')
            ->assertJsonPath('active', true)
            ->assertDontSee(self::PASSWORD, false);
        $this->assertSame(self::PASSWORD, $this->mailcow->passwords['info@acme.test']);

        // Free package: one mailbox.
        $this->signed('POST', '/mailboxes', $site, [
            'local_part' => 'satis',
            'domain' => 'acme.test',
            'password' => self::PASSWORD,
        ])->assertStatus(422)->assertJsonPath('error', 'limit_mailboxes');

        $this->signed('GET', '/mailboxes', $site)
            ->assertOk()
            ->assertJsonCount(1, 'mailboxes')
            ->assertJsonPath('mailboxes.0.address', 'info@acme.test')
            ->assertJsonPath('mailboxes.0.created_at', fn ($value) => is_string($value) && str_starts_with($value, '2026-10-26'))
            ->assertJsonPath('mailboxes.0.last_login_at', null);

        $this->signed('PATCH', '/mailboxes/info@acme.test', $site, ['quota_mb' => 2048])
            ->assertStatus(422)
            ->assertJsonPath('error', 'limit_storage');

        $this->signed('PATCH', '/mailboxes/info@acme.test', $site, [
            'name' => 'Yeni Ad',
            'password' => 'Baska-Parola-77y',
            'active' => false,
            'quota_mb' => 900,
        ])
            ->assertOk()
            ->assertJsonPath('name', 'Yeni Ad')
            ->assertJsonPath('quota_mb', 900)
            ->assertJsonPath('active', false)
            ->assertDontSee('Baska-Parola-77y', false);
        $this->assertSame('Baska-Parola-77y', $this->mailcow->passwords['info@acme.test']);

        $this->signed('DELETE', '/mailboxes/info@acme.test', $site)->assertOk()->assertJsonPath('ok', true);
        $this->assertArrayNotHasKey('info@acme.test', $this->mailcow->mailboxes);

        $audits = AuditLog::query()->where('subject_id', $site->id)->where('action', 'like', 'comail.mailbox%')->get();
        $this->assertCount(3, $audits);
        foreach ($audits as $audit) {
            $this->assertStringNotContainsString(self::PASSWORD, json_encode($audit->after));
            $this->assertStringNotContainsString('Baska-Parola-77y', json_encode($audit->after));
        }
    }

    public function test_mailbox_validation_and_storage_defaults(): void
    {
        $site = $this->comailSite();
        $this->comailDomain($site);
        $package = MailPackage::query()->where('slug', 'starter')->firstOrFail();
        $site->forceFill(['mail_package_id' => $package->id])->save();
        $this->mailcow->addMailbox('big@acme.test', 8192);

        $this->signed('POST', '/mailboxes', $site, ['local_part' => 'x', 'domain' => 'acme.test', 'password' => 'short'])
            ->assertStatus(422)
            ->assertJsonPath('error', 'validation_failed')
            ->assertJsonPath('message', 'Parola en az 8 karakter olmalı.');

        $this->signed('POST', '/mailboxes', $site, ['local_part' => 'x', 'domain' => 'acme.test', 'password' => 'onlyletters'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Parola en az bir harf ve bir rakam içermeli.');

        $this->signed('POST', '/mailboxes', $site, ['local_part' => 'x', 'domain' => 'foreign.test', 'password' => self::PASSWORD])
            ->assertStatus(422)
            ->assertJsonPath('error', 'domain_not_owned');

        $this->signed('POST', '/mailboxes', $site, ['local_part' => 'x', 'domain' => 'acme.test', 'password' => self::PASSWORD, 'quota_mb' => 6000])
            ->assertStatus(422)
            ->assertJsonPath('error', 'limit_storage');

        // 10240 - 8192 = 2048 left: the default quota is the remaining storage.
        $this->signed('POST', '/mailboxes', $site, ['local_part' => 'x', 'domain' => 'acme.test', 'password' => self::PASSWORD])
            ->assertCreated()
            ->assertJsonPath('quota_mb', 2048);

        $this->signed('POST', '/mailboxes', $site, ['local_part' => 'y', 'domain' => 'acme.test', 'password' => self::PASSWORD])
            ->assertStatus(422)
            ->assertJsonPath('error', 'limit_storage');
    }

    public function test_mailboxes_of_another_site_are_not_found(): void
    {
        $site = $this->comailSite();
        $this->comailDomain($site);
        $other = $this->comailSite('other.test');
        $this->comailDomain($other, 'other.test');
        $this->mailcow->addMailbox('ceo@other.test');

        $this->signed('GET', '/mailboxes', $site)->assertOk()->assertJsonCount(0, 'mailboxes');
        $this->signed('PATCH', '/mailboxes/ceo@other.test', $site, ['password' => self::PASSWORD])->assertNotFound();
        $this->signed('DELETE', '/mailboxes/ceo@other.test', $site)->assertNotFound();
        $this->signed('GET', '/mailboxes/ceo@other.test/autoreply', $site)->assertNotFound();
        $this->assertArrayHasKey('ceo@other.test', $this->mailcow->mailboxes);
        $this->assertDatabaseHas('audit_logs', ['action' => 'comail.access_denied', 'subject_id' => $site->id]);
    }

    public function test_mailcow_errors_are_parsed_and_translated(): void
    {
        $site = $this->comailSite();
        $this->comailDomain($site);
        $this->mailcow->failOn['/add/mailbox'] = ['type' => 'danger', 'msg' => ['mailbox_quota_exceeded', '1024']];

        $this->signed('POST', '/mailboxes', $site, ['local_part' => 'a', 'domain' => 'acme.test', 'password' => self::PASSWORD])
            ->assertStatus(422)
            ->assertJsonPath('error', 'provider_error')
            ->assertJsonPath('message', 'Posta kutusu kotası alan adının toplam kotasını aşıyor.');
    }

    public function test_failing_mailcow_answers_provider_error(): void
    {
        $site = $this->comailSite();
        $this->comailDomain($site);
        $this->mailcow->httpStatus = 503;

        $this->signed('GET', '/mailboxes', $site)
            ->assertStatus(422)
            ->assertJsonPath('error', 'provider_error');
    }

    public function test_autoreply_round_trip_with_escaping(): void
    {
        $site = $this->comailSite();
        $this->comailDomain($site);
        $this->mailcow->addMailbox('info@acme.test');

        $this->signed('GET', '/mailboxes/info@acme.test/autoreply', $site)
            ->assertOk()
            ->assertJsonPath('enabled', false);

        $this->signed('PUT', '/mailboxes/info@acme.test/autoreply', $site, ['enabled' => true])
            ->assertStatus(422)
            ->assertJsonPath('error', 'validation_failed');

        $this->signed('PUT', '/mailboxes/info@acme.test/autoreply', $site, [
            'enabled' => true,
            'subject' => 'Tatildeyiz "yaz"',
            'body' => "Merhaba,\n2 Kasım'a kadar yokuz. \\ teşekkürler",
            'starts_on' => '2026-10-27',
            'ends_on' => '2026-11-02',
        ])
            ->assertOk()
            ->assertJsonPath('enabled', true)
            ->assertJsonPath('subject', 'Tatildeyiz "yaz"')
            ->assertJsonPath('ends_on', '2026-11-02');

        $filter = array_values($this->mailcow->filters)[0];
        $this->assertSame('comail-autoreply', $filter['script_desc']);
        $this->assertSame('prefilter', $filter['filter_type']);
        $this->assertStringContainsString(':subject "Tatildeyiz \\"yaz\\""', $filter['script_data']);
        $this->assertStringContainsString('yokuz. \\\\ teşekkürler', $filter['script_data']);
        $this->assertStringContainsString('currentdate :value "ge" "date" "2026-10-27"', $filter['script_data']);

        $this->signed('PUT', '/mailboxes/info@acme.test/autoreply', $site, [
            'enabled' => false,
            'subject' => 'Tatildeyiz',
            'body' => 'Yokuz',
        ])->assertOk()->assertJsonPath('enabled', false)->assertJsonPath('subject', 'Tatildeyiz');
        $this->assertCount(1, $this->mailcow->filters);

        $this->signed('PUT', '/mailboxes/info@acme.test/autoreply', $site, [
            'enabled' => true,
            'subject' => 'x',
            'body' => 'y',
            'starts_on' => '2026-11-05',
            'ends_on' => '2026-11-01',
        ])->assertStatus(422)->assertJsonPath('error', 'validation_failed');
    }

    public function test_aliases_forwards_and_catch_all_with_limits_and_ownership(): void
    {
        $site = $this->comailSite();
        $this->comailDomain($site);
        $this->mailcow->addMailbox('info@acme.test');
        $other = $this->comailSite('other.test');
        $this->comailDomain($other, 'other.test');
        $this->mailcow->aliases[900] = ['id' => 900, 'address' => 'x@other.test', 'goto' => 'y@gmail.com', 'domain' => 'other.test', 'active' => 1];

        $this->signed('GET', '/aliases', $site)->assertOk()->assertJsonCount(0, 'aliases');

        $this->signed('POST', '/aliases', $site, ['address' => 'satis@acme.test', 'goto' => ['info@acme.test', 'patron@gmail.com']])
            ->assertCreated()
            ->assertJsonPath('address', 'satis@acme.test')
            ->assertJsonPath('goto', ['info@acme.test', 'patron@gmail.com'])
            ->assertJsonPath('kind', 'alias')
            ->assertJsonPath('alias.kind', 'alias');

        $this->signed('POST', '/aliases', $site, ['address' => '@acme.test', 'goto' => ['info@acme.test']])
            ->assertCreated()
            ->assertJsonPath('kind', 'catchall');

        $this->signed('POST', '/aliases', $site, ['address' => 'x@other.test', 'goto' => ['me@acme.test']])
            ->assertStatus(422)
            ->assertJsonPath('error', 'domain_not_owned');

        $this->signed('POST', '/aliases', $site, ['address' => 'bad', 'goto' => ['me@acme.test']])
            ->assertStatus(422)
            ->assertJsonPath('error', 'validation_failed');

        $list = $this->signed('GET', '/aliases', $site)->assertOk()->assertJsonCount(2, 'aliases');
        $id = (int) $list->json('aliases.0.id');

        $this->signed('PATCH', '/aliases/'.$id, $site, ['goto' => ['new@gmail.com'], 'active' => false])
            ->assertOk()
            ->assertJsonPath('goto', ['new@gmail.com'])
            ->assertJsonPath('active', false);

        $this->signed('PATCH', '/aliases/900', $site, ['goto' => ['me@acme.test']])->assertNotFound();
        $this->signed('DELETE', '/aliases/900', $site)->assertNotFound();
        $this->assertSame('y@gmail.com', $this->mailcow->aliases[900]['goto']);

        MailPackage::default()->forceFill(['alias_limit' => 2])->save();
        $this->signed('POST', '/aliases', $site, ['address' => 'destek@acme.test', 'goto' => ['info@acme.test']])
            ->assertStatus(422)
            ->assertJsonPath('error', 'limit_aliases');

        $this->signed('DELETE', '/aliases/'.$id, $site)->assertOk()->assertJsonPath('ok', true);
        $this->assertArrayNotHasKey($id, $this->mailcow->aliases);
    }

    public function test_forward_with_copy_is_reported_as_forward(): void
    {
        $site = $this->comailSite();
        $this->comailDomain($site);
        $this->mailcow->addMailbox('info@acme.test');
        $this->mailcow->aliases[901] = ['id' => 901, 'address' => 'info@acme.test', 'goto' => 'info@acme.test,me@gmail.com', 'domain' => 'acme.test', 'active' => 1];

        $this->signed('GET', '/aliases', $site)
            ->assertOk()
            ->assertJsonCount(1, 'aliases')
            ->assertJsonPath('aliases.0.kind', 'forward');
    }

    public function test_migrations_create_list_delete_and_ownership(): void
    {
        $site = $this->comailSite();
        $this->comailDomain($site);
        $this->mailcow->addMailbox('info@acme.test');
        $this->mailcow->syncjobs[950] = ['id' => 950, 'user2' => 'ceo@other.test', 'host1' => 'imap.x', 'port1' => 993, 'user1' => 'u', 'enc1' => 'SSL', 'active' => 1];

        $this->signed('POST', '/migrations', $site, [
            'mailbox' => 'info@acme.test',
            'host' => 'imap.hostinger.com',
            'port' => 993,
            'user' => 'info@acme.test',
            'password' => 'eski-sifre-1',
            'security' => 'SSL',
        ])
            ->assertCreated()
            ->assertJsonPath('mailbox', 'info@acme.test')
            ->assertJsonPath('host', 'imap.hostinger.com')
            ->assertJsonPath('security', 'SSL')
            ->assertJsonPath('active', true)
            ->assertJsonPath('success', null)
            ->assertDontSee('eski-sifre-1', false);

        $job = collect($this->mailcow->calls)->firstWhere('path', '/add/syncjob');
        $this->assertSame('eski-sifre-1', $job['body']['password1']);
        $this->assertSame('(?i)spam|(?i)junk', $job['body']['exclude']);

        $this->signed('POST', '/migrations', $site, [
            'mailbox' => 'nobody@acme.test', 'host' => 'imap.gmail.com', 'port' => 993, 'user' => 'u', 'password' => 'p', 'security' => 'SSL',
        ])->assertNotFound();
        $this->signed('POST', '/migrations', $site, [
            'mailbox' => 'ceo@other.test', 'host' => 'imap.gmail.com', 'port' => 993, 'user' => 'u', 'password' => 'p', 'security' => 'SSL',
        ])->assertStatus(422)->assertJsonPath('error', 'domain_not_owned');
        $this->signed('POST', '/migrations', $site, [
            'mailbox' => 'info@acme.test', 'host' => 'imap.gmail.com', 'port' => 993, 'user' => 'u', 'password' => 'p', 'security' => 'RAW',
        ])->assertStatus(422)->assertJsonPath('error', 'validation_failed');

        $list = $this->signed('GET', '/migrations', $site)->assertOk()->assertJsonCount(1, 'migrations');
        $id = $list->json('migrations.0.id');

        $this->signed('DELETE', '/migrations/950', $site)->assertNotFound();
        $this->signed('DELETE', '/migrations/'.$id, $site)->assertOk();
        $this->assertArrayHasKey(950, $this->mailcow->syncjobs);
        $this->assertCount(1, $this->mailcow->syncjobs);

        foreach (AuditLog::query()->where('subject_id', $site->id)->get() as $audit) {
            $this->assertStringNotContainsString('eski-sifre-1', (string) json_encode($audit->after));
        }
    }
}
