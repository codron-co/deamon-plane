<?php

namespace Tests\Feature\Comail;

use App\Models\AuditLog;
use App\Models\Site;
use App\Services\Comail\ComailSmtpCredentials;
use App\Services\Comail\ComailWebmailSso;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\Feature\Comail\Support\ComailTestHelpers;
use Tests\TestCase;

/**
 * Site SMTP from a CoMail mailbox: `POST /smtp-credentials` creates an
 * SMTP-only app password (no mailbox password involved) and returns it once;
 * `DELETE /smtp-credentials/{address}` removes it.
 */
class ComailSmtpCredentialsTest extends TestCase
{
    use ComailTestHelpers;
    use RefreshDatabase;

    private const APP_NAME = 'acme.test site SMTP (Deamon)';

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
        $this->signed('DELETE', '/smtp-credentials/b2b@acme.test', $this->site, [], 'wrong-secret')->assertUnauthorized();

        $this->assertSame([], $this->mailcow->calls);
        $this->assertSame([], $this->mailcow->appPasswords);
    }

    public function test_creates_an_smtp_only_app_password_and_returns_it_once(): void
    {
        $response = $this->signed('POST', '/smtp-credentials', $this->site, ['address' => 'B2B@Acme.test'])
            ->assertCreated()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('ok', true)
            ->assertJsonPath('smtp.host', 'mail.example.test')
            ->assertJsonPath('smtp.port', 465)
            ->assertJsonPath('smtp.encryption', 'ssl')
            ->assertJsonPath('smtp.username', 'b2b@acme.test')
            ->assertJsonPath('host', 'mail.example.test')
            ->assertJsonPath('username', 'b2b@acme.test');

        $password = (string) $response->json('smtp.password');
        $this->assertMatchesRegularExpression('/\A[A-Za-z0-9]{32}\z/', $password);
        $this->assertSame($password, $response->json('password'));

        $this->assertCount(1, $this->mailcow->appPasswords);
        $created = array_values($this->mailcow->appPasswords)[0];
        $this->assertSame(self::APP_NAME, $created['name']);
        $this->assertSame('b2b@acme.test', $created['mailbox']);
        $this->assertSame(['smtp_access'], $created['protocols']);
        $this->assertSame(1, $created['active']);
        $this->assertSame($password, $created['app_passwd']);

        $audit = AuditLog::query()->where('action', 'comail.smtp_password_created')->sole();
        $this->assertSame('b2b@acme.test', $audit->after['address']);
        $this->assertSame(self::APP_NAME, $audit->after['app_name']);
        $this->assertSame(0, $audit->after['replaced']);
        $this->assertSame('cms', $audit->after['source']);

        $this->assertSecretNowhere($password);
    }

    public function test_issuing_again_rotates_only_this_sites_password(): void
    {
        // Another site's SMTP password and the one-click webmail password on the same mailbox stay.
        $this->mailcow->appPasswords[900] = ['id' => 900, 'name' => ComailWebmailSso::APP_NAME, 'mailbox' => 'b2b@acme.test', 'app_passwd' => 'webmail-one'];
        $this->mailcow->appPasswords[901] = ['id' => 901, 'name' => 'other.test site SMTP (Deamon)', 'mailbox' => 'b2b@acme.test', 'app_passwd' => 'other-site'];

        $first = (string) $this->signed('POST', '/smtp-credentials', $this->site, ['address' => 'b2b@acme.test'])->assertCreated()->json('smtp.password');
        $second = (string) $this->signed('POST', '/smtp-credentials', $this->site, ['address' => 'b2b@acme.test'])->assertCreated()->json('smtp.password');

        $this->assertNotSame($first, $second);
        $ours = array_values(array_filter($this->mailcow->appPasswords, static fn (array $row): bool => $row['name'] === self::APP_NAME));
        $this->assertCount(1, $ours);
        $this->assertSame($second, $ours[0]['app_passwd']);
        $this->assertArrayHasKey(900, $this->mailcow->appPasswords);
        $this->assertArrayHasKey(901, $this->mailcow->appPasswords);

        $this->assertSame(1, AuditLog::query()->where('action', 'comail.smtp_password_created')->get()->last()->after['replaced']);
    }

    public function test_another_sites_mailbox_is_refused(): void
    {
        $other = $this->comailSite('other.test');
        $this->comailDomain($other, 'other.test');
        $this->mailcow->addMailbox('info@other.test');

        $this->signed('POST', '/smtp-credentials', $this->site, ['address' => 'info@other.test'])
            ->assertNotFound()
            ->assertJsonPath('error', 'not_found');
        $this->signed('DELETE', '/smtp-credentials/info@other.test', $this->site)
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

    public function test_share_group_mailbox_can_be_used_with_the_calling_sites_own_name(): void
    {
        $group = (string) str()->ulid();
        $sibling = $this->comailSite('beyazoglu.test', ['comail_share_group' => $group]);
        $this->comailDomain($sibling, 'beyazoglu.test');
        $this->mailcow->addMailbox('b2b@beyazoglu.test');
        $this->site->forceFill(['comail_share_group' => $group])->save();

        $this->signed('POST', '/smtp-credentials', $this->site, ['address' => 'b2b@beyazoglu.test'])
            ->assertCreated()
            ->assertJsonPath('smtp.username', 'b2b@beyazoglu.test');

        $this->assertSame(self::APP_NAME, array_values($this->mailcow->appPasswords)[0]['name']);
        $this->assertSame((string) $sibling->id, AuditLog::query()->where('action', 'comail.smtp_password_created')->sole()->after['owner_site_id']);
    }

    public function test_revoke_removes_only_this_sites_password(): void
    {
        $this->signed('POST', '/smtp-credentials', $this->site, ['address' => 'b2b@acme.test'])->assertCreated();
        $this->mailcow->appPasswords[900] = ['id' => 900, 'name' => ComailWebmailSso::APP_NAME, 'mailbox' => 'b2b@acme.test', 'app_passwd' => 'webmail-one'];

        $this->signed('DELETE', '/smtp-credentials/'.rawurlencode('b2b@acme.test'), $this->site)
            ->assertOk()
            ->assertJsonPath('revoked', 1);

        $this->assertSame([900], array_keys($this->mailcow->appPasswords));
        $audit = AuditLog::query()->where('action', 'comail.smtp_password_revoked')->sole();
        $this->assertSame(['address' => 'b2b@acme.test', 'app_name' => self::APP_NAME, 'revoked' => 1], array_intersect_key($audit->after, array_flip(['address', 'app_name', 'revoked'])));

        // Nothing left: still ok, no audit row.
        $this->signed('DELETE', '/smtp-credentials/b2b@acme.test', $this->site)->assertOk()->assertJsonPath('revoked', 0);
        $this->assertSame(1, AuditLog::query()->where('action', 'comail.smtp_password_revoked')->count());
    }

    public function test_revoke_of_a_deleted_mailbox_is_a_no_op(): void
    {
        unset($this->mailcow->mailboxes['b2b@acme.test']);

        $this->signed('DELETE', '/smtp-credentials/b2b@acme.test', $this->site)->assertOk()->assertJsonPath('revoked', 0);
        $this->assertSame([], $this->mailcow->paths('POST'));
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

    public function test_app_name_falls_back_to_the_slug_without_a_primary_domain(): void
    {
        $site = new Site(['slug' => 'beyazoglu', 'primary_domain' => null]);
        $this->assertSame('beyazoglu site SMTP (Deamon)', ComailSmtpCredentials::appName($site));
        $this->assertSame('acme.test site SMTP (Deamon)', ComailSmtpCredentials::appName(new Site(['primary_domain' => ' ACME.test '])));
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
