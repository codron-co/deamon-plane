<?php

namespace Tests\Feature\Ops;

use App\Enums\SiteStatus;
use App\Jobs\PushDeskronJob;
use App\Jobs\ReconcileDeskronPushJob;
use App\Models\DeskronSetting;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ReconcileDeskronPushTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_retries_a_site_the_push_never_reached(): void
    {
        $this->readySetting();
        Queue::fake();

        $site = $this->site(['deskron_pushed_at' => null, 'deskron_push_failed_at' => null]);

        (new ReconcileDeskronPushJob)->handle();

        Queue::assertPushed(
            PushDeskronJob::class,
            fn (PushDeskronJob $job): bool => $job->siteId === (string) $site->id,
        );
    }

    public function test_it_retries_a_site_whose_last_push_failed(): void
    {
        $this->readySetting();
        Queue::fake();

        // Answered 405 before it had the configure route, an hour after it was
        // last configured successfully.
        $site = $this->site([
            'deskron_pushed_at' => now()->subDay(),
            'deskron_push_failed_at' => now()->subHour(),
            'deskron_push_error' => 'http_405',
        ]);

        (new ReconcileDeskronPushJob)->handle();

        Queue::assertPushed(
            PushDeskronJob::class,
            fn (PushDeskronJob $job): bool => $job->siteId === (string) $site->id,
        );
    }

    public function test_it_leaves_a_configured_site_alone(): void
    {
        $this->readySetting();
        Queue::fake();

        $this->site([
            'deskron_pushed_at' => now(),
            'deskron_push_failed_at' => now()->subDay(),
        ]);

        (new ReconcileDeskronPushJob)->handle();

        Queue::assertNotPushed(PushDeskronJob::class);
    }

    public function test_it_does_nothing_until_the_key_is_set_here(): void
    {
        Queue::fake();

        $this->site(['deskron_pushed_at' => null]);

        (new ReconcileDeskronPushJob)->handle();

        Queue::assertNotPushed(PushDeskronJob::class);
    }

    public function test_it_skips_a_site_with_no_agent_secret(): void
    {
        $this->readySetting();
        Queue::fake();

        Site::factory()->create([
            'status' => SiteStatus::Active,
            'primary_domain' => 'nosecret.example.test',
            'agent_base_url' => 'https://nosecret.example.test',
            'deskron_pushed_at' => null,
        ]);

        (new ReconcileDeskronPushJob)->handle();

        Queue::assertNotPushed(PushDeskronJob::class);
    }

    private function readySetting(): void
    {
        DeskronSetting::query()->create([
            'application_id' => '01KDESKRONAPP0000000000000',
            'api_key' => 'dsk_master_key',
            'webhook_secret' => 'whsec_value',
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function site(array $attributes = []): Site
    {
        return Site::factory()->withSecrets()->create([
            'status' => SiteStatus::Active,
            'primary_domain' => 'shop.example.test',
            'agent_base_url' => 'https://shop.example.test',
            ...$attributes,
        ]);
    }
}
