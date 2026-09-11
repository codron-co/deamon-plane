<?php

namespace Tests\Feature\Ops;

use App\Enums\OpsRole;
use App\Models\PlatformMailSetting;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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

    private function operator(): User
    {
        $operator = User::factory()->create();
        $operator->assignRole(OpsRole::Operator->value);

        return $operator;
    }
}
