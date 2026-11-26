<?php

namespace Tests\Feature\Comail;

use App\Models\AuditLog;
use App\Models\Site;
use App\Models\SiteComailDomain;
use App\Services\Agent\ControlPlaneAgentContract;
use App\Services\Comail\ComailDomainProvisioner;
use App\Services\Comail\ComailShareGroup;
use App\Services\Comail\ComailSmtpCredentials;
use App\Services\Comail\ComailWebmailSso;
use App\Support\ControlPlaneAgentSignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\Feature\Comail\Support\ComailTestHelpers;
use Tests\TestCase;

/**
 * Site SMTP from a CoMail mailbox: `POST /smtp-credentials` adds an SMTP-only
 * app password (no mailbox password involved) and returns it once;
 * `POST /smtp-credentials/revoke` removes the site's others (`keep` stays).
 */
class ComailSmtpCredentialsTest extends TestCase
{
    use ComailTestHelpers;
    use RefreshDatabase;

    private Site $site;

    /** @var list<array{level: string, message: string, context: array<mixed>}> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpComail();
        $this->site = $this->comailSite();
        $this->comailDomain($this->site);
        $this->mailcow->addMailbox('b2b@acme.test');

        Log::listen(function ($event): void {
            $this->logged[] = ['level' => (string) $event->level, 'message' => (string) $event->message, 'context' => $event->context];
        });
    }

    public function test_signature_is_required(): void
    {
        $this->signed('POST', '/smtp-credentials', $this->site, ['address' => 'b2b@acme.test'], 'wrong-secret')
            ->assertUnauthorized();
        $this->postJson('/internal/site/v1/comail/smtp-credentials', ['address' => 'b2b@acme.test'])->assertUnauthorized();
        $this->signed('POST', '/smtp-credentials/revoke', $this->site, ['address' => 'b2b@acme.test'], 'wrong-secret')->assertUnauthorized();
        $this->signed('GET', '/smtp-senders', $this->site, [], 'wrong-secret')->assertUnauthorized();

        $this->assertSame([], $this->mailcow->calls);
        $this->assertSame([], $this->mailcow->appPasswords);
    }

    public function test_creates_an_smtp_only_app_password_and_returns_it_once(): void
    {
        $response = $this->signed('POST', '/smtp-credentials', $this->site, ['address' => 'B2B@Acme.test'])
            ->assertCreated()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('ok', true)
            ->assertJsonPath('host', 'mail.example.test')
            ->assertJsonPath('port', 465)
            ->assertJsonPath('encryption', 'ssl')
            ->assertJsonPath('username', 'b2b@acme.test')
            ->assertJsonPath('from', 'b2b@acme.test')
            ->assertJsonPath('send_as', ['b2b@acme.test'])
            ->assertJsonMissingPath('smtp');

        $password = (string) $response->json('password');
        $this->assertMatchesRegularExpression('/\A[A-Za-z0-9]{32}\z/', $password);
        $this->assertSame(1, substr_count((string) $response->getContent(), $password), 'password appears once');

        $appName = (string) $response->json('app_name');
        $this->assertMatchesRegularExpression('/\Aacme\.test site SMTP \(Deamon\) '.strtoupper($this->site->id).'\.[a-f0-9]{6}\z/', $appName);

        $this->assertCount(1, $this->mailcow->appPasswords);
        $created = array_values($this->mailcow->appPasswords)[0];
        $this->assertSame($appName, $created['name']);
        $this->assertSame('b2b@acme.test', $created['mailbox']);
        $this->assertSame(['smtp_access'], $created['protocols']);
        $this->assertSame($password, $created['app_passwd']);

        $audit = AuditLog::query()->where('action', 'comail.smtp_password_created')->sole();
        $this->assertSame('b2b@acme.test', $audit->after['address']);
        $this->assertSame($appName, $audit->after['app_name']);
        $this->assertSame('cms', $audit->after['source']);

        $this->assertSecretNowhere($password);
    }

    public function test_rotation_adds_first_and_revokes_only_what_the_cms_does_not_keep(): void
    {
        // The one-click webmail password and another site's SMTP password on the same mailbox stay.
        $this->mailcow->appPasswords[900] = ['id' => 900, 'name' => ComailWebmailSso::APP_NAME, 'mailbox' => 'b2b@acme.test', 'app_passwd' => 'webmail-one'];
        $this->mailcow->appPasswords[901] = ['id' => 901, 'name' => 'other.test site SMTP (Deamon) 01HX8K3NDEKTSV4RRFFQ69G5FA.abc123', 'mailbox' => 'b2b@acme.test', 'app_passwd' => 'other-site'];
        // Older name of this site (before unique names) is the site's too.
        $this->mailcow->appPasswords[902] = ['id' => 902, 'name' => 'acme.test site SMTP (Deamon)', 'mailbox' => 'b2b@acme.test', 'app_passwd' => 'legacy'];

        $first = $this->signed('POST', '/smtp-credentials', $this->site, ['address' => 'b2b@acme.test'])->assertCreated();
        $second = $this->signed('POST', '/smtp-credentials', $this->site, ['address' => 'b2b@acme.test'])->assertCreated();

        // Both live until the CMS confirms: a lost answer never kills the stored password.
        $this->assertNotSame($first->json('app_name'), $second->json('app_name'));
        $this->assertCount(5, $this->mailcow->appPasswords);

        $this->signed('POST', '/smtp-credentials/revoke', $this->site, ['address' => 'b2b@acme.test', 'keep' => $second->json('app_name')])
            ->assertOk()
            ->assertJsonPath('revoked', 2);

        $names = array_column($this->mailcow->appPasswords, 'name');
        $this->assertContains($second->json('app_name'), $names);
        $this->assertNotContains($first->json('app_name'), $names);
        $this->assertNotContains('acme.test site SMTP (Deamon)', $names);
        $this->assertArrayHasKey(900, $this->mailcow->appPasswords);
        $this->assertArrayHasKey(901, $this->mailcow->appPasswords);

        $audit = AuditLog::query()->where('action', 'comail.smtp_password_revoked')->sole();
        $this->assertSame(['address' => 'b2b@acme.test', 'revoked' => 2, 'kept' => $second->json('app_name')], array_intersect_key($audit->after, array_flip(['address', 'revoked', 'kept'])));
    }

    public function test_keep_must_be_the_sites_own_password(): void
    {
        $this->signed('POST', '/smtp-credentials', $this->site, ['address' => 'b2b@acme.test'])->assertCreated();

        $this->signed('POST', '/smtp-credentials/revoke', $this->site, ['address' => 'b2b@acme.test', 'keep' => 'other.test site SMTP (Deamon) 01HX8K3NDEKTSV4RRFFQ69G5FA.abc123'])
            ->assertStatus(422)
            ->assertJsonPath('error', 'validation_failed');

        $this->assertCount(1, $this->mailcow->appPasswords);
    }

    public function test_revoke_without_keep_removes_all_of_the_sites_passwords_and_is_idempotent(): void
    {
        $this->signed('POST', '/smtp-credentials', $this->site, ['address' => 'b2b@acme.test'])->assertCreated();
        $this->mailcow->appPasswords[900] = ['id' => 900, 'name' => ComailWebmailSso::APP_NAME, 'mailbox' => 'b2b@acme.test', 'app_passwd' => 'webmail-one'];

        $this->signed('POST', '/smtp-credentials/revoke', $this->site, ['address' => 'b2b@acme.test'])->assertOk()->assertJsonPath('revoked', 1);
        $this->assertSame([900], array_keys($this->mailcow->appPasswords));

        $this->signed('POST', '/smtp-credentials/revoke', $this->site, ['address' => 'b2b@acme.test'])->assertOk()->assertJsonPath('revoked', 0);
        $this->assertSame(1, AuditLog::query()->where('action', 'comail.smtp_password_revoked')->count());
    }

    public function test_revoke_of_a_deleted_mailbox_is_a_no_op(): void
    {
        unset($this->mailcow->mailboxes['b2b@acme.test']);

        $this->signed('POST', '/smtp-credentials/revoke', $this->site, ['address' => 'b2b@acme.test'])->assertOk()->assertJsonPath('revoked', 0);
        $this->assertSame([], $this->mailcow->paths('POST'));
    }

    public function test_another_sites_mailbox_is_refused(): void
    {
        $other = $this->comailSite('other.test');
        $this->comailDomain($other, 'other.test');
        $this->mailcow->addMailbox('info@other.test');

        $this->signed('POST', '/smtp-credentials', $this->site, ['address' => 'info@other.test'])
            ->assertNotFound()
            ->assertJsonPath('error', 'not_found');
        $this->signed('POST', '/smtp-credentials/revoke', $this->site, ['address' => 'info@other.test'])
            ->assertNotFound();

        $this->assertSame([], $this->mailcow->appPasswords);
        $this->assertSame([], $this->mailcow->paths());
        $this->assertSame(2, AuditLog::query()->where('action', 'comail.access_denied')->count());
    }

    public function test_unknown_mailbox_and_inactive_domain_are_refused(): void
    {
        $this->signed('POST', '/smtp-credentials', $this->site, ['address' => 'yok@acme.test'])->assertNotFound();

        $this->site->comailDomains()->update(['status' => 'pending']);
        $this->signed('POST', '/smtp-credentials', $this->site, ['address' => 'b2b@acme.test'])->assertNotFound();

        $this->assertSame([], $this->mailcow->appPasswords);
    }

    public function test_inactive_mailbox_is_refused(): void
    {
        $this->mailcow->mailboxes['b2b@acme.test']['active'] = 0;
        $this->mailcow->mailboxes['b2b@acme.test']['active_int'] = 0;

        $this->signed('POST', '/smtp-credentials', $this->site, ['address' => 'b2b@acme.test'])
            ->assertStatus(422)
            ->assertJsonPath('error', 'mailbox_inactive');

        $this->assertSame([], $this->mailcow->appPasswords);
    }

    public function test_address_is_required(): void
    {
        $this->signed('POST', '/smtp-credentials', $this->site, [])
            ->assertStatus(422)
            ->assertJsonPath('error', 'validation_failed');
    }

    public function test_alias_domain_addresses_can_be_the_sender(): void
    {
        $this->mailcow->aliasDomains = [
            ['alias_domain' => 'beyazoglu.tr', 'target_domain' => 'acme.test', 'active' => 1],
            ['alias_domain' => 'beyazoglu.com.tr', 'target_domain' => 'acme.test', 'active' => '1'],
            ['alias_domain' => 'kapali.test', 'target_domain' => 'acme.test', 'active' => 0],
            ['alias_domain' => 'baska.test', 'target_domain' => 'other.test', 'active' => 1],
        ];

        $response = $this->signed('POST', '/smtp-credentials', $this->site, ['address' => 'b2b@acme.test', 'from' => 'B2B@Beyazoglu.tr'])
            ->assertCreated()
            ->assertJsonPath('username', 'b2b@acme.test')
            ->assertJsonPath('from', 'b2b@beyazoglu.tr')
            ->assertJsonPath('send_as', ['b2b@acme.test', 'b2b@beyazoglu.com.tr', 'b2b@beyazoglu.tr']);

        $this->assertCount(1, $this->mailcow->appPasswords);
        $this->assertSecretNowhere((string) $response->json('password'));

        $this->signed('GET', '/smtp-senders', $this->site)
            ->assertOk()
            ->assertJsonPath('senders.0.address', 'b2b@acme.test')
            ->assertJsonPath('senders.0.send_as', ['b2b@acme.test', 'b2b@beyazoglu.com.tr', 'b2b@beyazoglu.tr']);
    }

    public function test_a_sender_outside_the_mailbox_is_refused_before_any_password_is_made(): void
    {
        $this->mailcow->aliasDomains = [['alias_domain' => 'kapali.test', 'target_domain' => 'acme.test', 'active' => 0]];

        foreach (['info@acme.test', 'b2b@kapali.test', 'b2b@other.test'] as $from) {
            $this->signed('POST', '/smtp-credentials', $this->site, ['address' => 'b2b@acme.test', 'from' => $from])
                ->assertStatus(422)
                ->assertJsonPath('error', 'sender_not_allowed');
        }

        $this->assertSame([], $this->mailcow->appPasswords);
        $this->assertSame([], $this->mailcow->paths('POST'));
    }

    public function test_senders_list_only_the_sites_active_mailboxes(): void
    {
        $this->mailcow->addMailbox('info@acme.test');
        $this->mailcow->addMailbox('pasif@acme.test');
        $this->mailcow->mailboxes['pasif@acme.test']['active'] = 0;
        $this->mailcow->mailboxes['pasif@acme.test']['active_int'] = 0;
        $other = $this->comailSite('other.test');
        $this->comailDomain($other, 'other.test');
        $this->mailcow->addMailbox('info@other.test');

        $response = $this->signed('GET', '/smtp-senders', $this->site)->assertOk();

        $this->assertSame(['b2b@acme.test', 'info@acme.test'], array_column($response->json('senders'), 'address'));
        $this->assertSame(['info@acme.test'], $response->json('senders.1.send_as'));
        $this->assertSame([], $this->mailcow->appPasswords);
    }

    public function test_share_group_mailbox_works_and_leaving_the_group_revokes_both_ways(): void
    {
        $sibling = $this->comailSite('beyazoglu.test');
        $this->comailDomain($sibling, 'beyazoglu.test');
        $this->mailcow->addMailbox('b2b@beyazoglu.test');
        $this->mailcow->addMailbox('info@beyazoglu.test');
        $groups = app(ComailShareGroup::class);
        $groups->join([$this->site, $sibling]);
        $this->site->refresh();
        $sibling->refresh();

        $this->signed('POST', '/smtp-credentials', $this->site, ['address' => 'b2b@beyazoglu.test'])
            ->assertCreated()
            ->assertJsonPath('username', 'b2b@beyazoglu.test');
        $this->signed('POST', '/smtp-credentials', $sibling, ['address' => 'b2b@acme.test'])->assertCreated();
        // Each site's own mailbox password stays.
        $this->signed('POST', '/smtp-credentials', $this->site, ['address' => 'b2b@acme.test'])->assertCreated();
        $this->signed('POST', '/smtp-credentials', $sibling, ['address' => 'info@beyazoglu.test'])->assertCreated();
        $this->assertSame((string) $sibling->id, AuditLog::query()->where('action', 'comail.smtp_password_created')->first()->after['owner_site_id']);
        $this->assertCount(4, $this->mailcow->appPasswords);

        $groups->leave($this->site->fresh());

        $left = array_map(static fn (array $row): string => $row['mailbox'].' '.(str_contains($row['name'], 'acme.test') ? 'acme' : 'beyazoglu'), array_values($this->mailcow->appPasswords));
        sort($left);
        $this->assertSame(['b2b@acme.test acme', 'info@beyazoglu.test beyazoglu'], $left);
    }

    public function test_archiving_a_site_revokes_its_passwords(): void
    {
        $this->signed('POST', '/smtp-credentials', $this->site, ['address' => 'b2b@acme.test'])->assertCreated();
        $this->mailcow->appPasswords[900] = ['id' => 900, 'name' => ComailWebmailSso::APP_NAME, 'mailbox' => 'b2b@acme.test', 'app_passwd' => 'webmail-one'];

        $this->site->delete();

        $this->assertSame([900], array_keys($this->mailcow->appPasswords));
    }

    public function test_a_domain_taken_over_by_another_site_loses_the_previous_owners_passwords(): void
    {
        // mailcow still has old.test with a mailbox and the previous owner's SMTP password; Plane has no row.
        $this->mailcow->domains['old.test'] = ['domain_name' => 'old.test'];
        $this->mailcow->dkim['old.test'] = ['dkim_txt' => 'v=DKIM1;k=rsa;p=MIIBIjANBgkq', 'dkim_selector' => 'dkim'];
        $this->mailcow->addMailbox('info@old.test');
        $this->mailcow->appPasswords[900] = ['id' => 900, 'name' => ComailWebmailSso::APP_NAME, 'mailbox' => 'info@old.test', 'app_passwd' => 'webmail-one'];
        $this->mailcow->appPasswords[901] = ['id' => 901, 'name' => 'old.test site SMTP (Deamon) 01HX8K3NDEKTSV4RRFFQ69G5FA.abc123', 'mailbox' => 'info@old.test', 'app_passwd' => 'old-owner'];
        $this->mailcow->appPasswords[902] = ['id' => 902, 'name' => 'old.test site SMTP (Deamon)', 'mailbox' => 'info@old.test', 'app_passwd' => 'old-owner-legacy'];

        $newOwner = $this->comailSite('new.test');
        app(ComailDomainProvisioner::class)->ensure($newOwner, 'old.test', false);

        $this->assertSame(SiteComailDomain::STATUS_ACTIVE, $newOwner->comailDomains()->sole()->status);
        $this->assertSame([900], array_keys($this->mailcow->appPasswords));
    }

    public function test_mailcow_failure_returns_a_turkish_error_without_the_password(): void
    {
        $this->mailcow->failOn['/add/app-passwd'] = ['type' => 'danger', 'msg' => ['app_passwd_invalid']];

        $response = $this->signed('POST', '/smtp-credentials', $this->site, ['address' => 'b2b@acme.test'])
            ->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'provider_error');

        $this->assertArrayNotHasKey('password', $response->json());
        $this->assertSame(0, AuditLog::query()->where('action', 'comail.smtp_password_created')->count());

        $sent = collect($this->mailcow->calls)->firstWhere('path', '/add/app-passwd');
        $this->assertNotNull($sent);
        $this->assertSecretNowhere((string) $sent['body']['app_passwd']);
    }

    public function test_broken_mailcow_answers_provider_error_and_creates_nothing(): void
    {
        $this->mailcow->httpStatus = 503;

        $this->signed('POST', '/smtp-credentials', $this->site, ['address' => 'b2b@acme.test'])
            ->assertStatus(422)
            ->assertJsonPath('error', 'provider_error');
        $this->assertSame([], $this->mailcow->appPasswords);
    }

    public function test_unsigned_requests_with_a_site_header_do_not_use_up_the_sites_quota(): void
    {
        for ($i = 0; $i < 65; $i++) {
            $this->call('GET', '/internal/site/v1/comail/smtp-senders', [], [], [], [
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_DEAMON_SITE' => $this->site->id,
                'HTTP_X_DEAMON_TIMESTAMP' => (string) time(),
                'HTTP_X_DEAMON_NONCE' => 'forged-nonce-'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'HTTP_X_DEAMON_SIGNATURE' => str_repeat('0', 64),
            ])->assertUnauthorized();
        }

        $this->signed('GET', '/smtp-senders', $this->site)->assertOk();
    }

    public function test_a_nonce_is_accepted_once(): void
    {
        $signed = ControlPlaneAgentSignature::headers((string) $this->site->agent_secret_encrypted, '');
        $server = [
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_DEAMON_SITE' => $this->site->id,
            'HTTP_X_DEAMON_TIMESTAMP' => $signed['timestamp'],
            'HTTP_X_DEAMON_NONCE' => $signed['nonce'],
            'HTTP_X_DEAMON_SIGNATURE' => $signed['signature'],
        ];

        $this->call('GET', '/internal/site/v1/comail/smtp-senders', [], [], [], $server)->assertOk();
        $this->call('GET', '/internal/site/v1/comail/smtp-senders', [], [], [], $server)->assertUnauthorized();
        $this->assertSame(ControlPlaneAgentContract::HEADER_NONCE, 'X-Deamon-Nonce');
    }

    public function test_names_are_unique_and_owned_by_the_site_id(): void
    {
        $site = new Site(['slug' => 'beyazoglu', 'primary_domain' => null]);
        $site->id = '01HX8K3NDEKTSV4RRFFQ69G5FA';
        $this->assertSame('beyazoglu site SMTP (Deamon)', ComailSmtpCredentials::legacyName($site));
        $name = ComailSmtpCredentials::newName($site);
        $this->assertNotSame($name, ComailSmtpCredentials::newName($site));
        $this->assertTrue(ComailSmtpCredentials::ownedBy($name, $site->id, 'whatever'));
        // A domain change keeps the owner (id token).
        $this->assertTrue(ComailSmtpCredentials::ownedBy('eski.test site SMTP (Deamon) 01HX8K3NDEKTSV4RRFFQ69G5FA.a1b2c3', $site->id, 'yeni.test site SMTP (Deamon)'));
        $this->assertFalse(ComailSmtpCredentials::ownedBy('x site SMTP (Deamon) 01HX8K3NDEKTSV4RRFFQ69G5FB.a1b2c3', $site->id, 'x site SMTP (Deamon)'));
        $this->assertFalse(ComailSmtpCredentials::ownedBy(ComailWebmailSso::APP_NAME, $site->id, 'x'));
        $this->assertTrue(ComailSmtpCredentials::ownedBy('beyazoglu site SMTP (Deamon)', $site->id, 'beyazoglu site SMTP (Deamon)'));
    }

    private function assertSecretNowhere(string $secret): void
    {
        $this->assertNotSame('', $secret);
        foreach (AuditLog::query()->get() as $row) {
            $this->assertStringNotContainsString($secret, (string) json_encode($row->after));
        }
        $this->assertStringNotContainsString($secret, (string) json_encode($this->logged));
    }
}
