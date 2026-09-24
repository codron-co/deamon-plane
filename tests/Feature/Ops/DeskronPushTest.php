<?php

namespace Tests\Feature\Ops;

use App\Enums\OpsRole;
use App\Enums\SiteStatus;
use App\Jobs\DispatchDeskronPushJob;
use App\Jobs\PushDeskronJob;
use App\Models\DeskronSetting;
use App\Models\Site;
use App\Models\User;
use App\Services\Agent\ControlPlaneAgentContract;
use App\Services\Deskron\DeskronConfigurer;
use App\Support\ControlPlaneAgentSignature;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DeskronPushTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Http::preventStrayRequests();
    }

    public function test_configurer_pushes_the_application_signed_and_records_success(): void
    {
        $this->readySetting();
        $site = $this->site();
        $secret = (string) $site->agent_secret_encrypted;

        Http::fake([
            'https://shop.example.test/internal/control/v1/deskron/configure' => Http::response(['ok' => true, 'deskron' => ['configured' => true]], 200),
        ]);

        $this->assertTrue(app(DeskronConfigurer::class)->sync($site));

        Http::assertSent(function (Request $request) use ($secret): bool {
            $timestamp = (string) ($request->header(ControlPlaneAgentContract::HEADER_TIMESTAMP)[0] ?? '');
            $nonce = (string) ($request->header(ControlPlaneAgentContract::HEADER_NONCE)[0] ?? '');
            $signature = (string) ($request->header(ControlPlaneAgentContract::HEADER_SIGNATURE)[0] ?? '');

            return str_ends_with($request->url(), ControlPlaneAgentContract::DESKRON_CONFIGURE_PATH)
                && ControlPlaneAgentSignature::matches($secret, $timestamp, $nonce, $request->body(), $signature)
                && $request['enabled'] === true
                && $request['application_id'] === '01KDESKRONAPP0000000000000'
                && $request['master_key'] === 'dsk_master_key';
        });

        $site->refresh();
        $this->assertNotNull($site->getAttribute('deskron_pushed_at'));
        $this->assertNull($site->getAttribute('deskron_push_failed_at'));
    }

    public function test_configurer_records_a_failed_push(): void
    {
        $this->readySetting();
        $site = $this->site();

        Http::fake([
            'https://shop.example.test/internal/control/v1/deskron/configure' => Http::response('', 404),
        ]);

        $this->assertFalse(app(DeskronConfigurer::class)->sync($site));

        $site->refresh();
        $this->assertNotNull($site->getAttribute('deskron_push_failed_at'));
        $this->assertSame('http_404', $site->getAttribute('deskron_push_error'));
    }

    public function test_an_unready_setting_pushes_disabled(): void
    {
        $site = $this->site();

        Http::fake([
            'https://shop.example.test/internal/control/v1/deskron/configure' => Http::response(['ok' => true], 200),
        ]);

        app(DeskronConfigurer::class)->sync($site);

        Http::assertSent(fn (Request $request): bool => $request['enabled'] === false
            && ! array_key_exists('master_key', $request->data()));
    }

    public function test_saving_settings_queues_a_push_to_every_site(): void
    {
        Queue::fake();

        $this->actingAs($this->superAdminUser())
            ->put(route('ops.deskron.update'), [
                'application_id' => '01KDESKRONAPP0000000000000',
                'api_key' => 'dsk_master_key',
            ])
            ->assertRedirect(route('ops.deskron.edit'));

        Queue::assertPushed(DispatchDeskronPushJob::class);
    }

    public function test_dispatch_job_fans_out_to_sites_with_an_agent_secret(): void
    {
        $this->readySetting();
        $withSecret = $this->site();
        Site::factory()->create(['status' => SiteStatus::Active, 'agent_secret_encrypted' => null]);

        Queue::fake([PushDeskronJob::class]);

        (new DispatchDeskronPushJob)->handle();

        Queue::assertPushed(PushDeskronJob::class, 1);
        Queue::assertPushed(PushDeskronJob::class, fn (PushDeskronJob $job): bool => $job->siteId === (string) $withSecret->id);
        $this->assertNotNull(DeskronSetting::current()->last_pushed_at);
    }

    private function readySetting(): void
    {
        DeskronSetting::query()->create([
            'application_id' => '01KDESKRONAPP0000000000000',
            'api_key' => 'dsk_master_key',
            'webhook_secret' => 'whsec_value',
        ]);
    }

    private function site(): Site
    {
        return Site::factory()->withSecrets()->create([
            'status' => SiteStatus::Active,
            'primary_domain' => 'shop.example.test',
            'agent_base_url' => 'https://shop.example.test',
        ]);
    }

    private function operator(): User
    {
        $user = User::factory()->create();
        $user->assignRole(OpsRole::Operator->value);

        return $user;
    }

    private function superAdminUser(): User
    {
        $user = User::factory()->create();
        $user->assignRole(OpsRole::SuperAdmin->value);

        return $user;
    }
}
