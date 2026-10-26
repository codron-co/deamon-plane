<?php

namespace Tests\Feature\Comail;

use App\Http\Controllers\Internal\SiteComailController;
use App\Models\AuditLog;
use App\Models\MailPackage;
use App\Models\MailServer;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Comail\Support\ComailTestHelpers;
use Tests\TestCase;

class HpostaMigrationTest extends TestCase
{
    use ComailTestHelpers;
    use RefreshDatabase;

    private const HOSTINGER = 'https://developers.hostinger.com/api/mail/v1';

    /** @var list<array{id: string, password: string}> */
    private array $passwordChanges = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpComail();

        Http::fake([
            self::HOSTINGER.'/orders/ORacme/mailboxes' => Http::response(['data' => [
                ['id' => 'HBinfo', 'email' => 'info@acme.test', 'local_part' => 'info'],
                ['id' => 'HBsatis', 'email' => 'satis@acme.test', 'local_part' => 'satis'],
            ]], 200),
            self::HOSTINGER.'/orders/ORside/mailboxes' => Http::response(['data' => [
                ['id' => 'HBside', 'email' => 'hello@side.test', 'local_part' => 'hello'],
            ]], 200),
            self::HOSTINGER.'/mailboxes/*/password' => function (Request $request) {
                $id = explode('/', (string) parse_url($request->url(), PHP_URL_PATH))[5] ?? '';
                $this->passwordChanges[] = ['id' => $id, 'password' => (string) ($request->data()['password'] ?? '')];

                return Http::response([], 200);
            },
        ]);
    }

    public function test_listing_without_a_hostinger_binding_is_unavailable(): void
    {
        $site = $this->comailSite();
        $this->comailDomain($site);

        $this->signed('GET', '/migrations/hposta', $site)
            ->assertOk()
            ->assertJsonPath('available', false)
            ->assertJsonPath('mailboxes', []);

        $this->signed('POST', '/migrations/hposta', $site, ['mailbox_ids' => ['HBinfo']])
            ->assertStatus(422)
            ->assertJsonPath('error', 'validation_failed');
    }

    public function test_listing_shows_hposta_boxes_with_comail_state(): void
    {
        $site = $this->hpostaSite();
        $this->comailDomain($site);
        $this->mailcow->addMailbox('info@acme.test');
        $this->mailcow->syncjobs[77] = ['id' => 77, 'user2' => 'info@acme.test', 'host1' => 'imap.hostinger.com', 'port1' => 993, 'user1' => 'info@acme.test', 'enc1' => 'SSL', 'active' => 1];

        $this->signed('GET', '/migrations/hposta', $site)
            ->assertOk()
            ->assertJsonPath('available', true)
            ->assertJsonCount(3, 'mailboxes')
            ->assertJsonPath('mailboxes.0', ['id' => 'HBinfo', 'email' => 'info@acme.test', 'domain' => 'acme.test', 'in_comail' => true, 'migration_id' => '77'])
            ->assertJsonPath('mailboxes.1.in_comail', false)
            ->assertJsonPath('mailboxes.1.migration_id', null)
            ->assertJsonPath('mailboxes.2.domain', 'side.test');
    }

    public function test_one_click_migration_sets_one_password_everywhere_and_creates_the_sync_job(): void
    {
        $site = $this->hpostaSite();
        $this->comailDomain($site);
        $this->starterPackage($site);
        $this->mailcow->addMailbox('info@acme.test');

        $response = $this->signed('POST', '/migrations/hposta', $site, ['mailbox_ids' => ['HBinfo', 'HBsatis']])
            ->assertOk()
            ->assertJsonCount(2, 'results')
            ->assertJsonPath('results.0.email', 'info@acme.test')
            ->assertJsonPath('results.0.ok', true)
            ->assertJsonPath('results.1.email', 'satis@acme.test')
            ->assertJsonPath('results.1.ok', true);

        $infoPassword = (string) $response->json('results.0.password');
        $satisPassword = (string) $response->json('results.1.password');
        foreach ([$infoPassword, $satisPassword] as $password) {
            $this->assertSame(16, strlen($password));
            $this->assertMatchesRegularExpression('/[A-Z]/', $password);
            $this->assertMatchesRegularExpression('/[a-z]/', $password);
            $this->assertMatchesRegularExpression('/[0-9]/', $password);
            $this->assertMatchesRegularExpression('/[!#%*\-_+=]/', $password);
        }

        // Same password on H-Posta and CoMail; existing box edited, missing box created.
        $this->assertSame([
            ['id' => 'HBinfo', 'password' => $infoPassword],
            ['id' => 'HBsatis', 'password' => $satisPassword],
        ], $this->passwordChanges);
        $this->assertSame($infoPassword, $this->mailcow->passwords['info@acme.test']);
        $this->assertSame($satisPassword, $this->mailcow->passwords['satis@acme.test']);
        $this->assertArrayHasKey('satis@acme.test', $this->mailcow->mailboxes);

        $jobs = array_values($this->mailcow->syncjobs);
        $this->assertCount(2, $jobs);
        $this->assertSame('imap.hostinger.com', $jobs[0]['host1']);
        $this->assertSame(993, $jobs[0]['port1']);
        $this->assertSame('SSL', $jobs[0]['enc1']);
        $this->assertSame('info@acme.test', $jobs[0]['user1']);
        $syncCall = collect($this->mailcow->calls)->firstWhere('path', '/add/syncjob');
        $this->assertSame($infoPassword, $syncCall['body']['password1']);

        foreach (AuditLog::query()->where('action', 'comail.hposta_migrated')->get() as $audit) {
            $encoded = (string) json_encode($audit->after);
            $this->assertStringNotContainsString($infoPassword, $encoded);
            $this->assertStringNotContainsString($satisPassword, $encoded);
        }

        // A second run keeps the existing sync job.
        $this->signed('POST', '/migrations/hposta', $site, ['mailbox_ids' => ['HBinfo']])->assertOk()->assertJsonPath('results.0.ok', true);
        $this->assertCount(2, $this->mailcow->syncjobs);
    }

    public function test_domain_must_already_be_in_comail_and_nothing_changes_otherwise(): void
    {
        $site = $this->hpostaSite();
        $this->comailDomain($site);

        $this->signed('POST', '/migrations/hposta', $site, ['mailbox_ids' => ['HBinfo', 'HBside']])
            ->assertStatus(422)
            ->assertJsonPath('error', 'domain_not_in_comail');

        $this->assertSame([], $this->passwordChanges);
        $this->assertSame([], $this->mailcow->mailboxes);
    }

    public function test_foreign_mailbox_ids_are_refused(): void
    {
        $site = $this->hpostaSite();
        $this->comailDomain($site);

        $this->signed('POST', '/migrations/hposta', $site, ['mailbox_ids' => ['HBsomeoneelse']])
            ->assertNotFound()
            ->assertJsonPath('error', 'not_found');
        $this->assertSame([], $this->passwordChanges);
    }

    public function test_package_limit_is_reported_per_item_without_touching_hposta(): void
    {
        $site = $this->hpostaSite();
        $this->comailDomain($site);
        $this->mailcow->addMailbox('info@acme.test');

        // Free package: one mailbox; satis would be the second.
        $this->signed('POST', '/migrations/hposta', $site, ['mailbox_ids' => ['HBsatis']])
            ->assertOk()
            ->assertJsonPath('results.0.ok', false)
            ->assertJsonPath('results.0.error', 'limit_mailboxes')
            ->assertJsonMissingPath('results.0.password');

        $this->assertSame([], $this->passwordChanges);
    }

    public function test_hposta_endpoints_require_the_site_signature(): void
    {
        $site = $this->hpostaSite();
        $this->comailDomain($site);

        $this->signed('GET', '/migrations/hposta', $site, [], 'wrong-secret')->assertUnauthorized();
        $this->signed('POST', '/migrations/hposta', $site, ['mailbox_ids' => ['HBinfo']], 'wrong-secret')->assertUnauthorized();
        $this->postJson('/internal/site/v1/comail/migrations/hposta', ['mailbox_ids' => ['HBinfo']])->assertUnauthorized();

        $this->assertSame([], $this->passwordChanges);
        $this->assertSame([], $this->mailcow->calls);
    }

    public function test_generated_passwords_follow_the_rules(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $password = SiteComailController::generatePassword();
            $this->assertMatchesRegularExpression('/\A(?=.*[A-Z])(?=.*[a-z])(?=.*\d)(?=.*[!#%*\-_+=])[A-Za-z0-9!#%*\-_+=]{16}\z/', $password);
        }
    }

    private function hpostaSite(): Site
    {
        $server = MailServer::factory()->hostingerReady('hapi-token-never-show')->create();
        $site = $this->comailSite('acme.test', ['mail_server_id' => $server->id, 'hostinger_order_id' => 'ORacme', 'mail_domain' => 'acme.test']);
        $site->mailBindings()->create(['hostinger_order_id' => 'ORacme', 'mail_domain' => 'acme.test']);
        $site->mailBindings()->create(['hostinger_order_id' => 'ORside', 'mail_domain' => 'side.test']);

        return $site->fresh();
    }

    private function starterPackage(Site $site): void
    {
        $site->forceFill(['mail_package_id' => MailPackage::query()->where('slug', 'starter')->value('id')])->save();
    }
}
