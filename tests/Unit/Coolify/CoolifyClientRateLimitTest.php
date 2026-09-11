<?php

namespace Tests\Unit\Coolify;

use App\Services\Coolify\CoolifyApiException;
use App\Services\Coolify\CoolifyClient;
use App\Services\Coolify\CoolifyCredentials;
use App\Services\Coolify\CoolifyRateGuard;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class CoolifyClientRateLimitTest extends TestCase
{
    private const TOKEN = 'test-coolify-token';

    private const BASE = 'https://coolify.test';

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
        Http::preventStrayRequests();
        config()->set('ops.coolify.retry.max_attempts', 3);
        config()->set('ops.coolify.rate.min_interval_ms', 0);
    }

    public function test_a_throttled_get_is_retried_and_then_succeeds(): void
    {
        Http::fake([
            'https://coolify.test/api/v1/applications*' => Http::sequence()
                ->push(['message' => 'Too Many Attempts.'], 429, ['Retry-After' => '2'])
                ->push([['uuid' => 'app-1', 'name' => 'Susa']], 200),
        ]);

        $apps = $this->client()->listApps();

        $this->assertCount(1, $apps);
        Http::assertSentCount(2);
        Sleep::assertSlept(fn ($duration): bool => $duration->totalMilliseconds >= 2000, 1);
    }

    public function test_exhausted_throttle_throws_a_localized_error_not_the_remote_english(): void
    {
        Http::fake([
            'https://coolify.test/api/v1/applications*' => Http::response(
                ['message' => 'Too Many Attempts.'],
                429,
            ),
        ]);

        try {
            $this->client()->listApps();
            $this->fail('Expected CoolifyApiException.');
        } catch (CoolifyApiException $exception) {
            $this->assertTrue($exception->isRateLimited());
            $this->assertTrue($exception->isTransient());
            $this->assertStringNotContainsStringIgnoringCase('Too Many Attempts', $exception->getMessage());
            $this->assertSame(__('coolify.errors.rate_limited'), $exception->getMessage());
        }

        Http::assertSentCount(3);
    }

    public function test_turkish_locale_surfaces_turkish_rate_limit_copy(): void
    {
        $this->app->setLocale('tr');

        Http::fake([
            'https://coolify.test/api/v1/applications*' => Http::response(['message' => 'Too Many Attempts.'], 429),
        ]);

        try {
            $this->client()->listApps();
            $this->fail('Expected CoolifyApiException.');
        } catch (CoolifyApiException $exception) {
            $this->assertStringContainsString('istek sınırına takıldı', $exception->getMessage());
        }
    }

    public function test_a_throttled_deploy_post_is_retried_because_nothing_ran_remotely(): void
    {
        Http::fake([
            'https://coolify.test/api/v1/deploy*' => Http::sequence()
                ->push(['message' => 'Too Many Attempts.'], 429)
                ->push(['deployments' => [['deployment_uuid' => 'dep-1']]], 200),
        ]);

        $result = $this->client()->deploy('app-1');

        $this->assertSame('dep-1', $result->firstDeploymentUuid());
        Http::assertSentCount(2);
    }

    public function test_a_failing_deploy_post_is_never_replayed(): void
    {
        Http::fake([
            'https://coolify.test/api/v1/deploy*' => Http::response(['message' => 'boom'], 500),
        ]);

        $this->expectException(CoolifyApiException::class);

        try {
            $this->client()->deploy('app-1');
        } finally {
            // Replaying a deploy could start a second build.
            Http::assertSentCount(1);
        }
    }

    public function test_a_gateway_error_on_a_read_is_retried(): void
    {
        Http::fake([
            'https://coolify.test/api/v1/applications/app-1' => Http::sequence()
                ->push(['message' => 'bad gateway'], 503)
                ->push(['uuid' => 'app-1', 'build_pack' => 'dockercompose'], 200),
        ]);

        $app = $this->client()->getApp('app-1');

        $this->assertSame('app-1', $app->uuid);
        Http::assertSentCount(2);
    }

    public function test_a_client_error_is_not_retried(): void
    {
        Http::fake([
            'https://coolify.test/api/v1/applications/app-1' => Http::response(['message' => 'nope'], 404),
        ]);

        try {
            $this->client()->getApp('app-1');
            $this->fail('Expected CoolifyApiException.');
        } catch (CoolifyApiException $exception) {
            $this->assertTrue($exception->isNotFound());
            $this->assertFalse($exception->isTransient());
        }

        Http::assertSentCount(1);
    }

    public function test_the_raw_remote_body_is_still_available_for_logs(): void
    {
        Http::fake([
            'https://coolify.test/api/v1/applications*' => Http::response(['message' => 'Too Many Attempts.'], 429),
        ]);

        try {
            $this->client()->listApps();
            $this->fail('Expected CoolifyApiException.');
        } catch (CoolifyApiException $exception) {
            $this->assertSame('Too Many Attempts.', $exception->payload['message'] ?? null);
        }
    }

    public function test_a_throttle_puts_the_whole_host_on_cooldown(): void
    {
        Http::fake([
            'https://coolify.test/api/v1/applications*' => Http::sequence()
                ->push(['message' => 'Too Many Attempts.'], 429, ['Retry-After' => '3'])
                ->push([], 200),
        ]);

        $this->client()->listApps();

        $guard = app(CoolifyRateGuard::class);
        $this->assertGreaterThan(0.0, $guard->cooldownRemaining(self::BASE.'/api/v1'));
    }

    private function client(): CoolifyClient
    {
        return new CoolifyClient(new CoolifyCredentials(self::BASE, self::TOKEN));
    }
}
