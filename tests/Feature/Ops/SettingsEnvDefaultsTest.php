<?php

namespace Tests\Feature\Ops;

use App\Enums\CoolifyEnvKind;
use App\Enums\CoolifyEnvPack;
use App\Enums\OpsRole;
use App\Models\CoolifyEnvDefault;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsEnvDefaultsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_settings_shows_compose_and_dockerfile_catalogs_with_developer_view(): void
    {
        $html = $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.settings'))
            ->assertOk()
            ->assertSee(__('settings.env.title'), false)
            ->assertSee(__('settings.env.packs.dockercompose'), false)
            ->assertSee(__('settings.env.packs.dockerfile'), false)
            ->assertSee(__('settings.env.developer'), false)
            ->assertSee(__('settings.env.kinds.static'), false)
            ->assertSee(__('settings.env.kinds.generated'), false)
            ->assertSee(__('settings.env.kinds.site'), false)
            ->assertSee('MYSQL_ROOT_PASSWORD', false)
            ->assertSee('APP_TIMEZONE=Europe/Istanbul', false)
            ->assertSee('DB_PASSWORD={{generated}}', false)
            ->assertSee('APP_KEY={{site.app_key}}', false)
            ->getContent();

        $this->assertStringContainsString('SERVICE_URL_APP={{coolify}}', $html);
        $this->assertSame(
            CoolifyEnvKind::Generated,
            CoolifyEnvDefault::query()->forPack(CoolifyEnvPack::DockerCompose)->where('key', 'MYSQL_ROOT_PASSWORD')->first()?->kind,
        );
        $this->assertFalse(
            CoolifyEnvDefault::query()->forPack(CoolifyEnvPack::Dockerfile)->where('key', 'MYSQL_ROOT_PASSWORD')->exists(),
        );
    }

    public function test_operator_can_update_compose_static_default(): void
    {
        $rows = CoolifyEnvDefault::query()->forPack(CoolifyEnvPack::DockerCompose)->get();
        $payload = ['pack' => CoolifyEnvPack::DockerCompose->value, 'rows' => []];

        foreach ($rows as $index => $row) {
            $payload['rows'][$index] = [
                'key' => $row->key,
                'kind' => $row->kind->value,
                'value' => $row->key === 'APP_TIMEZONE' ? 'Europe/Berlin' : $row->value,
                'is_secret' => $row->is_secret ? '1' : '0',
                'notes' => $row->notes,
            ];
        }

        $this->actingAs($this->user(OpsRole::Operator))
            ->from(route('ops.settings'))
            ->post(route('ops.settings.env.update'), $payload)
            ->assertRedirect(route('ops.settings'))
            ->assertSessionHas('status');

        $this->assertSame(
            'Europe/Berlin',
            CoolifyEnvDefault::query()->forPack(CoolifyEnvPack::DockerCompose)->where('key', 'APP_TIMEZONE')->value('value'),
        );
    }

    public function test_viewer_cannot_save_env_defaults(): void
    {
        $this->actingAs($this->user(OpsRole::Viewer))
            ->post(route('ops.settings.env.update'), [
                'pack' => CoolifyEnvPack::DockerCompose->value,
                'rows' => [[
                    'key' => 'APP_TIMEZONE',
                    'kind' => CoolifyEnvKind::Static->value,
                    'value' => 'UTC',
                ]],
            ])
            ->assertForbidden();

        $this->actingAs($this->user(OpsRole::Viewer))
            ->get(route('ops.settings'))
            ->assertOk()
            ->assertSee(__('ops.viewer_readonly'), false)
            ->assertDontSee(__('settings.env.save'), false);
    }

    private function user(OpsRole $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);

        return $user;
    }
}
