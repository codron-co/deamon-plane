<?php

namespace Tests\Feature\Ops;

use App\Mail\PlatformOpsMail;
use App\Models\PlatformMailSetting;
use App\Models\Site;
use App\Models\User;
use App\Services\Mail\PlatformMailUnsubscribe;
use App\Services\Mail\PlatformNotificationCatalog;
use App\Services\Mail\PlatformOpsMailer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class PlatformMailUnsubscribeTest extends TestCase
{
    use RefreshDatabase;

    public function test_signed_guest_unsubscribe_opts_out_user_for_key(): void
    {
        $user = User::factory()->create();
        $url = URL::temporarySignedRoute(
            'ops.platform-mail.unsubscribe',
            now()->addDay(),
            ['user' => $user->id, 'key' => PlatformNotificationCatalog::SITE_DOWN],
        );

        $this->get($url)
            ->assertOk()
            ->assertSee(__('platform_mail.unsubscribe.done'), false);

        $this->assertTrue($user->refresh()->hasMailOptOut(PlatformNotificationCatalog::SITE_DOWN));
    }

    public function test_unsigned_unsubscribe_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->get(route('ops.platform-mail.unsubscribe', [
            'user' => $user->id,
            'key' => PlatformNotificationCatalog::SITE_DOWN,
        ]))->assertForbidden();

        $this->assertFalse($user->refresh()->hasMailOptOut(PlatformNotificationCatalog::SITE_DOWN));
    }

    public function test_unsubscribe_service_rejects_non_plane_notification_key(): void
    {
        $user = User::factory()->create();
        $url = URL::temporarySignedRoute(
            'ops.platform-mail.unsubscribe',
            now()->addDay(),
            ['user' => $user->id, 'key' => PlatformNotificationCatalog::PASSWORD_RESET],
        );

        $this->assertFalse(app(PlatformMailUnsubscribe::class)->apply($url));
        $this->assertFalse($user->refresh()->hasMailOptOut(PlatformNotificationCatalog::PASSWORD_RESET));
    }

    public function test_ops_mailer_skips_opted_out_user_and_sends_to_other_user(): void
    {
        Mail::fake();
        $site = Site::factory()->create();
        $optedOut = User::factory()->create(['email' => 'opted-out@example.test']);
        $eligible = User::factory()->create(['email' => 'eligible@example.test']);
        $optedOut->optOutMail(PlatformNotificationCatalog::SITE_DOWN);
        $this->readySettings();

        $sent = app(PlatformOpsMailer::class)->send(
            $site,
            PlatformNotificationCatalog::SITE_DOWN,
            'Site unavailable',
            'The health check failed.',
        );

        $this->assertTrue($sent);
        Mail::assertNotSent(PlatformOpsMail::class, fn (PlatformOpsMail $mail): bool => $mail->hasTo($optedOut->email));
        Mail::assertSent(PlatformOpsMail::class, fn (PlatformOpsMail $mail): bool => $mail->hasTo($eligible->email));
    }

    public function test_user_mail_has_unsubscribe_link_and_header(): void
    {
        Mail::fake();
        $site = Site::factory()->create();
        $user = User::factory()->create();
        $this->readySettings();

        app(PlatformOpsMailer::class)->send(
            $site,
            PlatformNotificationCatalog::DEPLOY_FAILED,
            'Deploy failed',
            'Deployment details.',
        );

        Mail::assertSent(PlatformOpsMail::class, function (PlatformOpsMail $mail) use ($user): bool {
            $mail->assertSeeInHtml(__('platform_mail.unsubscribe.link'));
            $headers = $mail->headers()->text;

            return $mail->hasTo($user->email)
                && str_contains($mail->unsubscribeUrl, 'signature=')
                && ($headers['List-Unsubscribe'] ?? null) === '<'.$mail->unsubscribeUrl.'>';
        });
    }

    public function test_non_user_primary_recipient_is_still_eligible(): void
    {
        Mail::fake();
        $site = Site::factory()->create();
        $user = User::factory()->create();
        $user->optOutMail(PlatformNotificationCatalog::SITE_UP);
        $this->readySettings('outside-plane@example.test');

        $this->assertTrue(app(PlatformOpsMailer::class)->send(
            $site,
            PlatformNotificationCatalog::SITE_UP,
            'Site recovered',
            'The site is healthy again.',
        ));
        Mail::assertNotSent(PlatformOpsMail::class);
    }

    private function readySettings(?string $primaryRecipient = null): PlatformMailSetting
    {
        return PlatformMailSetting::query()->create([
            'enabled' => true,
            'host' => 'smtp.example.test',
            'port' => 465,
            'encryption' => 'ssl',
            'username' => 'mailer@example.test',
            'password' => 'test-password',
            'from_address' => 'noreply@example.test',
            'from_name' => 'Deamon Plane',
            'default_admin_recipient' => $primaryRecipient,
            'notifications' => [],
        ]);
    }
}
