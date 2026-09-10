<?php

namespace Tests\Feature\Sites;

use App\Enums\OpsRole;
use App\Enums\SiteStatus;
use App\Models\CoolifyConnection;
use App\Models\Site;
use App\Models\User;
use App\Services\Coolify\Dto\CreateComposeAppRequest;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ComposePackMigrateTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'plane-coolify-ops-token';

    private const APP = 'df-app-1';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Http::preventStrayRequests();
    }

    public function test_dockerfile_to_compose_patches_pack_restores_app_key_skips_db_and_never_deletes(): void
    {
        $site = $this->dockerfileSite();
        $appKey = (string) $site->app_key_encrypted;

        Http::fake(function (Request $request) use ($appKey) {
            $url = $request->url();
            $method = $request->method();

            if ($method === 'GET' && str_ends_with($url, '/applications/'.self::APP)) {
                return Http::response($this->appPayload('dockerfile'), 200);
            }

            if ($method === 'GET' && str_contains($url, '/envs')) {
                return Http::response([
                    ['uuid' => 'env-app-key', 'key' => 'APP_KEY', 'value' => $appKey],
                    ['key' => 'DB_HOST', 'value' => '10.0.0.9'],
                    ['key' => 'DB_PASSWORD', 'value' => 'should-not-copy'],
                    ['key' => 'DEAMON_SITE_NAME', 'value' => 'Legacy'],
                ], 200);
            }

            if ($method === 'PATCH' && str_ends_with($url, '/applications/'.self::APP)) {
                return Http::response($this->appPayload('dockercompose'), 200);
            }

            if (in_array($method, ['POST', 'PATCH'], true) && str_contains($url, '/envs')) {
                return Http::response(['uuid' => 'env-app-key', 'key' => $request->data()['key'] ?? 'APP_KEY'], 200);
            }

            return Http::response(['error' => 'unexpected '.$method.' '.$url], 404);
        });

        $this->actingAs($this->operator())
            ->from(route('ops.sites.show', $site))
            ->post(route('ops.sites.compose', $site))
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertFalse($site->fresh()->hasDockerfileBuildPackWarning());

        Http::assertSent(function (Request $request): bool {
            if ($request->method() !== 'PATCH' || ! str_ends_with($request->url(), '/applications/'.self::APP)) {
                return false;
            }

            $body = $request->data();

            return ($body['build_pack'] ?? null) === 'dockercompose'
                && ($body['docker_compose_location'] ?? null) === CreateComposeAppRequest::DEFAULT_COMPOSE_LOCATION
                && ! array_key_exists('fqdn', $body);
        });

        Http::assertSent(function (Request $request) use ($appKey): bool {
            if (! in_array($request->method(), ['POST', 'PATCH'], true) || ! str_contains($request->url(), '/envs')) {
                return false;
            }

            $body = $request->data();

            return ($body['key'] ?? null) === 'APP_KEY'
                && ($body['value'] ?? null) === $appKey
                && ($body['is_literal'] ?? null) === true
                && ! array_key_exists('is_literally', $body)
                && ! array_key_exists('available_in_services', $body)
                && ! array_key_exists('uuid', $body);
        });

        Http::assertSent(function (Request $request): bool {
            if (! in_array($request->method(), ['POST', 'PATCH'], true) || ! str_contains($request->url(), '/envs')) {
                return false;
            }

            $body = $request->data();

            return ($body['key'] ?? null) === 'DEAMON_SITE_NAME'
                && ($body['is_literal'] ?? null) === true
                && ! array_key_exists('available_in_services', $body);
        });

        Http::assertNotSent(function (Request $request): bool {
            $body = $request->data();

            return ($body['key'] ?? null) === 'DB_HOST' || ($body['key'] ?? null) === 'DB_PASSWORD';
        });

        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE');
    }

    public function test_env_restore_validation_error_is_flash_not_500(): void
    {
        $site = $this->dockerfileSite();

        Http::fake(function (Request $request) {
            $url = $request->url();
            $method = $request->method();

            if ($method === 'GET' && str_ends_with($url, '/applications/'.self::APP)) {
                return Http::response($this->appPayload('dockerfile'), 200);
            }
            if ($method === 'GET' && str_contains($url, '/envs')) {
                return Http::response([
                    ['uuid' => 'env-app-key', 'key' => 'APP_KEY', 'value' => 'base64:keep'],
                ], 200);
            }
            if ($method === 'PATCH' && str_ends_with($url, '/applications/'.self::APP)) {
                return Http::response($this->appPayload('dockercompose'), 200);
            }
            if ($method === 'PATCH' && str_contains($url, '/envs')) {
                return Http::response([
                    'message' => 'Validation failed.',
                    'errors' => [
                        'is_literally' => ['This field is not allowed.'],
                        'available_in_services' => ['This field is not allowed.'],
                        'uuid' => ['This field is not allowed.'],
                    ],
                ], 422);
            }

            return Http::response(['error' => 'unexpected '.$method.' '.$url], 404);
        });

        $this->actingAs($this->operator())
            ->from(route('ops.sites.show', $site))
            ->post(route('ops.sites.compose', $site))
            ->assertRedirect()
            ->assertSessionHas('error');

        $error = session('error');
        $this->assertIsString($error);
        $this->assertStringContainsString('is_literally', $error);
        $this->assertTrue($site->fresh()->hasDockerfileBuildPackWarning());
    }

    public function test_recreate_required_aborts_without_delete(): void
    {
        $site = $this->dockerfileSite();

        Http::fake(function (Request $request) {
            if ($request->method() === 'GET' && str_ends_with($request->url(), '/applications/'.self::APP)) {
                return Http::response($this->appPayload('dockerfile'), 200);
            }
            if ($request->method() === 'GET' && str_contains($request->url(), '/envs')) {
                return Http::response([['key' => 'APP_KEY', 'value' => 'base64:keep']], 200);
            }
            if ($request->method() === 'PATCH' && str_ends_with($request->url(), '/applications/'.self::APP)) {
                return Http::response(['message' => 'This action requires recreate of the application'], 422);
            }

            return Http::response(['error' => 'unexpected '.$request->method().' '.$request->url()], 404);
        });

        $this->actingAs($this->operator())
            ->from(route('ops.sites.show', $site))
            ->post(route('ops.sites.compose', $site))
            ->assertRedirect()
            ->assertSessionHas('error', __('site_ops.pack.recreate_aborted'));

        $this->assertTrue($site->fresh()->hasDockerfileBuildPackWarning());
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE');
        Http::assertNotSent(function (Request $request): bool {
            return in_array($request->method(), ['POST', 'PATCH'], true)
                && str_contains($request->url(), '/envs');
        });
    }

    public function test_already_compose_clears_marker_without_pack_patch(): void
    {
        $site = $this->dockerfileSite();

        Http::fake([
            'https://coolify.example/api/v1/applications/'.self::APP => Http::response($this->appPayload('dockercompose'), 200),
        ]);

        $this->actingAs($this->operator())
            ->post(route('ops.sites.compose', $site))
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertFalse($site->fresh()->hasDockerfileBuildPackWarning());
        Http::assertNotSent(function (Request $request): bool {
            return $request->method() === 'PATCH'
                && str_ends_with($request->url(), '/applications/'.self::APP)
                && ($request->data()['build_pack'] ?? null) === 'dockercompose';
        });
    }

    public function test_bulk_all_dockerfile_converts_marked_sites(): void
    {
        $site = $this->dockerfileSite();

        Http::fake(function (Request $request) {
            $url = $request->url();
            $method = $request->method();

            if ($method === 'GET' && str_ends_with($url, '/applications/'.self::APP)) {
                return Http::response($this->appPayload('dockerfile'), 200);
            }
            if ($method === 'GET' && str_contains($url, '/envs')) {
                return Http::response([['key' => 'APP_KEY', 'value' => 'base64:keep']], 200);
            }
            if ($method === 'PATCH' && str_ends_with($url, '/applications/'.self::APP)) {
                return Http::response($this->appPayload('dockercompose'), 200);
            }
            if (in_array($method, ['POST', 'PATCH'], true) && str_contains($url, '/envs')) {
                return Http::response(['key' => 'APP_KEY'], 200);
            }

            return Http::response(['error' => 'unexpected '.$method.' '.$url], 404);
        });

        $this->actingAs($this->operator())
            ->from(route('ops.sites'))
            ->post(route('ops.sites.bulk.compose'), ['all_dockerfile' => '1'])
            ->assertRedirect();

        $this->assertFalse($site->fresh()->hasDockerfileBuildPackWarning());
    }

    public function test_viewer_cannot_migrate(): void
    {
        $site = $this->dockerfileSite();
        $viewer = User::factory()->create();
        $viewer->assignRole(OpsRole::Viewer->value);

        $this->actingAs($viewer)
            ->post(route('ops.sites.compose', $site))
            ->assertForbidden();
    }

    public function test_show_and_index_render_compose_actions(): void
    {
        $site = $this->dockerfileSite();

        Http::fake([
            'https://coolify.example/api/v1/applications/'.self::APP => Http::response($this->appPayload('dockerfile', autoDeploy: true), 200),
        ]);

        $this->actingAs($this->operator())
            ->get(route('ops.sites.show', $site))
            ->assertOk()
            ->assertSee(__('site_ops.pack.button'), false)
            ->assertSee(__('site_ops.pack.warning'), false)
            ->assertSee(route('ops.sites.compose', $site), false);

        $html = $this->actingAs($this->operator())
            ->get(route('ops.sites'))
            ->assertOk()
            ->assertSee(__('site_ops.bulk.compose'), false)
            ->assertDontSee(__('site_ops.bulk.compose_all'), false)
            ->getContent();

        $this->assertStringContainsString('data-confirm=', $html);
        $this->assertDoesNotMatchRegularExpression('/href="[^"]*\/sites\/bulk\//', $html);
    }

    /**
     * @return array<string, mixed>
     */
    private function appPayload(string $pack, bool $autoDeploy = false): array
    {
        return [
            'uuid' => self::APP,
            'name' => 'Legacy',
            'build_pack' => $pack,
            'git_branch' => 'main',
            'is_auto_deploy' => $autoDeploy,
            'git_commit_sha' => null,
        ];
    }

    private function dockerfileSite(): Site
    {
        $connection = CoolifyConnection::factory()->create([
            'base_url' => 'https://coolify.example',
            'api_token' => self::TOKEN,
        ]);

        return Site::factory()->dockerfilePack()->withSecrets()->create([
            'status' => SiteStatus::Active,
            'coolify_app_uuid' => self::APP,
            'coolify_connection_id' => $connection->id,
        ]);
    }

    private function operator(): User
    {
        $operator = User::factory()->create();
        $operator->assignRole(OpsRole::Operator->value);

        return $operator;
    }
}
