<?php

namespace Tests\Feature\Mail;

use App\Jobs\SendOpsNotificationJob;
use App\Models\OpsNotification;
use App\Models\PlatformMailSetting;
use App\Models\Site;
use App\Models\User;
use App\Services\Mail\PlatformNotificationCatalog;
use App\Services\Mail\PlatformOpsMailer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class OpsNotificationOutboxTest extends TestCase
{
    use RefreshDatabase;

    public function test_send_queues_one_row_and_one_job_per_recipient(): void
    {
        Queue::fake();
        $site = Site::factory()->create();
        User::factory()->create(['email' => 'first@example.test']);
        User::factory()->create(['email' => 'second@example.test']);
        $this->readySettings('owner@example.test');

        $this->assertTrue(app(PlatformOpsMailer::class)->send($site, PlatformNotificationCatalog::SITE_DOWN, 'Down', 'Body'));

        $rows = OpsNotification::query()->orderBy('id')->get();
        $this->assertSame(
            ['owner@example.test', 'first@example.test', 'second@example.test'],
            $rows->pluck('recipient')->all(),
        );
        $this->assertTrue($rows->every(fn (OpsNotification $row): bool => $row->status === OpsNotification::STATUS_PENDING));
        $this->assertSame('['.$site->name.'] Down', $rows->first()->subject);
        Queue::assertPushed(SendOpsNotificationJob::class, 3);
    }

    public function test_delivered_rows_are_marked_sent(): void
    {
        Mail::fake();
        $site = Site::factory()->create();
        User::factory()->create(['email' => 'ops@example.test']);
        $this->readySettings();

        app(PlatformOpsMailer::class)->send($site, PlatformNotificationCatalog::SITE_DOWN, 'Down', 'Body');

        $row = OpsNotification::query()->sole();
        $this->assertSame(OpsNotification::STATUS_SENT, $row->status);
        $this->assertSame(1, $row->attempts);
        $this->assertNotNull($row->sent_at);
    }

    public function test_nothing_is_queued_when_platform_mail_is_not_ready(): void
    {
        Queue::fake();
        $site = Site::factory()->create();
        User::factory()->create(['email' => 'ops@example.test']);

        $this->assertFalse(app(PlatformOpsMailer::class)->send($site, PlatformNotificationCatalog::SITE_DOWN, 'Down', 'Body'));

        $this->assertSame(0, OpsNotification::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_old_rows_are_prunable(): void
    {
        $site = Site::factory()->create();
        $old = OpsNotification::query()->create([
            'site_id' => $site->id,
            'notification_key' => PlatformNotificationCatalog::SITE_DOWN,
            'recipient' => 'ops@example.test',
            'subject' => 'Old',
            'body' => 'Old',
            'status' => OpsNotification::STATUS_SENT,
        ]);
        $old->forceFill(['created_at' => now()->subDays(OpsNotification::RETENTION_DAYS + 1)])->save();

        $this->artisan('model:prune', ['--model' => [OpsNotification::class]])->assertSuccessful();

        $this->assertSame(0, OpsNotification::query()->count());
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
