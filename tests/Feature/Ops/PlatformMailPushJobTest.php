<?php

namespace Tests\Feature\Ops;

use App\Jobs\PushPlatformMailJob;
use App\Models\Site;
use App\Services\Mail\PlatformMailConfigurer;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

class PlatformMailPushJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_releases_its_unique_lock_before_processing(): void
    {
        $this->assertInstanceOf(
            ShouldBeUniqueUntilProcessing::class,
            new PushPlatformMailJob('site-id'),
        );
    }

    public function test_job_logs_and_throws_when_sync_fails(): void
    {
        $site = Site::factory()->withSecrets()->create([
            'agent_base_url' => 'https://mail-sync.example.test',
        ]);

        Http::fake([
            'https://mail-sync.example.test/*' => Http::response([], 500),
        ]);
        Log::spy();

        try {
            (new PushPlatformMailJob((string) $site->id))
                ->handle(app(PlatformMailConfigurer::class));
            $this->fail('The job should throw when platform mail sync fails.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Platform mail sync failed for site '.$site->id.'.',
                $exception->getMessage(),
            );
        }

        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $message, array $context): bool => $message === 'Queued platform mail sync failed'
                && ($context['site_id'] ?? null) === $site->id
                && ($context['site_slug'] ?? null) === $site->slug
                && ($context['http_status'] ?? null) === 500,
        );
    }
}
