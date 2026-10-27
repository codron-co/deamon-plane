<?php

namespace Tests\Feature\Comail;

use App\Enums\OpsRole;
use App\Models\AuditLog;
use App\Models\MailPackage;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Services\Comail\ComailWebmailSso;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Comail\Support\ComailTestHelpers;
use Tests\TestCase;

/**
 * Shared CoMail group: makermach (starter, 4 mailboxes) and makermak (free,
 * 1 mailbox) manage each other's mailboxes; domains, limits and billing stay
 * with the owner.
 */
class ComailShareGroupTest extends TestCase
{
    use ComailTestHelpers;
    use RefreshDatabase;

    private const PASSWORD = 'Gizli-Parola-42x';

    private Site $makermach;

    private Site $makermak;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpComail();

        $starter = MailPackage::query()->where('slug', 'starter')->firstOrFail();
        $this->makermach = $this->comailSite('makermach.com.tr', ['slug' => 'makermach', 'name' => 'Makermach', 'mail_package_id' => $starter->id]);
        $this->comailDomain($this->makermach, 'makermach.com.tr');
        foreach (['info', 'satis', 'muhasebe', 'destek'] as $local) {
            $this->mailcow->addMailbox($local.'@makermach.com.tr', 1024);
        }

        $this->makermak = $this->comailSite('makermak.com.tr', ['slug' => 'makermak', 'name' => 'Makermak']);
        $this->comailDomain($this->makermak, 'makermak.com.tr');
        $this->mailcow->addMailbox('info@makermak.com.tr', 1024);

        $this->artisan('ops:comail-share', ['sites' => ['makermach', 'makermak']])
            ->expectsOutputToContain('GROUP=')
            ->assertSuccessful();
        $this->makermach->refresh();
        $this->makermak->refresh();
    }

    public function test_command_puts_both_sites_in_one_group_and_lists_it(): void
    {
        $this->assertNotNull($this->makermach->comail_share_group);
        $this->assertSame($this->makermach->comail_share_group, $this->makermak->comail_share_group);
        $this->assertSame(2, AuditLog::query()->where('action', 'comail.share_group_changed')->count());

        $this->artisan('ops:comail-share')
            ->expectsOutputToContain('GROUP='.$this->makermach->comail_share_group)
            ->expectsOutputToContain('makermak: makermak.com.tr')
            ->assertSuccessful();
    }

    public function test_both_sites_list_all_five_mailboxes(): void
    {
        foreach ([$this->makermach, $this->makermak] as $site) {
            $addresses = collect($this->signed('GET', '/mailboxes', $site)->assertOk()->json('mailboxes'))->pluck('address')->sort()->values()->all();
            $this->assertSame([
                'destek@makermach.com.tr',
                'info@makermach.com.tr',
                'info@makermak.com.tr',
                'muhasebe@makermach.com.tr',
                'satis@makermach.com.tr',
            ], $addresses);
        }
    }

    public function test_overview_marks_shared_domains_and_keeps_own_usage(): void
    {
        SiteDomain::query()->create(['site_id' => $this->makermak->id, 'domain' => 'makermak.com.tr', 'is_primary' => true]);

        $this->signed('GET', '/overview', $this->makermak)
            ->assertOk()
            ->assertJsonPath('package.slug', 'free')
            ->assertJsonPath('usage.mailboxes', 1)
            ->assertJsonPath('usage.domains', 1)
            ->assertJsonPath('usage.storage_allocated_mb', 1024)
            ->assertJsonPath('over_limit', false)
            ->assertJsonCount(2, 'domains')
            ->assertJsonPath('domains.0.domain', 'makermak.com.tr')
            ->assertJsonPath('domains.0.shared', false)
            ->assertJsonPath('domains.1.domain', 'makermach.com.tr')
            ->assertJsonPath('domains.1.shared', true)
            ->assertJsonPath('domains.1.owner_site', 'Makermach')
            ->assertJsonPath('domains.1.is_primary', false)
            ->assertJsonPath('available_domains', []);

        $this->signed('GET', '/overview', $this->makermach)
            ->assertOk()
            ->assertJsonPath('package.slug', 'starter')
            ->assertJsonPath('usage.mailboxes', 4)
            ->assertJsonPath('domains.1.domain', 'makermak.com.tr')
            ->assertJsonPath('domains.1.owner_site', 'Makermak');
    }

    public function test_mailbox_on_a_shared_domain_follows_the_owner_package(): void
    {
        // makermak's own (free) package is full, makermach's (starter) has room for one more.
        $this->signed('POST', '/mailboxes', $this->makermak, ['local_part' => 'yeni', 'domain' => 'makermak.com.tr', 'password' => self::PASSWORD])
            ->assertStatus(422)
            ->assertJsonPath('error', 'limit_mailboxes');

        $this->signed('POST', '/mailboxes', $this->makermak, ['local_part' => 'ik', 'domain' => 'makermach.com.tr', 'password' => self::PASSWORD])
            ->assertCreated()
            ->assertJsonPath('address', 'ik@makermach.com.tr')
            ->assertJsonPath('quota_mb', 5120);
        $this->assertArrayHasKey('ik@makermach.com.tr', $this->mailcow->mailboxes);

        // starter is now full (5/5); makermach's own room does not help on makermak.com.tr either.
        $this->signed('POST', '/mailboxes', $this->makermak, ['local_part' => 'iki', 'domain' => 'makermach.com.tr', 'password' => self::PASSWORD])
            ->assertStatus(422)
            ->assertJsonPath('error', 'limit_mailboxes')
            ->assertJsonPath('message', 'Paketiniz en fazla 5 posta kutusuna izin veriyor.');
        $this->signed('POST', '/mailboxes', $this->makermach, ['local_part' => 'yeni', 'domain' => 'makermak.com.tr', 'password' => self::PASSWORD])
            ->assertStatus(422)
            ->assertJsonPath('error', 'limit_mailboxes')
            ->assertJsonPath('message', 'Paketiniz en fazla 1 posta kutusuna izin veriyor.');

        // Quota on a shared box: the owner's per-mailbox maximum (starter 5120 MB).
        $this->signed('PATCH', '/mailboxes/info@makermach.com.tr', $this->makermak, ['quota_mb' => 6000])
            ->assertStatus(422)
            ->assertJsonPath('error', 'limit_storage');
        $this->signed('PATCH', '/mailboxes/info@makermak.com.tr', $this->makermach, ['quota_mb' => 2048])
            ->assertStatus(422)
            ->assertJsonPath('error', 'limit_storage');

        $this->signed('DELETE', '/mailboxes/ik@makermach.com.tr', $this->makermak)->assertOk();
        $this->assertArrayNotHasKey('ik@makermach.com.tr', $this->mailcow->mailboxes);

        // Audited on the acting site, with the owner noted.
        $created = AuditLog::query()->where('action', 'comail.mailbox_created')->sole();
        $this->assertSame((string) $this->makermak->id, (string) $created->subject_id);
        $this->assertSame((string) $this->makermach->id, $created->after['owner_site_id']);
        $deleted = AuditLog::query()->where('action', 'comail.mailbox_deleted')->sole();
        $this->assertSame((string) $this->makermach->id, $deleted->after['owner_site_id']);
        $this->assertStringNotContainsString(self::PASSWORD, json_encode($created->after));
    }

    public function test_alias_on_a_shared_domain_counts_on_the_owner(): void
    {
        MailPackage::default()->forceFill(['alias_limit' => 0])->save();

        $this->signed('POST', '/aliases', $this->makermak, ['address' => 'teklif@makermak.com.tr', 'goto' => ['info@makermak.com.tr']])
            ->assertStatus(422)
            ->assertJsonPath('error', 'limit_aliases');

        $this->signed('POST', '/aliases', $this->makermak, ['address' => 'teklif@makermach.com.tr', 'goto' => ['info@makermach.com.tr']])
            ->assertCreated()
            ->assertJsonPath('address', 'teklif@makermach.com.tr');

        $this->signed('GET', '/aliases', $this->makermach)
            ->assertOk()
            ->assertJsonPath('aliases.0.address', 'teklif@makermach.com.tr');
        $audit = AuditLog::query()->where('action', 'comail.alias_created')->sole();
        $this->assertSame((string) $this->makermach->id, $audit->after['owner_site_id']);
    }

    public function test_webmail_login_works_for_a_shared_mailbox(): void
    {
        $response = $this->signed('POST', '/mailboxes/satis@makermach.com.tr/webmail-login', $this->makermak)
            ->assertOk()
            ->assertJsonPath('expires_in', 120);
        $this->assertStringContainsString('_comail_sso=', (string) $response->json('url'));

        $created = collect($this->mailcow->appPasswords)->firstWhere('name', ComailWebmailSso::APP_NAME);
        $this->assertSame('satis@makermach.com.tr', $created['mailbox']);

        $audit = AuditLog::query()->where('action', 'comail.webmail_login')->sole();
        $this->assertSame((string) $this->makermak->id, (string) $audit->subject_id);
        $this->assertSame('satis@makermach.com.tr', $audit->after['address']);
        $this->assertSame((string) $this->makermach->id, $audit->after['owner_site_id']);
    }

    public function test_spam_and_quarantine_cover_shared_mailboxes(): void
    {
        $this->mailcow->quarantineItem(7, 'muhasebe@makermach.com.tr');

        $this->signed('GET', '/mailboxes/muhasebe@makermach.com.tr/spam', $this->makermak)->assertOk();
        $this->signed('GET', '/quarantine', $this->makermak)
            ->assertOk()
            ->assertJsonPath('items.0.mailbox', 'muhasebe@makermach.com.tr');
        $this->signed('POST', '/quarantine/7/release', $this->makermak)->assertOk();
    }

    public function test_domain_management_of_a_shared_domain_is_refused(): void
    {
        SiteDomain::query()->create(['site_id' => $this->makermak->id, 'domain' => 'makermach.com.tr']);
        $before = count($this->mailcow->calls);

        $this->signed('POST', '/domains', $this->makermak, ['domain' => 'makermach.com.tr'])
            ->assertStatus(422)->assertJsonPath('error', 'domain_not_owned');
        $this->signed('DELETE', '/domains/makermach.com.tr', $this->makermak)
            ->assertStatus(422)->assertJsonPath('error', 'domain_not_owned');
        $this->signed('GET', '/domains/makermach.com.tr/dns', $this->makermak)
            ->assertStatus(422)->assertJsonPath('error', 'domain_not_owned');
        $this->signed('POST', '/domains/makermach.com.tr/dns', $this->makermak)
            ->assertStatus(422)->assertJsonPath('error', 'domain_not_owned');
        $this->signed('POST', '/domains/makermach.com.tr/activate', $this->makermak)
            ->assertStatus(422)->assertJsonPath('error', 'domain_not_owned');
        $this->signed('POST', '/domains/makermach.com.tr/dmarc', $this->makermak, ['policy' => 'reject'])
            ->assertStatus(422)->assertJsonPath('error', 'domain_not_owned');

        $this->assertSame($before, count($this->mailcow->calls));
        $this->assertSame('makermach.com.tr', $this->makermach->comailDomains()->value('domain'));
        $this->assertNull($this->makermach->comailDomains()->value('mx_active_at'));

        // The security overview stays about the site's own domains.
        $this->signed('GET', '/security', $this->makermak)
            ->assertOk()
            ->assertJsonCount(1, 'domains');
    }

    public function test_sites_outside_the_group_stay_isolated(): void
    {
        $outsider = $this->comailSite('baska.test', ['slug' => 'baska']);
        $this->comailDomain($outsider, 'baska.test');
        $this->mailcow->addMailbox('ceo@baska.test');

        $this->signed('GET', '/mailboxes', $outsider)
            ->assertOk()
            ->assertJsonCount(1, 'mailboxes')
            ->assertJsonPath('mailboxes.0.address', 'ceo@baska.test');
        $this->signed('POST', '/mailboxes/info@makermach.com.tr/webmail-login', $outsider)->assertNotFound();
        $this->signed('POST', '/mailboxes', $outsider, ['local_part' => 'x', 'domain' => 'makermak.com.tr', 'password' => self::PASSWORD])
            ->assertStatus(422)->assertJsonPath('error', 'domain_not_owned');
        $this->signed('GET', '/domains/makermach.com.tr/dns', $outsider)->assertNotFound();

        $this->signed('POST', '/mailboxes/ceo@baska.test/webmail-login', $this->makermak)->assertNotFound();
        $this->signed('DELETE', '/mailboxes/ceo@baska.test', $this->makermach)->assertNotFound();
        $this->assertArrayHasKey('ceo@baska.test', $this->mailcow->mailboxes);
    }

    public function test_clearing_the_group_isolates_the_sites_again(): void
    {
        $this->artisan('ops:comail-share', ['sites' => ['makermak'], '--clear' => true])
            ->expectsOutputToContain('makermak: left group')
            ->assertSuccessful();

        // A group of one shares nothing: makermach is cleared too.
        $this->assertNull($this->makermak->fresh()->comail_share_group);
        $this->assertNull($this->makermach->fresh()->comail_share_group);

        $this->signed('GET', '/mailboxes', $this->makermak)->assertOk()->assertJsonCount(1, 'mailboxes');
        $this->signed('POST', '/mailboxes/info@makermach.com.tr/webmail-login', $this->makermak)->assertNotFound();
        $this->signed('GET', '/overview', $this->makermak)->assertOk()->assertJsonCount(1, 'domains');
        $this->signed('GET', '/domains/makermach.com.tr/dns', $this->makermak)->assertNotFound();

        $this->artisan('ops:comail-share')->expectsOutputToContain('No shared CoMail groups.')->assertSuccessful();
    }

    public function test_command_refuses_sites_in_two_different_groups(): void
    {
        $other = $this->comailSite('ucuncu.test', ['slug' => 'ucuncu']);
        $fourth = $this->comailSite('dorduncu.test', ['slug' => 'dorduncu']);
        $this->artisan('ops:comail-share', ['sites' => ['ucuncu', 'dorduncu']])->assertSuccessful();

        $this->artisan('ops:comail-share', ['sites' => ['makermak', 'ucuncu']])
            ->expectsOutputToContain('farklı ortak posta gruplarında')
            ->assertFailed();
        $this->artisan('ops:comail-share', ['sites' => ['makermak']])->assertFailed();
        $this->artisan('ops:comail-share', ['sites' => ['makermak', 'yok']])->expectsOutputToContain('Site not found: yok')->assertFailed();

        $this->assertNotSame($this->makermak->fresh()->comail_share_group, $other->fresh()->comail_share_group);
        $this->assertSame($other->fresh()->comail_share_group, $fourth->fresh()->comail_share_group);
    }

    public function test_ops_card_sets_and_clears_the_group_for_super_admin_only(): void
    {
        $this->artisan('ops:comail-share', ['sites' => ['makermak'], '--clear' => true])->assertSuccessful();

        $this->actingAs($this->opsUser(OpsRole::Operator))
            ->post(route('ops.sites.comail.share-group', $this->makermak), ['share_with' => 'makermach'])
            ->assertForbidden();
        $this->assertNull($this->makermak->fresh()->comail_share_group);

        $admin = $this->opsUser();
        $this->actingAs($admin)
            ->post(route('ops.sites.comail.share-group', $this->makermak), ['share_with' => 'makermach.com.tr'])
            ->assertRedirect()
            ->assertSessionHas('status');
        $this->assertNotNull($this->makermak->fresh()->comail_share_group);
        $this->assertSame($this->makermak->fresh()->comail_share_group, $this->makermach->fresh()->comail_share_group);
        $this->assertSame($admin->id, AuditLog::query()->where('action', 'comail.share_group_changed')->latest('id')->value('actor_user_id'));

        $this->actingAs($admin)
            ->get(route('ops.sites.show', $this->makermak))
            ->assertOk()
            ->assertSee('data-comail-share-group', false)
            ->assertSee('Makermach');

        $this->actingAs($admin)
            ->post(route('ops.sites.comail.share-group', $this->makermak), ['share_with' => 'makermak'])
            ->assertSessionHas('error');

        $this->actingAs($admin)
            ->post(route('ops.sites.comail.share-group', $this->makermak), ['leave' => 1])
            ->assertSessionHas('status');
        $this->assertNull($this->makermak->fresh()->comail_share_group);
        $this->assertNull($this->makermach->fresh()->comail_share_group);
    }
}
