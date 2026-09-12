<?php

namespace Tests\Feature\Sites;

use App\Enums\Channel;
use App\Enums\OpsRole;
use App\Enums\SiteStatus;
use App\Models\CoolifyConnection;
use App\Models\CoolifySetting;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SiteBulkActionsTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'plane-bulk-token';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Http::preventStrayRequests();

        CoolifySetting::factory()->create([
            'base_url' => 'https://coolify.example',
            'api_token' => self::TOKEN,
        ]);
    }

    public function test_index_has_select_all_and_selection_only_actions(): void
    {
        $dockerfile = $this->site([
            'name' => 'Legacy Pack',
            'notes' => "[import] dockerfile_build_pack: leftover\n",
        ]);
        $this->site(['name' => 'Compose Site']);

        $html = $this->actingAs($this->operator())
            ->get(route('ops.sites'))
            ->assertOk()
            ->assertSee('name="all"', false)
            ->assertSee(__('site_ops.bulk.select_all'), false)
            ->assertSee('data-ops-bulk-actions', false)
            ->assertSee(__('site_ops.bulk.change_branch'), false)
            ->assertSee(__('site_ops.bulk.compose'), false)
            ->assertSee(__('site_ops.bulk.auto_toggle'), false)
            ->assertSee(__('site_ops.bulk.redeploy'), false)
            ->assertSee(__('site_ops.bulk.follow_head'), false)
            ->assertSee(__('site_ops.bulk.pin'), false)
            ->assertSee(__('sites.menu.hard_delete'), false)
            ->assertDontSee(__('site_ops.bulk.compose_all'), false)
            ->assertDontSee(__('site_ops.bulk.auto_on'), false)
            ->assertDontSee(__('site_ops.bulk.auto_off'), false)
            ->getContent();

        $this->assertStringContainsString('formaction="'.route('ops.sites.bulk.channel').'"', $html);
        $this->assertStringContainsString('formaction="'.route('ops.sites.bulk.compose').'"', $html);
        $this->assertStringContainsString('formaction="'.route('ops.sites.bulk.auto-deploy').'"', $html);
        $this->assertStringContainsString('formaction="'.route('ops.sites.bulk.deploy').'"', $html);
        $this->assertStringContainsString('formaction="'.route('ops.sites.bulk.follow-head').'"', $html);
        $this->assertStringContainsString('formaction="'.route('ops.sites.bulk.pin').'"', $html);
        $this->assertStringContainsString('formaction="'.route('ops.sites.bulk.purge').'"', $html);
        $this->assertStringNotContainsString('formaction="'.route('ops.sites.bulk.sync').'"', $html);
        $this->assertStringNotContainsString('formaction="'.route('ops.sites.live-sync').'"', $html);
        $this->assertStringContainsString((string) $dockerfile->id, $html);
    }

    public function test_bulk_redeploy_confirm_states_the_pin_rule_instead_of_pin_or_head(): void
    {
        $this->app->setLocale('tr');
        $this->site(['name' => 'Confirm Copy']);

        $html = $this->actingAs($this->operator())
            ->get(route('ops.sites'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(
            'data-confirm="'.e(__('site_ops.bulk.confirm_redeploy')).'"',
            $html,
        );
        $this->assertStringNotContainsString('pin veya HEAD', $html);
    }

    public function test_compose_action_is_hidden_when_no_dockerfile_sites(): void
    {
        $this->site(['name' => 'Already Compose']);

        $this->actingAs($this->operator())
            ->get(route('ops.sites'))
            ->assertOk()
            ->assertSee(__('site_ops.bulk.change_branch'), false)
            ->assertDontSee(__('site_ops.bulk.compose'), false);
    }

    public function test_select_all_channel_switch_uses_filters_not_only_the_page(): void
    {
        $connection = $this->connection();
        $keep = $this->site([
            'name' => 'Keep Other',
            'primary_domain' => 'keep.example.test',
            'coolify_connection_id' => $connection->id,
            'coolify_app_uuid' => 'app-keep',
        ]);
        $match = $this->site([
            'name' => 'Alpha Shop',
            'primary_domain' => 'alpha-shop.example.test',
            'coolify_connection_id' => $connection->id,
            'coolify_app_uuid' => 'app-match',
        ]);

        $this->fakeChannelSwitch();

        $this->actingAs($this->operator())
            ->from(route('ops.sites'))
            ->post(route('ops.sites.bulk.channel'), [
                'all' => '1',
                'filter_q' => 'Alpha Shop',
                'channel' => 'beta',
                'confirmed' => '1',
            ])
            ->assertRedirect(route('ops.sites'))
            ->assertSessionHas('status');

        $this->assertSame(Channel::Beta, $match->fresh()->channel);
        $this->assertSame(Channel::Main, $keep->fresh()->channel);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH'
            && str_contains($request->url(), '/applications/app-match')
            && ($request->data()['git_branch'] ?? null) === 'beta');
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/applications/app-keep'));
    }

    public function test_auto_deploy_toggle_turns_all_on_off_and_mixed_off(): void
    {
        $connection = $this->connection();
        $on = $this->site([
            'name' => 'Auto On',
            'coolify_connection_id' => $connection->id,
            'coolify_app_uuid' => 'app-on',
        ]);
        $off = $this->site([
            'name' => 'Auto Off',
            'coolify_connection_id' => $connection->id,
            'coolify_app_uuid' => 'app-off',
        ]);

        Http::fake(function (Request $request) {
            $url = $request->url();
            if ($request->method() === 'GET' && str_contains($url, '/applications/app-on')) {
                return Http::response($this->appPayload('app-on', true), 200);
            }
            if ($request->method() === 'GET' && str_contains($url, '/applications/app-off')) {
                return Http::response($this->appPayload('app-off', false), 200);
            }
            if ($request->method() === 'PATCH' && str_contains($url, '/applications/')) {
                return Http::response(['uuid' => 'patched'], 200);
            }

            return Http::response(['error' => 'unexpected '.$url], 404);
        });

        $this->actingAs($this->operator())
            ->from(route('ops.sites'))
            ->post(route('ops.sites.bulk.auto-deploy'), [
                'site_ids' => [$on->id, $off->id],
            ])
            ->assertRedirect(route('ops.sites'));

        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'PATCH'
                && str_contains($request->url(), '/applications/app-on')
                && ($request->data()['is_auto_deploy_enabled'] ?? null) === false;
        });
        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'PATCH'
                && str_contains($request->url(), '/applications/app-off')
                && ($request->data()['is_auto_deploy_enabled'] ?? null) === false;
        });
    }

    public function test_auto_deploy_toggle_turns_all_off_on(): void
    {
        $connection = $this->connection();
        $a = $this->site([
            'coolify_connection_id' => $connection->id,
            'coolify_app_uuid' => 'app-a',
        ]);
        $b = $this->site([
            'coolify_connection_id' => $connection->id,
            'coolify_app_uuid' => 'app-b',
        ]);

        Http::fake(function (Request $request) {
            if ($request->method() === 'GET') {
                return Http::response($this->appPayload('app', false), 200);
            }
            if ($request->method() === 'PATCH') {
                return Http::response(['uuid' => 'patched'], 200);
            }

            return Http::response(['error' => 'unexpected'], 404);
        });

        $this->actingAs($this->operator())
            ->post(route('ops.sites.bulk.auto-deploy'), [
                'site_ids' => [$a->id, $b->id],
            ])
            ->assertRedirect();

        Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH'
            && ($request->data()['is_auto_deploy_enabled'] ?? null) === true);
    }

    public function test_viewer_cannot_run_bulk_channel_or_auto_deploy(): void
    {
        $site = $this->site();
        Http::fake();

        $this->actingAs($this->viewer())
            ->post(route('ops.sites.bulk.channel'), [
                'site_ids' => [$site->id],
                'channel' => 'beta',
                'confirmed' => '1',
            ])
            ->assertForbidden();

        $this->actingAs($this->viewer())
            ->post(route('ops.sites.bulk.auto-deploy'), ['all' => '1'])
            ->assertForbidden();

        $this->actingAs($this->viewer())
            ->post(route('ops.sites.bulk.deploy'), ['all' => '1'])
            ->assertForbidden();

        $this->actingAs($this->viewer())
            ->post(route('ops.sites.bulk.follow-head'), ['all' => '1'])
            ->assertForbidden();

        $this->actingAs($this->viewer())
            ->post(route('ops.sites.bulk.pin'), ['all' => '1', 'ref' => 'abc1234'])
            ->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_bulk_redeploy_force_deploys_without_application_patch(): void
    {
        $connection = $this->connection();
        $site = $this->site([
            'coolify_connection_id' => $connection->id,
            'coolify_app_uuid' => 'app-redeploy',
        ]);

        Http::fake(function (Request $request) {
            if ($sync = $this->coolifyEnvSyncResponse($request)) {
                return $sync;
            }
            if ($request->method() === 'POST' && str_contains($request->url(), '/deploy')) {
                return Http::response(['deployments' => [['deployment_uuid' => 'dep-rd']]], 200);
            }

            return Http::response(['error' => 'unexpected '.$request->url()], 404);
        });

        $this->actingAs($this->operator())
            ->from(route('ops.sites'))
            ->post(route('ops.sites.bulk.deploy'), ['site_ids' => [$site->id]])
            ->assertRedirect(route('ops.sites'))
            ->assertSessionHas('status');

        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'POST'
                && str_contains($request->url(), '/deploy')
                && str_contains($request->url(), 'uuid=app-redeploy')
                && str_contains($request->url(), 'force=true');
        });
        Http::assertNotSent(function (Request $request): bool {
            return ($request->method() === 'PATCH' && ! str_contains($request->url(), '/envs'))
                || $request->method() === 'DELETE';
        });
    }

    public function test_bulk_follow_head_and_pin_use_patch_then_deploy(): void
    {
        $connection = $this->connection();
        $follow = $this->site([
            'coolify_connection_id' => $connection->id,
            'coolify_app_uuid' => 'app-follow',
        ]);
        $pin = $this->site([
            'coolify_connection_id' => $connection->id,
            'coolify_app_uuid' => 'app-pin',
        ]);

        Http::fake(function (Request $request) {
            if ($sync = $this->coolifyEnvSyncResponse($request)) {
                return $sync;
            }
            if ($request->method() === 'PATCH' && str_contains($request->url(), '/applications/')) {
                return Http::response(['uuid' => 'patched'], 200);
            }
            if ($request->method() === 'POST' && str_contains($request->url(), '/deploy')) {
                return Http::response(['deployments' => [['deployment_uuid' => 'dep-pin']]], 200);
            }

            return Http::response(['error' => 'unexpected '.$request->url()], 404);
        });

        $this->actingAs($this->operator())
            ->post(route('ops.sites.bulk.follow-head'), ['site_ids' => [$follow->id]])
            ->assertRedirect()
            ->assertSessionHas('status');

        Http::assertSent(function (Request $request): bool {
            $body = $request->data();

            return $request->method() === 'PATCH'
                && str_contains($request->url(), '/applications/app-follow')
                && ($body['git_commit_sha'] ?? null) === 'HEAD'
                && ($body['is_auto_deploy_enabled'] ?? null) === true;
        });

        $this->actingAs($this->operator())
            ->post(route('ops.sites.bulk.pin'), ['site_ids' => [$pin->id], 'ref' => 'abc1234'])
            ->assertRedirect()
            ->assertSessionHas('status');

        Http::assertSent(function (Request $request): bool {
            $body = $request->data();

            return $request->method() === 'PATCH'
                && str_contains($request->url(), '/applications/app-pin')
                && ($body['git_commit_sha'] ?? null) === 'abc1234'
                && ($body['is_auto_deploy_enabled'] ?? null) === false;
        });
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE');
    }

    /**
     * @return array<string, mixed>
     */
    private function appPayload(string $uuid, bool $autoDeploy): array
    {
        return [
            'uuid' => $uuid,
            'settings' => ['is_auto_deploy_enabled' => $autoDeploy],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function site(array $overrides = []): Site
    {
        return Site::factory()->create(array_merge([
            'status' => SiteStatus::Active,
            'channel' => Channel::Main,
            'coolify_app_uuid' => 'app-'.uniqid(),
        ], $overrides));
    }

    private function connection(): CoolifyConnection
    {
        return CoolifyConnection::factory()->create([
            'base_url' => 'https://coolify.example',
            'api_token' => self::TOKEN,
        ]);
    }

    private function fakeChannelSwitch(): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();
            $method = $request->method();

            if ($sync = $this->coolifyEnvSyncResponse($request)) {
                return $sync;
            }
            if ($method === 'PATCH' && str_contains($url, '/envs')) {
                return Http::response([], 200);
            }
            if ($method === 'PATCH' && preg_match('#/applications/[^/]+$#', $url) === 1) {
                return Http::response(['uuid' => 'app', 'git_branch' => $request->data()['git_branch'] ?? 'main'], 200);
            }
            if ($method === 'POST' && str_contains($url, '/deploy')) {
                return Http::response([
                    'deployments' => [['deployment_uuid' => 'dep-bulk-1']],
                ], 200);
            }
            if ($method === 'GET' && str_contains($url, '/deployments/dep-bulk-1')) {
                return Http::response(['uuid' => 'dep-bulk-1', 'status' => 'finished', 'commit' => 'sha'], 200);
            }

            return Http::response(['error' => 'unexpected '.$url], 404);
        });
    }

    private function operator(): User
    {
        $user = User::factory()->create();
        $user->assignRole(OpsRole::Operator->value);

        return $user;
    }

    private function viewer(): User
    {
        $user = User::factory()->create();
        $user->assignRole(OpsRole::Viewer->value);

        return $user;
    }
}
