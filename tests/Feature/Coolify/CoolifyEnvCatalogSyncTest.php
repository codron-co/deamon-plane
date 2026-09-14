<?php

namespace Tests\Feature\Coolify;

use App\Enums\Channel;
use App\Jobs\SyncCoolifyEnvCatalogJob;
use App\Models\CoolifyEnvCatalogSource;
use App\Models\CoolifyEnvDefault;
use App\Models\GithubSetting;
use App\Services\Coolify\EnvCatalog\CoolifyEnvCatalogException;
use App\Services\Coolify\EnvCatalog\CoolifyEnvCatalogSync;
use App\Services\Coolify\EnvCatalog\DeamonRepo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CoolifyEnvCatalogSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_deamon_repo_full_name_is_parsed_from_the_configured_repository(): void
    {
        config(['ops.deamon.repository' => 'https://github.com/codron-co/deamon.git']);
        $this->assertSame('codron-co/deamon', DeamonRepo::fullName());
        $this->assertSame('codron-co', DeamonRepo::owner());

        config(['ops.deamon.repository' => 'git@github.com:codron-co/deamon.git']);
        $this->assertSame('codron-co/deamon', DeamonRepo::fullName());

        config(['ops.deamon.repository' => 'https://gitlab.com/x/y.git']);
        $this->assertNull(DeamonRepo::fullName());
    }

    public function test_sync_replaces_only_the_requested_channel_and_records_the_source(): void
    {
        GithubSetting::query()->create(['token' => 'ghp-settings-pat']);
        $this->fakeGithub("#@secret\nAPP_KEY={{site.app_key}}\nDB_PASSWORD={{generated}}\n", 'deadbeefcafe');

        $source = app(CoolifyEnvCatalogSync::class)->sync(Channel::Alpha);

        $this->assertSame('codron-co/deamon', $source->repo_full_name);
        $this->assertSame('.env.production.example', $source->path);
        $this->assertSame('deadbeefcafe', $source->commit_sha);
        $this->assertSame(2, $source->row_count);
        $this->assertNotNull($source->fetched_at);
        $this->assertSame(2, CoolifyEnvDefault::query()->forChannel(Channel::Alpha)->count());
        $this->assertGreaterThan(2, CoolifyEnvDefault::query()->forChannel(Channel::Main)->count());

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/contents/.env.production.example')
            && ($request['ref'] ?? null) === 'alpha');
    }

    public function test_missing_file_records_error_and_keeps_existing_rows(): void
    {
        GithubSetting::query()->create(['token' => 'ghp-settings-pat']);
        Http::fake([
            'api.github.com/repos/codron-co/deamon/contents/*' => Http::response(['message' => 'Not Found'], 404),
        ]);
        $before = CoolifyEnvDefault::query()->forChannel(Channel::Main)->count();

        try {
            app(CoolifyEnvCatalogSync::class)->sync(Channel::Main);
            $this->fail('Expected CoolifyEnvCatalogException');
        } catch (CoolifyEnvCatalogException $exception) {
            $this->assertStringContainsString('.env.production.example', $exception->getMessage());
        }

        $this->assertSame($before, CoolifyEnvDefault::query()->forChannel(Channel::Main)->count());
        $this->assertStringContainsString('.env.production.example', (string) CoolifyEnvCatalogSource::forChannel(Channel::Main)->last_error);
    }

    public function test_cms_repo_push_webhook_queues_a_catalog_sync_for_that_branch(): void
    {
        Queue::fake();
        GithubSetting::query()->create(['webhook_secret' => 'hook-secret']);

        $payload = json_encode([
            'ref' => 'refs/heads/beta',
            'after' => 'abc123',
            'repository' => ['full_name' => 'codron-co/deamon'],
        ]);

        $this->call('POST', '/webhooks/github', [], [], [], [
            'HTTP_X_GITHUB_EVENT' => 'push',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', (string) $payload, 'hook-secret'),
            'CONTENT_TYPE' => 'application/json',
        ], (string) $payload)->assertOk()->assertJsonPath('updated', true);

        Queue::assertPushed(SyncCoolifyEnvCatalogJob::class, fn (SyncCoolifyEnvCatalogJob $job): bool => $job->channel === 'beta');
    }

    public function test_cms_repo_push_to_unknown_branch_or_other_repo_does_not_queue(): void
    {
        Queue::fake();
        GithubSetting::query()->create(['webhook_secret' => 'hook-secret']);

        foreach ([
            ['ref' => 'refs/heads/develop', 'repository' => ['full_name' => 'codron-co/deamon']],
            ['ref' => 'refs/heads/main', 'repository' => ['full_name' => 'deamon-themes/deamon-theme-x']],
        ] as $body) {
            $payload = (string) json_encode($body);
            $this->call('POST', '/webhooks/github', [], [], [], [
                'HTTP_X_GITHUB_EVENT' => 'push',
                'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $payload, 'hook-secret'),
                'CONTENT_TYPE' => 'application/json',
            ], $payload)->assertOk();
        }

        Queue::assertNotPushed(SyncCoolifyEnvCatalogJob::class);
    }

    public function test_job_syncs_the_channel(): void
    {
        GithubSetting::query()->create(['token' => 'ghp-settings-pat']);
        $this->fakeGithub("ONLY_KEY={{generated}}\n", 'feedface');

        (new SyncCoolifyEnvCatalogJob('main'))->handle(app(CoolifyEnvCatalogSync::class));

        $this->assertSame(['ONLY_KEY'], CoolifyEnvDefault::query()->forChannel(Channel::Main)->pluck('key')->all());
    }

    public function test_artisan_command_syncs_all_allowed_channels(): void
    {
        GithubSetting::query()->create(['token' => 'ghp-settings-pat']);
        $this->fakeGithub("CMD_KEY={{generated}}\n", 'c0ffee');

        $this->artisan('ops:sync-env-catalog')->assertSuccessful();

        foreach (Channel::cases() as $channel) {
            $this->assertSame(['CMD_KEY'], CoolifyEnvDefault::query()->forChannel($channel)->pluck('key')->all());
        }
    }

    private function fakeGithub(string $example, string $sha): void
    {
        Http::fake([
            'api.github.com/repos/codron-co/deamon/contents/.env.production.example*' => Http::response([
                'type' => 'file',
                'encoding' => 'base64',
                'content' => base64_encode($example),
            ], 200),
            'api.github.com/repos/codron-co/deamon/commits*' => Http::response([['sha' => $sha]], 200),
        ]);
    }
}
