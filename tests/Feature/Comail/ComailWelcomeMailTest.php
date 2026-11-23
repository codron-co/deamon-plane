<?php

namespace Tests\Feature\Comail;

use App\Jobs\SendComailWelcomeMailJob;
use App\Mail\ComailWelcomeMail;
use App\Models\AuditLog;
use App\Models\MailPackage;
use App\Models\MailServer;
use App\Models\PlatformMailSetting;
use App\Models\Site;
use App\Services\Comail\ComailWelcome;
use App\Services\Mail\PlatformNotificationCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\Feature\Comail\Support\ComailTestHelpers;
use Tests\TestCase;

class ComailWelcomeMailTest extends TestCase
{
    use ComailTestHelpers;
    use RefreshDatabase;

    private const PASSWORD = 'Gizli-Parola-42x';

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpComail();
    }

    public function test_switch_is_off_by_default_and_nothing_is_queued(): void
    {
        $this->readyPlatformMail(enabled: null);
        Bus::fake([SendComailWelcomeMailJob::class]);
        $site = $this->siteWithDomain();

        $this->assertFalse(PlatformNotificationCatalog::definitions()[PlatformNotificationCatalog::COMAIL_WELCOME]['default']);
        $this->assertFalse(app(ComailWelcome::class)->enabled());

        $this->createMailbox($site)->assertCreated();

        Bus::assertNotDispatched(SendComailWelcomeMailJob::class);
        $this->assertFalse(AuditLog::query()->where('action', 'comail.mailbox_created')->firstOrFail()->after['welcome_mail']);
    }

    public function test_new_mailbox_gets_the_welcome_mail_without_the_password(): void
    {
        $this->readyPlatformMail(enabled: true);
        Mail::fake();
        $site = $this->siteWithDomain();

        $this->createMailbox($site, ['name' => 'Ayşe Yılmaz'])
            ->assertCreated()
            ->assertDontSee(self::PASSWORD, false);

        Mail::assertSent(ComailWelcomeMail::class, function (ComailWelcomeMail $mail): bool {
            $html = $mail->render();
            $text = view('mail.comail-welcome.text', ['w' => $mail->welcome])->render();

            $this->assertTrue($mail->hasTo('info@acme.test'));
            $this->assertTrue($mail->hasSubject('E-posta hesabınız hazır: info@acme.test'));
            $this->assertTrue($mail->hasReplyTo(ComailWelcome::SUPPORT_EMAIL));
            $this->assertStringNotContainsString(self::PASSWORD, $html);
            $this->assertStringNotContainsString(self::PASSWORD, $text);
            $this->assertStringContainsString('Merhaba Ayşe,', $html);
            $this->assertStringContainsString('mail.example.test', $html);
            $this->assertStringContainsString('993', $text);
            $this->assertStringContainsString('465', $text);
            $this->assertStringContainsString('587', $text);
            $this->assertStringContainsString('brand/codron/logo.png', $html);
            $this->assertStringNotContainsString('yeni bir şifre', $html);
            $this->assertStringNotContainsString('—', $html.$text);

            return true;
        });
        $this->assertTrue(AuditLog::query()->where('action', 'comail.mailbox_created')->firstOrFail()->after['welcome_mail']);
    }

    public function test_force_password_change_is_set_on_mailcow_and_mentioned_in_the_mail(): void
    {
        $this->readyPlatformMail(enabled: true);
        Mail::fake();
        $site = $this->siteWithDomain();

        $this->createMailbox($site, ['force_password_change' => true])->assertCreated();

        $this->assertSame('1', $this->mailcow->mailboxes['info@acme.test']['attributes']['force_pw_update']);
        Mail::assertSent(ComailWelcomeMail::class, fn (ComailWelcomeMail $mail): bool => str_contains($mail->render(), 'yeni bir şifre'));
    }

    public function test_send_welcome_false_skips_the_mail(): void
    {
        $this->readyPlatformMail(enabled: true);
        Bus::fake([SendComailWelcomeMailJob::class]);
        $site = $this->siteWithDomain();

        $this->createMailbox($site, ['send_welcome' => false])->assertCreated();

        Bus::assertNotDispatched(SendComailWelcomeMailJob::class);
    }

    public function test_without_ready_platform_mail_nothing_is_queued(): void
    {
        PlatformMailSetting::query()->create([
            'enabled' => false,
            'notifications' => [PlatformNotificationCatalog::COMAIL_WELCOME => ['enabled' => true]],
        ]);
        Bus::fake([SendComailWelcomeMailJob::class]);
        $site = $this->siteWithDomain();

        $this->createMailbox($site)->assertCreated();

        Bus::assertNotDispatched(SendComailWelcomeMailJob::class);
    }

    public function test_hposta_migration_does_not_send_the_mail(): void
    {
        $this->readyPlatformMail(enabled: true);
        Bus::fake([SendComailWelcomeMailJob::class]);
        Http::fake([
            'https://developers.hostinger.com/api/mail/v1/orders/ORacme/mailboxes' => Http::response(['data' => [
                ['id' => 'HBinfo', 'email' => 'info@acme.test', 'local_part' => 'info'],
            ]], 200),
            'https://developers.hostinger.com/api/mail/v1/mailboxes/*/password' => fn (Request $request) => Http::response([], 200),
        ]);
        $server = MailServer::factory()->hostingerReady('hapi-token')->create();
        $site = $this->comailSite('acme.test', ['mail_server_id' => $server->id, 'hostinger_order_id' => 'ORacme', 'mail_domain' => 'acme.test']);
        $site->mailBindings()->create(['hostinger_order_id' => 'ORacme', 'mail_domain' => 'acme.test']);
        $this->comailDomain($site);

        $this->signed('POST', '/migrations/hposta', $site->fresh(), ['mailbox_ids' => ['HBinfo']])
            ->assertOk()
            ->assertJsonPath('results.0.ok', true);

        $this->assertArrayHasKey('info@acme.test', $this->mailcow->mailboxes);
        Bus::assertNotDispatched(SendComailWelcomeMailJob::class);
    }

    public function test_a_failing_send_does_not_break_mailbox_creation(): void
    {
        $this->readyPlatformMail(enabled: true);
        $site = $this->siteWithDomain();
        // Sync queue in tests: the job runs inline and its SMTP error reaches ComailWelcome::queue().
        Mail::shouldReceive('mailer')->andThrow(new RuntimeException('smtp down'));

        $this->createMailbox($site)->assertCreated()->assertJsonPath('address', 'info@acme.test');

        $this->assertArrayHasKey('info@acme.test', $this->mailcow->mailboxes);
    }

    public function test_preview_command_writes_html_without_sending(): void
    {
        Mail::fake();

        $this->artisan('comail:welcome-mail', ['address' => 'ali@acme.test', '--dry-run' => true])
            ->expectsOutputToContain('HTML:')
            ->assertSuccessful();

        $html = (string) file_get_contents(storage_path('app/comail-welcome/ali-acme-test.html'));
        $this->assertStringContainsString('ali@acme.test', $html);
        $this->assertStringContainsString('E-posta hesabınız hazır', $html);
        Mail::assertNothingSent();
    }

    public function test_preview_command_sends_only_to_the_test_address_even_while_off(): void
    {
        $this->readyPlatformMail(enabled: false);
        Mail::fake();

        $this->artisan('comail:welcome-mail', ['address' => 'ali@acme.test', '--to' => 'emin@codron.co'])->assertSuccessful();

        Mail::assertSent(ComailWelcomeMail::class, fn (ComailWelcomeMail $mail): bool => $mail->hasTo('emin@codron.co') && ! $mail->hasTo('ali@acme.test'));
    }

    public function test_outlook_section_follows_the_autodiscover_dns_check(): void
    {
        $site = $this->comailSite();
        $this->comailDomain($site, 'acme.test', [
            'mx_active_at' => now(),
            'dns_check' => ['records' => [['key' => 'cname_autodiscover', 'status' => 'ok']]],
        ]);
        $server = MailServer::comail();
        $welcome = app(ComailWelcome::class);

        $this->assertTrue($welcome->data($server, 'a@acme.test')['autodiscover']);
        $this->assertFalse($welcome->data($server, 'a@other.test')['autodiscover']);
    }

    public function test_switch_is_not_a_per_site_override(): void
    {
        $this->assertArrayNotHasKey(PlatformNotificationCatalog::COMAIL_WELCOME, PlatformNotificationCatalog::siteDefinitions());
    }

    private function siteWithDomain(): Site
    {
        $site = $this->comailSite();
        $this->comailDomain($site);
        $site->forceFill(['mail_package_id' => MailPackage::query()->where('slug', 'starter')->value('id')])->save();

        return $site->fresh();
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function createMailbox(Site $site, array $extra = []): TestResponse
    {
        return $this->signed('POST', '/mailboxes', $site, $extra + [
            'local_part' => 'info',
            'domain' => 'acme.test',
            'password' => self::PASSWORD,
        ]);
    }

    private function readyPlatformMail(?bool $enabled): void
    {
        PlatformMailSetting::query()->create([
            'enabled' => true,
            'host' => 'smtp.example.test',
            'port' => 465,
            'encryption' => 'ssl',
            'username' => 'mailer@example.test',
            'password' => 'smtp-secret',
            'from_address' => 'noreply@example.test',
            'from_name' => 'Deamon Plane',
            'notifications' => $enabled === null ? [] : [PlatformNotificationCatalog::COMAIL_WELCOME => ['enabled' => $enabled]],
        ]);
    }
}
