<?php

namespace Tests\Feature\Mail;

use App\Enums\Channel;
use App\Enums\OpsRole;
use App\Enums\SiteStatus;
use App\Jobs\PushPlatformMailJob;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SitePlatformMailOverrideTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Http::preventStrayRequests();
    }

    public function test_saving_site_override_queues_the_push_instead_of_waiting_on_the_cms(): void
    {
        Queue::fake();
        Http::fake();
        $site = Site::factory()->withSecrets()->create([
            'status' => SiteStatus::Active,
            'channel' => Channel::Main,
            'primary_domain' => 'shop.example.test',
            'agent_base_url' => 'https://shop.example.test',
        ]);

        $this->actingAs($this->operator())
            ->from(route('ops.sites.show', $site))
            ->post(route('ops.sites.platform-mail', $site), [
                'platform_mail_recipient' => 'owner@example.test',
                'notifications' => ['site_down' => ['enabled' => '1']],
            ])
            ->assertRedirect(route('ops.sites.show', $site))
            ->assertSessionHas('status', __('platform_mail.flash.site_saved').' '.__('platform_mail.flash.site_push_queued'));

        $site->refresh();
        $this->assertSame('owner@example.test', $site->platform_mail_recipient);
        $this->assertTrue((bool) ($site->platform_notification_overrides['site_down']['enabled'] ?? false));

        Queue::assertPushed(PushPlatformMailJob::class, fn (PushPlatformMailJob $job): bool => $job->siteId === (string) $site->id);
        Http::assertNothingSent();
        $this->assertDatabaseHas('audit_logs', ['action' => 'platform_mail.site_override_updated', 'subject_id' => $site->id]);
    }

    public function test_site_without_agent_secret_saves_and_says_so_without_queueing(): void
    {
        Queue::fake();
        Http::fake();
        $site = Site::factory()->create([
            'status' => SiteStatus::Active,
            'primary_domain' => 'shop.example.test',
            'agent_secret_encrypted' => null,
        ]);

        $this->actingAs($this->operator())
            ->post(route('ops.sites.platform-mail', $site), ['platform_mail_recipient' => 'owner@example.test'])
            ->assertRedirect(route('ops.sites.show', $site))
            ->assertSessionHas('status', __('platform_mail.flash.site_saved').' '.__('mail.flash.needs_secret'));

        $this->assertSame('owner@example.test', $site->fresh()->platform_mail_recipient);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_viewer_cannot_save_site_override(): void
    {
        Queue::fake();
        $site = Site::factory()->create();
        $viewer = User::factory()->create();
        $viewer->assignRole(OpsRole::Viewer->value);

        $this->actingAs($viewer)
            ->post(route('ops.sites.platform-mail', $site), ['platform_mail_recipient' => 'owner@example.test'])
            ->assertForbidden();

        Queue::assertNothingPushed();
    }

    private function operator(): User
    {
        $user = User::factory()->create();
        $user->assignRole(OpsRole::Operator->value);

        return $user;
    }
}
