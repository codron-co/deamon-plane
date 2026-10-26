<?php

namespace Tests\Feature\Sites;

use App\Enums\Channel;
use App\Models\CoolifyConnection;
use App\Services\Coolify\CoolifyApplicationService;
use App\Services\Coolify\Dto\CoolifyApplication;
use App\Services\Sites\BuiltImageCommits;
use App\Services\Sites\BuiltImageException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Which commit a Deamon site may run: the newest one its channel's image builder
 * finished (`GET /deployments/applications/{builder}`).
 */
class BuiltImageCommitsTest extends TestCase
{
    use RefreshDatabase;

    private const NEW = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const OLD = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private const FAILED = 'cccccccccccccccccccccccccccccccccccccccc';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config(['ops.deamon.image_builders' => ['alpha' => 'builder-alpha', 'beta' => 'builder-beta']]);
    }

    public function test_latest_is_the_newest_finished_build_whatever_order_coolify_answers_in(): void
    {
        $this->fakeBuilder('builder-beta', [
            $this->row(9, self::OLD, 'finished', minutesAgo: 60),
            $this->row(12, 'HEAD', 'in_progress'),
            $this->row(11, self::NEW, 'finished', minutesAgo: 10),
            $this->row(10, self::FAILED, 'failed', minutesAgo: 30),
            $this->row(13, 'dddddddddddddddddddddddddddddddddddddddd', 'finished', minutesAgo: 5, pullRequest: 4),
        ], wrapped: true);

        $this->assertSame(self::NEW, $this->images()->latest(Channel::Beta, $this->coolify()));

        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && str_contains($request->url(), '/api/v1/deployments/applications/builder-beta')
            && str_contains($request->url(), 'take=20'));
    }

    public function test_main_runs_the_beta_builder_images(): void
    {
        $this->fakeBuilder('builder-beta', [$this->row(5, self::NEW, 'finished', minutesAgo: 10)]);

        $this->assertSame('builder-beta', $this->images()->builderFor(Channel::Main));
        $this->assertSame(self::NEW, $this->images()->latest(Channel::Main, $this->coolify()));
    }

    public function test_a_build_younger_than_the_tag_grace_is_not_used_yet(): void
    {
        $this->fakeBuilder('builder-alpha', [
            $this->row(8, self::NEW, 'finished', minutesAgo: 0),
            $this->row(7, self::OLD, 'finished', minutesAgo: 30),
        ]);

        $this->assertSame(self::OLD, $this->images()->latest(Channel::Alpha, $this->coolify()));
    }

    public function test_only_a_build_being_tagged_is_a_pending_refusal(): void
    {
        $this->fakeBuilder('builder-alpha', [$this->row(8, self::NEW, 'finished', minutesAgo: 0)]);

        try {
            $this->images()->latest(Channel::Alpha, $this->coolify());
            $this->fail('A build still being tagged must not be deployed.');
        } catch (BuiltImageException $exception) {
            $this->assertTrue($exception->pending);
            $this->assertStringContainsString('aaaaaaaa', $exception->getMessage());
        }
    }

    public function test_no_finished_build_fails_clearly(): void
    {
        $this->fakeBuilder('builder-alpha', [
            $this->row(2, 'HEAD', 'in_progress'),
            $this->row(1, self::FAILED, 'failed', minutesAgo: 30),
        ]);

        $this->expectException(BuiltImageException::class);
        $this->expectExceptionMessage(__('site_ops.image.no_build', ['channel' => 'alpha']));

        $this->images()->latest(Channel::Alpha, $this->coolify());
    }

    public function test_an_unreadable_builder_fails_clearly(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Application not found.'], 404)]);

        try {
            $this->images()->latest(Channel::Beta, $this->coolify());
            $this->fail('An unreadable builder must refuse the deploy.');
        } catch (BuiltImageException $exception) {
            $this->assertFalse($exception->pending);
            $this->assertSame(404, $exception->getCode());
            $this->assertStringContainsString('beta', $exception->getMessage());
        }
    }

    public function test_resolve_expands_a_built_short_sha_and_refuses_anything_else(): void
    {
        $this->fakeBuilder('builder-beta', [
            $this->row(11, self::NEW, 'finished', minutesAgo: 10),
            $this->row(10, self::FAILED, 'failed', minutesAgo: 30),
        ]);

        $this->assertSame(self::NEW, $this->images()->resolve(Channel::Beta, 'AAAAAAA', $this->coolify()));

        try {
            $this->images()->resolve(Channel::Beta, substr(self::FAILED, 0, 12), $this->coolify());
            $this->fail('A commit the builder never finished has no image.');
        } catch (BuiltImageException $exception) {
            $this->assertTrue($exception->pending);
        }

        $this->expectException(BuiltImageException::class);
        $this->images()->resolve(Channel::Beta, 'v1.2.85', $this->coolify());
    }

    public function test_a_channel_without_a_builder_keeps_building_its_branch(): void
    {
        config(['ops.deamon.image_builders' => ['alpha' => 'builder-alpha', 'beta' => '']]);

        $this->assertTrue($this->images()->appliesToChannel(Channel::Alpha));
        $this->assertFalse($this->images()->appliesToChannel(Channel::Beta));
        $this->assertFalse($this->images()->appliesToChannel(Channel::Main));
    }

    public function test_pin_body_turns_auto_deploy_off_and_source_commit_on(): void
    {
        $this->assertSame([
            'git_commit_sha' => self::NEW,
            'is_auto_deploy_enabled' => false,
            'include_source_commit_in_build' => true,
        ], BuiltImageCommits::pinBody(self::NEW));
    }

    public function test_pin_body_follows_the_build_pack(): void
    {
        $compose = CoolifyApplication::fromArray(['uuid' => 'a', 'build_pack' => 'dockercompose', 'git_commit_sha' => self::OLD]);
        $image = CoolifyApplication::fromArray([
            'uuid' => 'b',
            'build_pack' => 'dockerimage',
            'git_commit_sha' => 'HEAD',
            'docker_registry_image_name' => '127.0.0.1:5000/deamon-cms',
            'docker_registry_image_tag' => self::OLD,
        ]);

        $this->assertSame(BuiltImageCommits::pinBody(self::NEW), BuiltImageCommits::pinBodyFor($compose, self::NEW));
        $this->assertSame([
            'docker_registry_image_tag' => self::NEW,
            'is_auto_deploy_enabled' => false,
        ], BuiltImageCommits::pinBodyFor($image, self::NEW));

        $this->assertSame(self::OLD, $compose->pinnedCommit());
        $this->assertSame(self::OLD, $image->pinnedCommit());
        $this->assertTrue($image->isDockerImagePack());
        $this->assertFalse($compose->isDockerImagePack());
    }

    public function test_registry_lookup_is_off_without_a_registry_url(): void
    {
        config(['ops.deamon.image_registry.url' => '']);

        $this->assertFalse($this->images()->registryHas(self::NEW));
        Http::assertNothingSent();
    }

    public function test_registry_lookup_asks_the_manifest_of_the_commit_tag(): void
    {
        config(['ops.deamon.image_registry.url' => 'http://registry.test:5000/']);
        Http::fake([
            'http://registry.test:5000/v2/deamon-cms/manifests/'.self::NEW => Http::response('', 200),
            'http://registry.test:5000/*' => Http::response('', 404),
        ]);

        $this->assertTrue($this->images()->registryHas(self::NEW));
        $this->assertFalse($this->images()->registryHas(self::OLD));
        $this->assertFalse($this->images()->registryHas('latest'));

        Http::assertSent(fn (Request $request): bool => $request->method() === 'HEAD'
            && $request->url() === 'http://registry.test:5000/v2/deamon-cms/manifests/'.self::NEW
            && str_contains($request->header('Accept')[0] ?? '', 'application/vnd.docker.distribution.manifest.v2+json'));
        Http::assertSentCount(2);
    }

    public function test_a_docker_image_site_also_accepts_a_commit_already_in_the_registry(): void
    {
        config(['ops.deamon.image_registry.url' => 'http://registry.test:5000']);
        $this->fakeBuilder('builder-beta', [
            $this->row(11, self::NEW, 'finished', minutesAgo: 0),
            $this->row(10, self::OLD, 'finished', minutesAgo: 30),
        ]);
        Http::fake([
            'http://registry.test:5000/v2/deamon-cms/manifests/'.self::NEW => Http::response('', 200),
            'http://registry.test:5000/v2/deamon-cms/manifests/'.self::FAILED => Http::response('', 200),
        ]);

        // Compose site: the tag grace still holds the newest build back.
        $this->assertSame(self::OLD, $this->images()->latest(Channel::Beta, $this->coolify()));
        // Docker Image site: its tag is in the registry already.
        $this->assertSame(self::NEW, $this->images()->latest(Channel::Beta, $this->coolify(), registry: true));
        $this->assertSame(self::FAILED, $this->images()->resolve(Channel::Beta, self::FAILED, $this->coolify(), registry: true));

        $this->expectException(BuiltImageException::class);
        $this->images()->resolve(Channel::Beta, self::FAILED, $this->coolify());
    }

    private function images(): BuiltImageCommits
    {
        return app(BuiltImageCommits::class);
    }

    private function coolify(): CoolifyApplicationService
    {
        return CoolifyApplicationService::forConnection(CoolifyConnection::factory()->create([
            'base_url' => 'https://coolify.example',
            'api_token' => 'builder-token',
        ]));
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function fakeBuilder(string $builder, array $rows, bool $wrapped = false): void
    {
        Http::fake([
            'https://coolify.example/api/v1/deployments/applications/'.$builder.'*' => Http::response(
                $wrapped ? ['count' => count($rows), 'deployments' => $rows] : $rows,
                200,
            ),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(int $id, string $commit, string $status, ?int $minutesAgo = null, int $pullRequest = 0): array
    {
        return [
            'id' => $id,
            'deployment_uuid' => 'build-'.$id,
            'commit' => $commit,
            'status' => $status,
            'pull_request_id' => $pullRequest,
            'created_at' => now()->utc()->subMinutes(($minutesAgo ?? 0) + 3)->toIso8601ZuluString(),
            'finished_at' => $minutesAgo === null ? null : now()->utc()->subMinutes($minutesAgo)->toIso8601ZuluString(),
        ];
    }
}
