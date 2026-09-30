<?php

namespace Tests\Feature\Sites;

use App\Enums\Channel;
use App\Enums\CoolifyEnvKind;
use App\Enums\OpsRole;
use App\Enums\SiteStatus;
use App\Models\CoolifyConnection;
use App\Models\CoolifyEnvDefault;
use App\Models\Site;
use App\Models\User;
use App\Services\Coolify\CoolifyApplicationService;
use App\Services\Sites\SiteEnvEditor;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SiteEnvEditorTest extends TestCase
{
    use RefreshDatabase;

    private const APP = 'env-editor-app';

    private const DB_SECRET = 'db-password-that-must-never-render';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Http::preventStrayRequests();
        config(['app.url' => 'https://plane.codron.co']);

        CoolifyEnvDefault::query()->delete();
        foreach ([Channel::Main, Channel::Alpha] as $channel) {
            $this->catalogRow($channel, 'APP_ENV', CoolifyEnvKind::Static, 'production');
            $this->catalogRow($channel, 'DEAMON_CHANNEL', CoolifyEnvKind::Site, '{{site.channel}}');
            $this->catalogRow($channel, 'DB_PASSWORD', CoolifyEnvKind::Generated, null, true);
            $this->catalogRow($channel, 'MAIL_MAILER', CoolifyEnvKind::Required, 'log');
        }
        $this->catalogRow(Channel::Alpha, 'ALPHA_ONLY_FLAG', CoolifyEnvKind::Static, 'on');
    }

    public function test_rows_compare_with_the_chosen_branch_and_mask_secrets(): void
    {
        $site = $this->site();
        $this->fakeEnvs($this->liveEnvs());

        $editor = app(SiteEnvEditor::class);
        $rows = collect($editor->rows($site, CoolifyApplicationService::forSite($site), Channel::Main))->keyBy('key');

        $this->assertSame('ok', $rows['APP_ENV']['status']);
        $this->assertSame('differs', $rows['DEAMON_CHANNEL']['status']);
        $this->assertSame('main', $rows['DEAMON_CHANNEL']['expected']);
        $this->assertSame('ok', $rows['DB_PASSWORD']['status']);
        $this->assertTrue($rows['DB_PASSWORD']['secret']);
        $this->assertTrue($rows['DB_PASSWORD']['bootstrap']);
        $this->assertNull($rows['DB_PASSWORD']['value']);
        $this->assertFalse($rows['DB_PASSWORD']['editable']);
        $this->assertSame('missing', $rows['MAIL_MAILER']['status']);
        $this->assertSame('extra', $rows['LEGACY_FLAG']['status']);
        $this->assertTrue($rows['LEGACY_FLAG']['deletable']);
        $this->assertSame('protected', $rows['SERVICE_URL_APP']['status']);
        $this->assertFalse($rows['SERVICE_URL_APP']['editable']);
        $this->assertSame('bootstrap', $rows['MYSQL_ROOT_PASSWORD']['status']);
        $this->assertNull($rows['MYSQL_ROOT_PASSWORD']['value']);
        $this->assertTrue($rows['SMTP_TOKEN']['secret']);
        $this->assertNull($rows['SMTP_TOKEN']['value']);
        $this->assertArrayNotHasKey('ALPHA_ONLY_FLAG', $rows->all());

        $alpha = collect($editor->rows($site, CoolifyApplicationService::forSite($site), Channel::Alpha))->keyBy('key');
        // {{site.channel}} is the branch the Coolify app builds, not the compared catalog.
        $this->assertSame('differs', $alpha['DEAMON_CHANNEL']['status']);
        $this->assertSame('missing', $alpha['ALPHA_ONLY_FLAG']['status']);
    }

    public function test_panel_renders_statuses_without_secret_values(): void
    {
        $site = $this->site();
        $this->fakeEnvs($this->liveEnvs());

        $this->actingAs($this->operator())
            ->get(route('ops.sites.env.panel', [$site, 'channel' => 'alpha']))
            ->assertOk()
            ->assertSee('ALPHA_ONLY_FLAG')
            ->assertSee('LEGACY_FLAG')
            ->assertSee('legacy-value')
            ->assertSee(route('ops.sites.env.fix', $site), false)
            ->assertDontSee(self::DB_SECRET)
            ->assertDontSee('root-password-hidden')
            ->assertDontSee('smtp-token-hidden');
    }

    public function test_viewer_sees_the_panel_without_write_actions(): void
    {
        $site = $this->site();
        $this->fakeEnvs($this->liveEnvs());

        $this->actingAs($this->viewer())
            ->get(route('ops.sites.env.panel', $site))
            ->assertOk()
            ->assertSee('LEGACY_FLAG')
            ->assertDontSee(route('ops.sites.env.fix', $site), false);

        $this->actingAs($this->viewer())
            ->post(route('ops.sites.env.set', $site), ['key' => 'NEW_FLAG', 'value' => 'x'])
            ->assertForbidden();
    }

    public function test_fix_by_branch_syncs_the_chosen_channel_catalog(): void
    {
        $site = $this->site();
        $this->fakeEnvs($this->liveEnvs());

        $this->actingAs($this->operator())
            ->post(route('ops.sites.env.fix', $site), ['channel' => 'alpha'])
            ->assertRedirect()
            ->assertSessionHas('status');

        Http::assertSent(function (Request $request): bool {
            if ($request->method() !== 'PATCH' || ! str_ends_with($request->url(), '/envs/bulk')) {
                return false;
            }
            $map = $this->bulkMap($request);

            return ($map['ALPHA_ONLY_FLAG'] ?? null) === 'on'
                && ($map['MAIL_MAILER'] ?? null) === 'log'
                && ($map['DEAMON_CHANNEL'] ?? null) === 'main'
                && ! array_key_exists('DB_PASSWORD', $map)
                && ! array_key_exists('MYSQL_ROOT_PASSWORD', $map);
        });
        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && str_contains($request->url(), '/envs/env-legacy'));
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && (str_contains($request->url(), '/envs/env-db') || str_contains($request->url(), '/envs/env-root')));

        $this->assertDatabaseHas('audit_logs', ['action' => 'site.env_fixed']);
    }

    public function test_bootstrap_and_protected_keys_are_refused(): void
    {
        $site = $this->site();
        $this->fakeEnvs($this->liveEnvs());
        $operator = $this->operator();

        foreach (['DB_PASSWORD', 'MYSQL_ROOT_PASSWORD', 'APP_KEY', 'CONTROL_PLANE_AGENT_SECRET', 'SERVICE_URL_APP', 'COOLIFY_FQDN'] as $key) {
            $this->actingAs($operator)
                ->post(route('ops.sites.env.set', $site), ['key' => $key, 'value' => 'x'])
                ->assertSessionHasErrors('env');
            $this->actingAs($operator)
                ->delete(route('ops.sites.env.destroy', $site), ['key' => $key])
                ->assertSessionHasErrors('env');
        }

        $this->actingAs($operator)
            ->post(route('ops.sites.env.set', $site), ['key' => 'bad-key', 'value' => 'x'])
            ->assertSessionHasErrors('env');

        Http::assertNotSent(fn (Request $request): bool => in_array($request->method(), ['PATCH', 'DELETE'], true));
    }

    public function test_set_upserts_one_key_and_delete_removes_it(): void
    {
        $site = $this->site();
        $this->fakeEnvs($this->liveEnvs());
        $operator = $this->operator();

        $this->actingAs($operator)
            ->post(route('ops.sites.env.set', $site), ['key' => 'new_flag', 'value' => 'enabled'])
            ->assertRedirect()
            ->assertSessionHas('status');

        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'PATCH'
                && str_ends_with($request->url(), '/envs/bulk')
                && $this->bulkMap($request) === ['NEW_FLAG' => 'enabled'];
        });

        $this->actingAs($operator)
            ->delete(route('ops.sites.env.destroy', $site), ['key' => 'LEGACY_FLAG'])
            ->assertRedirect()
            ->assertSessionHas('status');

        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && str_contains($request->url(), '/envs/env-legacy'));

        $this->actingAs($operator)
            ->delete(route('ops.sites.env.destroy', $site), ['key' => 'NOT_THERE'])
            ->assertSessionHas('error');

        $this->assertDatabaseHas('audit_logs', ['action' => 'site.env_set']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'site.env_deleted']);
        $this->assertDatabaseMissing('audit_logs', ['after' => json_encode(['value' => 'enabled'])]);
    }

    public function test_detail_page_includes_the_lazy_env_panel(): void
    {
        $site = $this->site();

        $this->actingAs($this->operator())
            ->get(route('ops.sites.show', [$site, 'env_channel' => 'beta']))
            ->assertOk()
            ->assertSee(route('ops.sites.env.panel', [$site, 'channel' => 'beta']), false);
    }

    /**
     * @return list<array{key: string, value: string, uuid: string}>
     */
    private function liveEnvs(): array
    {
        return [
            ['key' => 'APP_ENV', 'value' => 'production', 'uuid' => 'env-app-env'],
            ['key' => 'DEAMON_CHANNEL', 'value' => 'alpha', 'uuid' => 'env-channel'],
            ['key' => 'DB_PASSWORD', 'value' => self::DB_SECRET, 'uuid' => 'env-db'],
            ['key' => 'MYSQL_ROOT_PASSWORD', 'value' => 'root-password-hidden', 'uuid' => 'env-root'],
            ['key' => 'SMTP_TOKEN', 'value' => 'smtp-token-hidden', 'uuid' => 'env-smtp'],
            ['key' => 'LEGACY_FLAG', 'value' => 'legacy-value', 'uuid' => 'env-legacy'],
            ['key' => 'SERVICE_URL_APP', 'value' => 'https://example.test', 'uuid' => 'env-service'],
        ];
    }

    private function catalogRow(Channel $channel, string $key, CoolifyEnvKind $kind, ?string $value, bool $secret = false): void
    {
        CoolifyEnvDefault::query()->create([
            'channel' => $channel,
            'key' => $key,
            'kind' => $kind,
            'value' => $value,
            'is_secret' => $secret,
            'sort' => 10,
        ]);
    }

    /**
     * @param  list<array{key: string, value: string, uuid: string}>  $envs
     */
    private function fakeEnvs(array $envs): void
    {
        Http::fake(function (Request $request) use ($envs) {
            if ($request->method() === 'GET' && str_contains($request->url(), '/envs')) {
                return Http::response($envs, 200);
            }
            if ($request->method() === 'PATCH' && str_contains($request->url(), '/envs/bulk')) {
                return Http::response([], 200);
            }
            if ($request->method() === 'DELETE' && str_contains($request->url(), '/envs/')) {
                return Http::response([], 200);
            }

            return Http::response(['error' => 'unexpected '.$request->url()], 404);
        });
    }

    /**
     * @return array<string, string>
     */
    private function bulkMap(Request $request): array
    {
        $map = [];
        foreach ($request->data()['data'] ?? [] as $row) {
            if (is_array($row) && isset($row['key'])) {
                $map[(string) $row['key']] = (string) ($row['value'] ?? '');
            }
        }

        return $map;
    }

    private function site(): Site
    {
        $connection = CoolifyConnection::factory()->create([
            'base_url' => 'https://coolify.example',
            'api_token' => 'plane-env-editor-token',
        ]);

        return Site::factory()->withSecrets()->create([
            'name' => 'Env Editor',
            'status' => SiteStatus::Active,
            'channel' => Channel::Main,
            'coolify_app_uuid' => self::APP,
            'coolify_connection_id' => $connection->id,
        ]);
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
