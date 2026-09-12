<?php

namespace Tests\Feature\Ops;

use App\Enums\Channel;
use App\Enums\OpsRole;
use App\Enums\SiteStatus;
use App\Jobs\DispatchPlatformMailPushJob;
use App\Models\PlatformMailSetting;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PlatformMailSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Http::preventStrayRequests();
    }

    public function test_operator_can_save_platform_smtp_without_echoing_password(): void
    {
        $this->actingAs($this->operator())
            ->put(route('ops.platform-mail.update'), [
                'enabled' => '1',
                'host' => 'smtp.hostinger.com',
                'port' => 465,
                'encryption' => 'ssl',
                'username' => 'noreply@codron.co',
                'password' => 'super-secret-smtp',
                'from_address' => 'noreply@codron.co',
                'from_name' => 'Deamon Support Team',
                'default_admin_recipient' => 'ops@codron.co',
                'notifications' => [
                    'password_reset' => ['enabled' => '1'],
                    'order_new' => ['enabled' => '1'],
                    'weekly_visitor_report' => ['enabled' => '1', 'day' => 1, 'hour' => 8],
                    'site_version_update' => ['enabled' => '1', 'on' => 'minor'],
                ],
            ])
            ->assertRedirect(route('ops.platform-mail.edit'));

        $settings = PlatformMailSetting::query()->first();
        $this->assertNotNull($settings);
        $this->assertTrue($settings->enabled);
        $this->assertSame('super-secret-smtp', $settings->password);
        $this->assertSame('minor', data_get($settings->notifications, 'site_version_update.on'));

        $this->actingAs($this->operator())
            ->get(route('ops.platform-mail.edit'))
            ->assertOk()
            ->assertSee(__('platform_mail.title'), false)
            ->assertDontSee('super-secret-smtp', false);
    }

    public function test_save_flashes_status_without_waiting_on_sites(): void
    {
        $this->actingAs($this->operator())
            ->put(route('ops.platform-mail.update'), [
                'enabled' => '1',
                'host' => 'smtp.example.com',
                'port' => 465,
                'encryption' => 'ssl',
                'username' => 'u@example.com',
                'password' => 'secret-pass',
                'from_address' => 'noreply@example.com',
                'from_name' => 'Test',
                'default_admin_recipient' => 'ops@example.com',
                'notifications' => [],
            ])
            ->assertRedirect(route('ops.platform-mail.edit'))
            ->assertSessionHas('status', __('platform_mail.flash.saved_push_queued'));

        $this->actingAs($this->operator())
            ->get(route('ops.platform-mail.edit'))
            ->assertOk()
            ->assertSee(__('platform_mail.flash.saved_push_queued'), false);
    }

    public function test_save_queues_platform_mail_push_dispatch(): void
    {
        Queue::fake();

        $this->actingAs($this->operator())
            ->put(route('ops.platform-mail.update'), [
                'enabled' => '1',
                'host' => 'smtp.example.com',
                'port' => 465,
                'encryption' => 'ssl',
                'username' => 'u@example.com',
                'password' => 'secret-pass',
                'from_address' => 'noreply@example.com',
                'from_name' => 'Test',
                'default_admin_recipient' => 'ops@example.com',
                'notifications' => [],
            ])
            ->assertRedirect(route('ops.platform-mail.edit'))
            ->assertSessionHas('status');

        Queue::assertPushed(DispatchPlatformMailPushJob::class);
    }

    public function test_invalid_from_address_redirects_back_with_errors(): void
    {
        $this->actingAs($this->operator())
            ->from(route('ops.platform-mail.edit'))
            ->put(route('ops.platform-mail.update'), [
                'enabled' => '1',
                'host' => 'smtp.example.com',
                'port' => 465,
                'encryption' => 'ssl',
                'username' => 'u@example.com',
                'password' => 'secret-pass',
                'from_address' => 'not-an-email',
                'from_name' => 'Test',
            ])
            ->assertRedirect(route('ops.platform-mail.edit'))
            ->assertSessionHasErrors(['from_address']);

        $this->actingAs($this->operator())
            ->get(route('ops.platform-mail.edit'))
            ->assertOk()
            ->assertSee(__('platform_mail.form_errors'), false);
    }

    public function test_test_mail_requires_ready_settings(): void
    {
        $this->actingAs($this->operator())
            ->post(route('ops.platform-mail.test'), [])
            ->assertRedirect(route('ops.platform-mail.edit'))
            ->assertSessionHas('error');
    }

    public function test_test_mail_sends_when_ready(): void
    {
        Mail::fake();

        PlatformMailSetting::query()->create([
            'enabled' => true,
            'host' => 'smtp.example.com',
            'port' => 465,
            'encryption' => 'ssl',
            'username' => 'u@example.com',
            'password' => 'secret-pass',
            'from_address' => 'noreply@example.com',
            'from_name' => 'Test',
            'default_admin_recipient' => 'ops@example.com',
            'notifications' => [],
        ]);

        config(['mail.default' => 'array']);

        $this->actingAs($this->operator())
            ->post(route('ops.platform-mail.test'), ['to' => 'probe@example.com'])
            ->assertRedirect(route('ops.platform-mail.edit'))
            ->assertSessionHas('status');

        Mail::assertSent(\App\Mail\PlatformTestMail::class, function (\App\Mail\PlatformTestMail $mail): bool {
            return $mail->hasTo('probe@example.com')
                && $mail->hasFrom('noreply@example.com', 'Test');
        });
    }

    public function test_save_does_not_sync_sites_on_update(): void
    {
        Site::factory()->withSecrets()->create([
            'status' => SiteStatus::Active,
            'channel' => Channel::Main,
            'primary_domain' => 'sync-guard.example.test',
            'agent_base_url' => 'https://sync-guard.example.test',
        ]);

        $this->actingAs($this->operator())
            ->put(route('ops.platform-mail.update'), [
                'enabled' => '1',
                'host' => 'smtp.example.com',
                'port' => 465,
                'encryption' => 'ssl',
                'username' => 'u@example.com',
                'password' => 'secret-pass',
                'from_address' => 'noreply@example.com',
                'from_name' => 'Test',
                'default_admin_recipient' => 'ops@example.com',
                'notifications' => [],
            ])
            ->assertRedirect(route('ops.platform-mail.edit'));

        Http::assertNothingSent();
    }

    private function operator(): User
    {
        $operator = User::factory()->create();
        $operator->assignRole(OpsRole::Operator->value);

        return $operator;
    }
}
