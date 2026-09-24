<?php

namespace Tests\Feature\Ops;

use App\Enums\OpsRole;
use App\Models\AuditLog;
use App\Models\DeskronSetting;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DeskronSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Queue::fake();
    }

    public function test_operator_saves_deskron_settings_encrypted_and_never_sees_the_key_again(): void
    {
        $this->actingAs($this->userWithRole(OpsRole::SuperAdmin))
            ->put(route('ops.deskron.update'), [
                'application_id' => '01KDESKRONAPP000000000000',
                'api_key' => 'dsk_super_secret_master_key',
                'webhook_secret' => 'whsec_secret_value',
            ])
            ->assertRedirect(route('ops.deskron.edit'))
            ->assertSessionHas('status', __('deskron.flash.saved'));

        $settings = DeskronSetting::query()->firstOrFail();
        $this->assertSame('01KDESKRONAPP000000000000', $settings->application_id);
        $this->assertSame('dsk_super_secret_master_key', $settings->api_key);
        $this->assertNotSame('dsk_super_secret_master_key', $settings->getRawOriginal('api_key'));
        $this->assertTrue($settings->isReady());

        $audit = AuditLog::query()->where('action', 'deskron.updated')->firstOrFail();
        $this->assertStringNotContainsString('dsk_super_secret_master_key', json_encode($audit->after, JSON_THROW_ON_ERROR));

        $this->actingAs($this->userWithRole(OpsRole::SuperAdmin))
            ->get(route('ops.deskron.edit'))
            ->assertOk()
            ->assertSee(__('deskron.title'), false)
            ->assertSee(__('deskron.state.ready'), false)
            ->assertDontSee('dsk_super_secret_master_key', false)
            ->assertDontSee('whsec_secret_value', false);
    }

    public function test_blank_secret_fields_keep_the_saved_values(): void
    {
        DeskronSetting::query()->create([
            'application_id' => 'OLDAPP',
            'api_key' => 'dsk_existing',
            'webhook_secret' => 'whsec_existing',
        ]);

        $this->actingAs($this->userWithRole(OpsRole::SuperAdmin))
            ->put(route('ops.deskron.update'), [
                'application_id' => 'NEWAPP',
                'api_key' => '',
                'webhook_secret' => '',
            ])
            ->assertRedirect(route('ops.deskron.edit'));

        $settings = DeskronSetting::query()->firstOrFail();
        $this->assertSame('NEWAPP', $settings->application_id);
        $this->assertSame('dsk_existing', $settings->api_key);
        $this->assertSame('whsec_existing', $settings->webhook_secret);
    }

    public function test_viewer_can_open_but_not_save(): void
    {
        $viewer = $this->userWithRole(OpsRole::Viewer);

        $this->actingAs($viewer)->get(route('ops.deskron.edit'))->assertOk();

        $this->actingAs($viewer)
            ->put(route('ops.deskron.update'), ['application_id' => 'APP', 'api_key' => 'dsk_x'])
            ->assertForbidden();

        $this->assertSame(0, DeskronSetting::query()->count());
    }

    private function userWithRole(OpsRole $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);

        return $user;
    }
}
