<?php

namespace Tests\Feature\Ops;

use App\Enums\Channel;
use App\Enums\CoolifyEnvKind;
use App\Enums\OpsRole;
use App\Models\CoolifyEnvCatalogSource;
use App\Models\CoolifyEnvDefault;
use App\Models\GithubSetting;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SettingsEnvDefaultsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_settings_shows_one_catalog_per_branch_from_the_cms_example(): void
    {
        $html = $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.settings'))
            ->assertOk()
            ->assertSee(__('settings.env.title'), false)
            ->assertSee('env-channel-main', false)
            ->assertSee('env-channel-beta', false)
            ->assertSee('env-channel-alpha', false)
            ->assertDontSee('Dockerfile', false)
            ->assertSee(__('settings.env.developer'), false)
            ->assertSee(__('settings.env.kinds.generated'), false)
            ->assertSee(__('settings.env.kinds.site'), false)
            ->assertSee(__('settings.env.sync'), false)
            ->assertSee('codron-co/deamon', false)
            ->assertSee('.env.production.example', false)
            ->assertSee('MYSQL_ROOT_PASSWORD', false)
            ->assertSee('DB_PASSWORD={{generated}}', false)
            ->assertSee('APP_KEY={{site.app_key}}', false)
            ->assertSee('CONTROL_PLANE_HOST_ALLOWLIST={{plane.host}}', false)
            ->getContent();

        $this->assertStringContainsString('SERVICE_URL_APP={{coolify}}', $html);
        $this->assertStringNotContainsString('APP_TIMEZONE=', $html);
        $this->assertStringNotContainsString('DEAMON_PLATFORM_MAIL', $html);
        $this->assertStringNotContainsString('DEAMON_DEFAULT_ADMIN_PASSWORD', $html);

        foreach (Channel::cases() as $channel) {
            $this->assertSame(
                CoolifyEnvKind::Generated,
                CoolifyEnvDefault::query()->forChannel($channel)->where('key', 'MYSQL_ROOT_PASSWORD')->first()?->kind,
            );
            $this->assertTrue(
                CoolifyEnvDefault::query()->forChannel($channel)->where('key', 'APP_KEY')->value('is_secret') === true
                || (bool) CoolifyEnvDefault::query()->forChannel($channel)->where('key', 'APP_KEY')->value('is_secret'),
            );
        }
    }

    public function test_row_description_comes_from_the_comment_above_the_key(): void
    {
        $row = CoolifyEnvDefault::query()->forChannel(Channel::Main)->where('key', 'DB_PASSWORD')->firstOrFail();

        $this->assertStringContainsString('Compose MySQL', (string) $row->description);
        $this->assertTrue($row->is_secret);

        $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.settings'))
            ->assertOk()
            ->assertSee('Compose MySQL', false);
    }

    public function test_operator_can_refresh_a_branch_from_github(): void
    {
        GithubSetting::query()->create(['token' => 'ghp-settings-pat']);

        $example = "# Fresh from git\n#@secret\nAPP_KEY={{site.app_key}}\nDB_PASSWORD={{generated}}\nMYSQL_ROOT_PASSWORD={{generated}}\nNEW_KEY=static-value\n";

        Http::fake([
            'api.github.com/repos/codron-co/deamon/contents/.env.production.example*' => Http::response([
                'type' => 'file',
                'encoding' => 'base64',
                'content' => base64_encode($example),
            ], 200),
            'api.github.com/repos/codron-co/deamon/commits*' => Http::response([
                ['sha' => 'abcdef1234567890'],
            ], 200),
        ]);

        $this->actingAs($this->user(OpsRole::Operator))
            ->from(route('ops.settings'))
            ->post(route('ops.settings.env.sync'), ['channel' => 'beta'])
            ->assertRedirect(route('ops.settings'))
            ->assertSessionHas('status');

        Http::assertSent(function (Request $request): bool {
            return str_contains($request->url(), '/repos/codron-co/deamon/contents/.env.production.example')
                && ($request->data()['ref'] ?? $request['ref'] ?? null) === 'beta'
                && $request->hasHeader('Authorization', 'Bearer ghp-settings-pat');
        });

        $this->assertSame(4, CoolifyEnvDefault::query()->forChannel(Channel::Beta)->count());
        $this->assertSame('static-value', CoolifyEnvDefault::query()->forChannel(Channel::Beta)->where('key', 'NEW_KEY')->value('value'));
        $this->assertFalse(CoolifyEnvDefault::query()->forChannel(Channel::Beta)->where('key', 'DEAMON_SITE_NAME')->exists());
        // Other branches untouched.
        $this->assertTrue(CoolifyEnvDefault::query()->forChannel(Channel::Main)->where('key', 'DEAMON_SITE_NAME')->exists());

        $source = CoolifyEnvCatalogSource::forChannel(Channel::Beta);
        $this->assertSame('abcdef1234567890', $source->commit_sha);
        $this->assertSame(4, $source->row_count);
        $this->assertNull($source->last_error);
    }

    public function test_refresh_without_github_credentials_flashes_error_and_keeps_catalog(): void
    {
        Http::preventStrayRequests();
        $before = CoolifyEnvDefault::query()->forChannel(Channel::Main)->count();

        $this->actingAs($this->user(OpsRole::Operator))
            ->from(route('ops.settings'))
            ->post(route('ops.settings.env.sync'), ['channel' => 'main'])
            ->assertRedirect(route('ops.settings'))
            ->assertSessionHas('error');

        $this->assertSame($before, CoolifyEnvDefault::query()->forChannel(Channel::Main)->count());
        $this->assertNotNull(CoolifyEnvCatalogSource::forChannel(Channel::Main)->last_error);
    }

    public function test_env_rows_publish_a_client_search_haystack_and_settings_is_not_a_list_fragment(): void
    {
        $response = $this->actingAs($this->user(OpsRole::Operator, 'tr'))
            ->get(route('ops.settings'))
            ->assertOk()
            ->assertSee('data-ops-settings-search', false)
            ->assertSee('data-env-search-text=', false)
            ->assertSee('MYSQL_ROOT_PASSWORD', false)
            ->assertSee(trans('settings.env.search_hint', [], 'tr'), false)
            ->assertSee(trans('settings.jump.search', [], 'tr'), false)
            ->assertDontSee(trans('settings.env.search_hint', [], 'en'), false)
            ->assertDontSee('data-ops-list-toolbar', false);

        $this->assertFalse($response->headers->has('X-Ops-List-Region'));
        $this->assertStringContainsString('mysql_root_password', mb_strtolower($response->getContent()));
    }

    public function test_viewer_cannot_refresh_and_sees_readonly_hint(): void
    {
        $this->actingAs($this->user(OpsRole::Viewer))
            ->post(route('ops.settings.env.sync'), ['channel' => 'main'])
            ->assertForbidden();

        $this->actingAs($this->user(OpsRole::Viewer))
            ->get(route('ops.settings'))
            ->assertOk()
            ->assertSee(__('ops.viewer_readonly'), false)
            ->assertDontSee(__('settings.env.sync_all'), false);
    }

    private function user(OpsRole $role, ?string $locale = null): User
    {
        $user = User::factory()->create($locale === null ? [] : ['locale' => $locale]);
        $user->assignRole($role->value);

        return $user;
    }
}
