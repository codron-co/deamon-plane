<?php

namespace Tests\Feature\Databases;

use App\Enums\Channel;
use App\Enums\SiteStatus;
use App\Models\CoolifySetting;
use App\Models\RedisServer;
use App\Models\Site;
use App\Services\Databases\RedisServerClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SiteRedisCommandTest extends TestCase
{
    use RefreshDatabase;

    private const REDIS_PASSWORD = 'site-redis-password-00000000000000000001';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        CoolifySetting::factory()->create(['base_url' => 'https://coolify.test', 'api_token' => 'test-coolify-token']);
    }

    public function test_status_of_a_local_site_prints_todays_defaults_and_target_names(): void
    {
        $this->site();

        $out = $this->ops('status');

        $this->assertStringContainsString("SITE=izyem\n", $out);
        $this->assertStringContainsString("APP_UUID=redis-app-1\n", $out);
        $this->assertStringContainsString("SHARED=no\n", $out);
        $this->assertStringContainsString("REDIS_HOST=redis\n", $out);
        $this->assertStringContainsString("REDIS_PORT=6379\n", $out);
        $this->assertStringContainsString("REDIS_USERNAME=\n", $out);
        $this->assertStringContainsString("REDIS_PASSWORD=\n", $out);
        $this->assertStringContainsString("REDIS_DB=0\n", $out);
        $this->assertStringContainsString("REDIS_CACHE_DB=1\n", $out);
        $this->assertStringContainsString("REDIS_PREFIX=deamon-database-\n", $out);
        $this->assertStringContainsString("TARGET_DB=\n", $out);
        $this->assertStringContainsString("TARGET_USERNAME=site_izyem\n", $out);
        $this->assertStringContainsString("PREFIX=site_izyem:\n", $out);
    }

    public function test_status_masks_the_password_unless_asked(): void
    {
        $this->sharedSite();

        $out = $this->ops('status');
        $this->assertStringContainsString("SHARED=yes\n", $out);
        $this->assertStringContainsString("REDIS_HOST=deamon-redis\n", $out);
        $this->assertStringContainsString("REDIS_PASSWORD=***\n", $out);
        $this->assertStringContainsString("TARGET_PASSWORD=***\n", $out);
        $this->assertStringContainsString("TARGET_DB=4\n", $out);
        $this->assertStringContainsString("TARGET_CACHE_DB=5\n", $out);
        $this->assertStringNotContainsString(self::REDIS_PASSWORD, $out);

        $secret = $this->ops('status', ['--show-secret' => true]);
        $this->assertStringContainsString('REDIS_PASSWORD='.self::REDIS_PASSWORD."\n", $secret);
    }

    public function test_status_with_keys_counts_the_sites_keys(): void
    {
        $this->sharedSite();
        $this->mock(RedisServerClient::class)->shouldReceive('siteKeyCounts')->once()->andReturn(['db' => 12, 'cache_db' => 340]);

        $out = $this->ops('status', ['--keys' => true]);

        $this->assertStringContainsString("KEYS_DB=12\n", $out);
        $this->assertStringContainsString("KEYS_CACHE_DB=340\n", $out);
    }

    public function test_prepare_prints_the_pair_and_leaves_the_site_local(): void
    {
        RedisServer::factory()->default()->create();
        $site = $this->site();
        $this->mock(RedisServerClient::class)->shouldReceive('ensureSiteUser')->once();

        $out = $this->ops('prepare');

        $this->assertStringContainsString("HOST=deamon-redis\n", $out);
        $this->assertStringContainsString("DB=2\n", $out);
        $this->assertStringContainsString("CACHE_DB=3\n", $out);
        $this->assertStringContainsString("USERNAME=site_izyem\n", $out);
        $this->assertStringContainsString("PREFIX=site_izyem:\n", $out);
        $this->assertFalse($site->refresh()->usesSharedRedis());
    }

    public function test_prepare_without_a_server_fails(): void
    {
        $this->site();

        $this->assertSame(1, Artisan::call('ops:site-redis', ['action' => 'prepare', 'site' => 'izyem']));
    }

    public function test_switch_masks_the_password_and_no_restart_skips_the_restart(): void
    {
        $server = RedisServer::factory()->default()->create();
        $site = $this->site();
        $site->forceFill([
            'redis_server_id' => $server->id, 'redis_db' => 4, 'redis_username' => 'site_izyem',
            'redis_password_encrypted' => self::REDIS_PASSWORD,
        ])->save();
        $this->fakeCoolify();

        $out = $this->ops('switch', ['--no-restart' => true]);

        $this->assertStringContainsString("REDIS_HOST=deamon-redis\n", $out);
        $this->assertStringContainsString("REDIS_PASSWORD=***\n", $out);
        $this->assertTrue($site->refresh()->usesSharedRedis());
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/restart'));
    }

    public function test_local_switches_back(): void
    {
        $site = $this->sharedSite();
        $this->fakeCoolify();

        $out = $this->ops('local');

        $this->assertStringContainsString("REDIS_HOST=redis\n", $out);
        $this->assertStringContainsString("REDIS_PREFIX=deamon-database-\n", $out);
        $this->assertFalse($site->refresh()->usesSharedRedis());
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/restart'));
    }

    public function test_flush_clears_only_the_cache_db_and_is_audited(): void
    {
        $site = $this->sharedSite();
        $this->mock(RedisServerClient::class)->shouldReceive('flushSite')->once()
            ->withArgs(fn (Site $target, bool $bothDbs): bool => $target->is($site) && $bothDbs === false)->andReturn(57);

        $out = $this->ops('flush');

        $this->assertStringContainsString("DELETED=57\n", $out);
        $audit = $site->auditLogs()->where('action', 'site.redis_flushed')->sole()->after;
        $this->assertSame(57, $audit['deleted']);
        $this->assertSame('cache', $audit['scope']);
    }

    public function test_flush_all_is_refused_while_the_site_uses_the_shared_redis(): void
    {
        $this->sharedSite();
        $this->mock(RedisServerClient::class)->shouldNotReceive('flushSite');

        $this->assertSame(1, Artisan::call('ops:site-redis', ['action' => 'flush', 'site' => 'izyem', '--all' => true]));
        $this->assertStringContainsString('sessions and queued jobs', Artisan::output());
    }

    public function test_flush_all_empties_the_pair_of_a_site_not_yet_on_it(): void
    {
        $site = $this->sharedSite();
        $site->forceFill(['redis_shared' => false])->save();
        $this->mock(RedisServerClient::class)->shouldReceive('flushSite')->once()
            ->withArgs(fn (Site $target, bool $bothDbs): bool => $target->is($site) && $bothDbs === true)->andReturn(9);

        $out = $this->ops('flush', ['--all' => true]);

        $this->assertStringContainsString("DELETED=9\n", $out);
        $this->assertSame('all', $site->auditLogs()->where('action', 'site.redis_flushed')->sole()->after['scope']);
    }

    public function test_unknown_action_and_site_are_rejected(): void
    {
        $this->site();

        $this->assertSame(2, Artisan::call('ops:site-redis', ['action' => 'nuke', 'site' => 'izyem']));
        $this->assertSame(1, Artisan::call('ops:site-redis', ['action' => 'status', 'site' => 'nope']));
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function ops(string $action, array $options = []): string
    {
        $code = Artisan::call('ops:site-redis', ['action' => $action, 'site' => 'izyem'] + $options);
        $out = str_replace("\r\n", "\n", Artisan::output());
        $this->assertSame(0, $code, $out);

        return $out;
    }

    private function fakeCoolify(): void
    {
        Http::fake(function (Request $request) {
            if ($request->method() === 'GET' && str_contains($request->url(), '/envs')) {
                return Http::response([], 200);
            }

            return Http::response(['message' => 'ok'], 200);
        });
    }

    private function site(): Site
    {
        return Site::factory()->create([
            'slug' => 'izyem',
            'status' => SiteStatus::Active,
            'channel' => Channel::Main,
            'coolify_app_uuid' => 'redis-app-1',
        ]);
    }

    private function sharedSite(): Site
    {
        $server = RedisServer::factory()->default()->create();
        $site = $this->site();
        $site->forceFill([
            'redis_server_id' => $server->id,
            'redis_db' => 4,
            'redis_username' => 'site_izyem',
            'redis_password_encrypted' => self::REDIS_PASSWORD,
            'redis_shared' => true,
        ])->save();

        return $site->refresh();
    }
}
