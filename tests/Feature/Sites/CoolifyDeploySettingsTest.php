<?php

namespace Tests\Feature\Sites;

use App\Enums\OpsRole;
use App\Enums\SiteStatus;
use App\Models\CoolifyConnection;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CoolifyDeploySettingsTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'plane-coolify-ops-token';

    private const APP = 'pin-app-1';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Http::preventStrayRequests();
    }

    public function test_auto_deploy_patch_does_not_send_fqdn_or_delete(): void
    {
        $site = $this->site();

        Http::fake([
            'https://coolify.example/api/v1/applications/'.self::APP => Http::response($this->appPayload(), 200),
        ]);

        $this->actingAs($this->operator())
            ->post(route('ops.sites.auto-deploy', $site), ['enabled' => '1'])
            ->assertRedirect()
            ->assertSessionHas('status');

        Http::assertSent(function (Request $request): bool {
            $body = $request->data();

            return $request->method() === 'PATCH'
                && str_ends_with($request->url(), '/applications/'.self::APP)
                && ($body['is_auto_deploy_enabled'] ?? null) === true
                && ! array_key_exists('is_auto_deploy', $body)
                && ! array_key_exists('fqdn', $body);
        });
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE');
    }

    public function test_pin_sets_sha_turns_auto_deploy_off_and_deploys(): void
    {
        $site = $this->site();

        Http::fake(function (Request $request) {
            if ($sync = $this->coolifyEnvSyncResponse($request)) {
                return $sync;
            }
            if ($request->method() === 'PATCH' && str_ends_with($request->url(), '/applications/'.self::APP)) {
                return Http::response($this->appPayload(sha: 'abc1234', autoDeploy: false), 200);
            }
            if ($request->method() === 'POST' && str_contains($request->url(), '/deploy')) {
                return Http::response(['deployments' => [['deployment_uuid' => 'dep-1']]], 200);
            }

            return Http::response(['error' => 'unexpected'], 404);
        });

        $this->actingAs($this->operator())
            ->post(route('ops.sites.pin', $site), ['ref' => 'abc1234'])
            ->assertRedirect()
            ->assertSessionHas('status');

        Http::assertSent(function (Request $request): bool {
            $body = $request->data();

            return $request->method() === 'PATCH'
                && ($body['git_commit_sha'] ?? null) === 'abc1234'
                && ($body['is_auto_deploy_enabled'] ?? null) === false
                && ! array_key_exists('is_auto_deploy', $body)
                && ! array_key_exists('fqdn', $body);
        });
        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'POST'
                && str_contains($request->url(), '/deploy')
                && str_contains($request->url(), 'uuid='.self::APP);
        });
    }

    public function test_follow_head_clears_pin_enables_auto_deploy_and_deploys(): void
    {
        $site = $this->site();

        Http::fake(function (Request $request) {
            if ($sync = $this->coolifyEnvSyncResponse($request)) {
                return $sync;
            }
            if ($request->method() === 'PATCH') {
                return Http::response($this->appPayload(sha: '', autoDeploy: true), 200);
            }
            if ($request->method() === 'POST' && str_contains($request->url(), '/deploy')) {
                return Http::response(['deployments' => [['deployment_uuid' => 'dep-2']]], 200);
            }

            return Http::response(['error' => 'unexpected'], 404);
        });

        $this->actingAs($this->operator())
            ->post(route('ops.sites.follow-head', $site))
            ->assertRedirect()
            ->assertSessionHas('status');

        Http::assertSent(function (Request $request): bool {
            $body = $request->data();

            return $request->method() === 'PATCH'
                && array_key_exists('git_commit_sha', $body)
                && $body['git_commit_sha'] === 'HEAD'
                && ($body['is_auto_deploy_enabled'] ?? null) === true
                && ! array_key_exists('is_auto_deploy', $body);
        });
    }

    public function test_show_does_not_link_get_for_post_only_ops(): void
    {
        $site = $this->site();

        Http::fake([
            'https://coolify.example/api/v1/applications/'.self::APP => Http::response($this->appPayload(sha: 'deadbeef', autoDeploy: false), 200),
        ]);

        $html = $this->actingAs($this->operator())
            ->get(route('ops.sites.show', $site))
            ->assertOk()
            ->assertSee('deadbeef', false)
            ->assertSee(__('site_ops.pin.follow_button'), false)
            ->assertSee(__('site_ops.auto_deploy.status_off'), false)
            ->assertSee('data-confirm="'.__('site_ops.auto_deploy.confirm_on', ['name' => $site->name]).'"', false)
            ->assertSee('data-confirm="'.__('site_ops.pin.confirm', ['name' => $site->name]).'"', false)
            ->assertSee('data-confirm="'.__('site_ops.pin.confirm_follow', ['name' => $site->name]).'"', false)
            ->getContent();

        $this->assertDoesNotMatchRegularExpression('/href="[^"]*\/follow-head"/', $html);
        $this->assertDoesNotMatchRegularExpression('/href="[^"]*\/auto-deploy"/', $html);
        $this->assertDoesNotMatchRegularExpression('/href="[^"]*\/pin"/', $html);
        $this->assertDoesNotMatchRegularExpression('/href="[^"]*\/sites\/[^"\/]+\/deploy"/', $html);
        $this->assertStringContainsString(route('ops.sites.deploy', $site), $html);
        $this->assertStringContainsString(__('sites.menu.deploy'), $html);
        $this->assertStringContainsString(__('site_ops.redeploy.button'), $html);
    }

    public function test_redeploy_posts_force_deploy_without_application_patch(): void
    {
        $site = $this->site();

        Http::fake(function (Request $request) {
            if ($sync = $this->coolifyEnvSyncResponse($request)) {
                return $sync;
            }
            if ($request->method() === 'POST' && str_contains($request->url(), '/deploy')) {
                return Http::response(['deployments' => [['deployment_uuid' => 'dep-3']]], 200);
            }

            return Http::response(['error' => 'unexpected'], 404);
        });

        $this->actingAs($this->operator())
            ->post(route('ops.sites.deploy', $site))
            ->assertRedirect()
            ->assertSessionHas('status');

        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'POST'
                && str_contains($request->url(), '/deploy')
                && str_contains($request->url(), 'uuid='.self::APP)
                && str_contains($request->url(), 'force=true');
        });
        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'PATCH' && str_contains($request->url(), '/envs/bulk');
        });
        Http::assertNotSent(function (Request $request): bool {
            return $request->method() === 'PATCH'
                && ! str_contains($request->url(), '/envs');
        });
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE');
    }

    public function test_viewer_cannot_redeploy_or_follow_head(): void
    {
        $site = $this->site();
        Http::fake();

        $this->actingAs($this->viewer())
            ->post(route('ops.sites.deploy', $site))
            ->assertForbidden();

        $this->actingAs($this->viewer())
            ->post(route('ops.sites.follow-head', $site))
            ->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_show_reads_legacy_nested_auto_deploy_as_on(): void
    {
        $site = $this->site();

        Http::fake([
            'https://coolify.example/api/v1/applications/'.self::APP => Http::response([
                'uuid' => self::APP,
                'build_pack' => 'dockercompose',
                'settings' => ['is_auto_deploy' => true],
                'git_commit_sha' => 'cafebabe',
            ], 200),
        ]);

        $this->actingAs($this->operator())
            ->get(route('ops.sites.show', $site))
            ->assertOk()
            ->assertSee(__('site_ops.auto_deploy.status_on'), false)
            ->assertSee(__('site_ops.auto_deploy.off_button'), false)
            ->assertDontSee(__('site_ops.auto_deploy.status_off'), false)
            ->assertSee(route('ops.sites.follow-head', $site), false)
            ->assertSee(route('ops.sites.pin', $site), false)
            ->assertSee(route('ops.sites.deploy', $site), false)
            ->assertSee(__('site_ops.pin.status_following'), false);
    }

    public function test_show_does_not_claim_auto_deploy_off_when_coolify_omits_the_flag(): void
    {
        $site = $this->site();

        Http::fake([
            'https://coolify.example/api/v1/applications/'.self::APP => Http::response([
                'uuid' => self::APP,
                'build_pack' => 'dockercompose',
                'git_commit_sha' => 'abc1234',
            ], 200),
        ]);

        $html = $this->actingAs($this->operator())
            ->get(route('ops.sites.show', $site))
            ->assertOk()
            ->assertSee(__('site_ops.auto_deploy.status_unknown'), false)
            ->assertSee(__('site_ops.auto_deploy.on_button'), false)
            ->assertSee(__('site_ops.auto_deploy.off_button'), false)
            ->getContent();

        $this->assertStringNotContainsString(__('site_ops.auto_deploy.status_off'), $html);
        $this->assertStringContainsString(route('ops.sites.follow-head', $site), $html);
        $this->assertStringContainsString(route('ops.sites.pin', $site), $html);
        $this->assertStringContainsString(route('ops.sites.deploy', $site), $html);
    }

    /**
     * @return array<string, mixed>
     */
    private function appPayload(?string $sha = null, bool $autoDeploy = false): array
    {
        return [
            'uuid' => self::APP,
            'build_pack' => 'dockercompose',
            'is_auto_deploy_enabled' => $autoDeploy,
            'settings' => ['is_auto_deploy_enabled' => $autoDeploy],
            'git_commit_sha' => $sha,
        ];
    }

    private function site(): Site
    {
        $connection = CoolifyConnection::factory()->create([
            'base_url' => 'https://coolify.example',
            'api_token' => self::TOKEN,
        ]);

        return Site::factory()->create([
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

    private function viewer(): User
    {
        $viewer = User::factory()->create();
        $viewer->assignRole(OpsRole::Viewer->value);

        return $viewer;
    }
}
