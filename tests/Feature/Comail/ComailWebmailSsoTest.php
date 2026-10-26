<?php

namespace Tests\Feature\Comail;

use App\Models\AuditLog;
use App\Models\MailServer;
use App\Models\Site;
use App\Services\Comail\ComailWebmailSso;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Comail\Support\ComailTestHelpers;
use Tests\Feature\Comail\Support\FakeMailcow;
use Tests\TestCase;

class ComailWebmailSsoTest extends TestCase
{
    use ComailTestHelpers;
    use RefreshDatabase;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpComail();
        $this->site = $this->comailSite();
        $this->comailDomain($this->site);
        $this->mailcow->addMailbox('info@acme.test');
    }

    public function test_server_gets_an_encrypted_sso_secret_on_save(): void
    {
        $server = MailServer::comail();
        $secret = (string) $server->sso_secret;

        $this->assertSame(48, strlen($secret));
        $this->assertNotSame($secret, $server->getRawOriginal('sso_secret'));
        $this->assertArrayNotHasKey('sso_secret', $server->toArray());

        $server->name = 'CoMail 2';
        $server->save();
        $this->assertSame($secret, (string) $server->fresh()->sso_secret);
    }

    public function test_webmail_login_creates_a_fresh_app_password_and_a_one_time_url(): void
    {
        // An older one-click password and an unrelated one the user made himself.
        $this->mailcow->appPasswords[500] = ['id' => 500, 'name' => ComailWebmailSso::APP_NAME, 'mailbox' => 'info@acme.test', 'app_passwd' => 'old'];
        $this->mailcow->appPasswords[501] = ['id' => 501, 'name' => 'Telefonum', 'mailbox' => 'info@acme.test', 'app_passwd' => 'mine'];

        $response = $this->signed('POST', '/mailboxes/info@acme.test/webmail-login', $this->site)->assertOk();

        $response->assertJsonPath('ok', true)->assertJsonPath('expires_in', 120);
        $url = (string) $response->json('url');
        $this->assertMatchesRegularExpression('~\A'.preg_quote(FakeMailcow::BASE, '~').'/webmail/\?_task=login&_comail_sso=[a-f0-9]{64}\z~', $url);

        $this->assertArrayNotHasKey(500, $this->mailcow->appPasswords);
        $this->assertArrayHasKey(501, $this->mailcow->appPasswords);
        $created = collect($this->mailcow->appPasswords)->firstWhere('name', ComailWebmailSso::APP_NAME);
        $this->assertSame('info@acme.test', $created['mailbox']);
        $this->assertSame(['imap_access', 'smtp_access'], $created['protocols']);
        $this->assertSame(32, strlen($created['app_passwd']));

        $add = collect($this->mailcow->calls)->firstWhere('path', '/add/app-passwd');
        $this->assertSame(1, $add['body']['active']);
        $this->assertSame($add['body']['app_passwd'], $add['body']['app_passwd2']);

        // Neither the response nor the audit carries the password.
        $this->assertStringNotContainsString($created['app_passwd'], $response->getContent());
        $audit = AuditLog::query()->where('action', 'comail.webmail_login')->sole();
        $this->assertSame('info@acme.test', $audit->after['address']);
        $this->assertStringNotContainsString($created['app_passwd'], json_encode($audit->after));

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->redeem(['token' => $query['_comail_sso']])
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertExactJson(['username' => 'info@acme.test', 'password' => $created['app_passwd']]);
    }

    public function test_token_is_redeemed_once(): void
    {
        $token = $this->token();

        $this->redeem(['token' => $token])->assertOk();
        $this->redeem(['token' => $token])->assertNotFound()->assertJsonPath('error', 'not_found');
    }

    public function test_token_expires_after_120_seconds(): void
    {
        $token = $this->token();

        $this->travel(121)->seconds();
        $this->redeem(['token' => $token])->assertNotFound();
    }

    public function test_redeem_signature_is_checked(): void
    {
        $token = $this->token();
        $body = json_encode(['token' => $token]);
        $secret = (string) MailServer::comail()->sso_secret;
        $now = (string) time();

        $this->redeemRaw($body, $now, ComailWebmailSso::signature('wrong-secret', $now, $body))->assertUnauthorized();
        $this->redeemRaw($body, $now, '')->assertUnauthorized();
        // Signature over another body.
        $this->redeemRaw($body, $now, ComailWebmailSso::signature($secret, $now, '{"token":"x"}'))->assertUnauthorized();
        // Outside the 60 s window.
        $old = (string) (time() - 61);
        $this->redeemRaw($body, $old, ComailWebmailSso::signature($secret, $old, $body))->assertUnauthorized();

        // The token survived the refused attempts.
        $this->redeem(['token' => $token])->assertOk();
    }

    public function test_unknown_or_malformed_tokens_are_404(): void
    {
        $this->redeem(['token' => str_repeat('a', 64)])->assertNotFound();
        $this->redeem(['token' => 'short'])->assertNotFound();
        $this->redeem([])->assertNotFound();
    }

    public function test_redeem_is_throttled(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->redeem(['token' => str_repeat('b', 64)])->assertNotFound();
        }

        $this->redeem(['token' => str_repeat('b', 64)])->assertStatus(429);
    }

    public function test_webmail_login_only_for_the_sites_own_mailboxes(): void
    {
        $other = $this->comailSite('other.test');
        $this->comailDomain($other, 'other.test');
        $this->mailcow->addMailbox('boss@other.test');

        $this->signed('POST', '/mailboxes/boss@other.test/webmail-login', $this->site)
            ->assertNotFound()->assertJsonPath('error', 'not_found');
        $this->signed('POST', '/mailboxes/nobody@acme.test/webmail-login', $this->site)->assertNotFound();

        $this->assertSame([], $this->mailcow->appPasswords);
        $this->assertTrue(AuditLog::query()->where('action', 'comail.access_denied')->exists());
    }

    public function test_mailcow_failure_is_a_provider_error(): void
    {
        $this->mailcow->failOn['/add/app-passwd'] = ['type' => 'danger', 'msg' => ['app_passwd_invalid']];

        $this->signed('POST', '/mailboxes/info@acme.test/webmail-login', $this->site)
            ->assertStatus(422)->assertJsonPath('error', 'provider_error');
    }

    public function test_sso_secret_command_prints_and_rotates(): void
    {
        $before = (string) MailServer::comail()->sso_secret;

        Artisan::call('ops:comail-sso-secret');
        $this->assertSame($before, trim(Artisan::output()));

        Artisan::call('ops:comail-sso-secret', ['--rotate' => true]);
        $after = trim(Artisan::output());
        $this->assertNotSame($before, $after);
        $this->assertSame($after, (string) MailServer::comail()->sso_secret);
        $this->assertTrue(AuditLog::query()->where('action', 'mail_server.sso_secret_rotated')->exists());
    }

    private function token(): string
    {
        $url = (string) $this->signed('POST', '/mailboxes/info@acme.test/webmail-login', $this->site)->assertOk()->json('url');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return (string) $query['_comail_sso'];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function redeem(array $payload): TestResponse
    {
        $body = (string) json_encode($payload === [] ? new \stdClass : $payload);
        $timestamp = (string) time();

        return $this->redeemRaw($body, $timestamp, ComailWebmailSso::signature((string) MailServer::comail()->sso_secret, $timestamp, $body));
    }

    private function redeemRaw(string $body, string $timestamp, string $signature): TestResponse
    {
        return $this->call('POST', '/internal/comail/v1/sso/redeem', [], [], [], [
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_COMAIL_TIMESTAMP' => $timestamp,
            'HTTP_X_COMAIL_SIGNATURE' => $signature,
        ], $body);
    }
}
