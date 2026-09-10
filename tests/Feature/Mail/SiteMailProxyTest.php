<?php

namespace Tests\Feature\Mail;

use App\Enums\Channel;
use App\Enums\SiteStatus;
use App\Models\AuditLog;
use App\Models\MailServer;
use App\Models\Site;
use App\Services\Agent\ControlPlaneAgentContract;
use App\Support\ControlPlaneAgentSignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class SiteMailProxyTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'hapi-proxy-token-never-show';

    private const SECRET = 'site-agent-secret-for-mail-proxy-tests-32';

    private const PASSWORD = 'SecurePassword123!';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function test_list_create_password_delete_with_valid_hmac(): void
    {
        $site = $this->readySite();

        Http::fake([
            'https://developers.hostinger.com/api/mail/v1/orders/OR1a2b3c4d5e6f7g/mailboxes' => Http::sequence()
                ->push([
                    'data' => [[
                        'id' => 'ACmailbox1',
                        'email' => 'info@example.com',
                        'local_part' => 'info',
                    ]],
                ], 200)
                ->push([
                    'data' => [
                        'id' => 'ACmailbox2',
                        'email' => 'new@example.com',
                        'local_part' => 'new',
                    ],
                ], 201),
            'https://developers.hostinger.com/api/mail/v1/mailboxes/ACmailbox2/password' => Http::response([], 200),
            'https://developers.hostinger.com/api/mail/v1/mailboxes/ACmailbox2' => Http::response([], 200),
        ]);

        $this->signed('GET', '/internal/site/v1/mail/mailboxes', $site)
            ->assertOk()
            ->assertJsonPath('mailboxes.0.id', 'ACmailbox1')
            ->assertJsonMissingPath('mailboxes.0.password')
            ->assertDontSee(self::TOKEN, false);

        $this->signed('POST', '/internal/site/v1/mail/mailboxes', $site, [
            'local_part' => 'new',
            'password' => self::PASSWORD,
        ])
            ->assertCreated()
            ->assertJsonPath('mailbox.id', 'ACmailbox2')
            ->assertDontSee(self::PASSWORD, false)
            ->assertDontSee(self::TOKEN, false);

        $this->signed('PATCH', '/internal/site/v1/mail/mailboxes/ACmailbox2/password', $site, [
            'password' => self::PASSWORD,
        ])->assertOk()->assertJsonPath('ok', true);

        $this->signed('DELETE', '/internal/site/v1/mail/mailboxes/ACmailbox2', $site)
            ->assertOk()
            ->assertJsonPath('ok', true);
    }

    public function test_rejects_bad_signature(): void
    {
        $site = $this->readySite();

        $this->call(
            'GET',
            '/internal/site/v1/mail/mailboxes',
            [],
            [],
            [],
            [
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_DEAMON_TIMESTAMP' => (string) now()->timestamp,
                'HTTP_X_DEAMON_NONCE' => 'nonce-bad-signature-01',
                'HTTP_X_DEAMON_SIGNATURE' => 'deadbeef',
                'HTTP_X_DEAMON_SITE' => $site->id,
            ],
        )->assertUnauthorized()->assertJsonPath('error', 'unauthorized');
    }

    public function test_rejects_wrong_site_header(): void
    {
        $site = $this->readySite();
        $other = Site::factory()->withSecrets()->create([
            'agent_secret_encrypted' => 'other-secret-value-that-is-long-enough',
        ]);

        $body = '';
        $signed = ControlPlaneAgentSignature::headers((string) $site->agent_secret_encrypted, $body);

        $this->call(
            'GET',
            '/internal/site/v1/mail/mailboxes',
            [],
            [],
            [],
            [
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_'.str_replace('-', '_', strtoupper(ControlPlaneAgentContract::HEADER_TIMESTAMP)) => $signed['timestamp'],
                'HTTP_'.str_replace('-', '_', strtoupper(ControlPlaneAgentContract::HEADER_NONCE)) => $signed['nonce'],
                'HTTP_'.str_replace('-', '_', strtoupper(ControlPlaneAgentContract::HEADER_SIGNATURE)) => $signed['signature'],
                'HTTP_X_DEAMON_SITE' => $other->id,
            ],
        )->assertUnauthorized();
    }

    public function test_missing_mail_server_returns_mail_not_configured(): void
    {
        $site = Site::factory()->withSecrets()->create([
            'status' => SiteStatus::Active,
            'channel' => Channel::Main,
            'agent_secret_encrypted' => self::SECRET,
        ]);

        $this->signed('GET', '/internal/site/v1/mail/mailboxes', $site)
            ->assertStatus(422)
            ->assertJsonPath('error', 'mail_not_configured')
            ->assertDontSee(self::SECRET, false);
    }

    public function test_audit_omits_password_and_token(): void
    {
        $site = $this->readySite();
        Http::fake([
            'https://developers.hostinger.com/api/mail/v1/orders/OR1a2b3c4d5e6f7g/mailboxes' => Http::response([
                'data' => ['id' => 'ACnewbox', 'email' => 'a@example.com', 'local_part' => 'a'],
            ], 201),
        ]);

        $this->signed('POST', '/internal/site/v1/mail/mailboxes', $site, [
            'local_part' => 'a',
            'password' => self::PASSWORD,
        ])->assertCreated();

        $after = AuditLog::query()->where('action', 'mail.mailbox_created')->value('after');
        $encoded = json_encode($after);
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString(self::PASSWORD, $encoded);
        $this->assertStringNotContainsString(self::TOKEN, $encoded);
        $this->assertStringNotContainsString(self::SECRET, $encoded);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function signed(string $method, string $uri, Site $site, array $payload = []): TestResponse
    {
        $body = in_array($method, ['GET', 'DELETE'], true)
            ? ''
            : ControlPlaneAgentContract::encodeJson($payload);
        $signed = ControlPlaneAgentSignature::headers((string) $site->agent_secret_encrypted, $body);

        $server = [
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_DEAMON_TIMESTAMP' => $signed['timestamp'],
            'HTTP_X_DEAMON_NONCE' => $signed['nonce'],
            'HTTP_X_DEAMON_SIGNATURE' => $signed['signature'],
            'HTTP_X_DEAMON_SITE' => $site->id,
        ];

        return $this->call($method, $uri, [], [], [], $server, $body);
    }

    private function readySite(): Site
    {
        $server = MailServer::factory()->hostingerReady(self::TOKEN)->create();

        return Site::factory()->withSecrets()->create([
            'status' => SiteStatus::Active,
            'channel' => Channel::Main,
            'agent_secret_encrypted' => self::SECRET,
            'mail_server_id' => $server->id,
            'hostinger_order_id' => 'OR1a2b3c4d5e6f7g',
            'mail_domain' => 'example.com',
        ]);
    }
}
