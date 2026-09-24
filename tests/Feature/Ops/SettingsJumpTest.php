<?php

namespace Tests\Feature\Ops;

use App\Enums\OpsRole;
use App\Models\User;
use App\Support\Ops\SettingsJump;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Settings is four stacked panels. Jump links are ordinary hashes (no JS);
 * the search box only filters what is already on the page.
 */
class SettingsJumpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_settings_declares_section_hashes_and_a_search_box(): void
    {
        $html = $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.settings'))
            ->assertOk()
            ->assertSee('data-ops-settings-jump', false)
            ->assertSee('data-ops-settings-search', false)
            ->assertSee('href="#env-defaults-heading"', false)
            ->assertSee('href="#deamon-git-heading"', false)
            ->assertSee('href="#github-connection-heading"', false)
            ->assertSee('href="#customer-defaults-heading"', false)
            ->assertSee('href="#system-coolify-heading"', false)
            ->assertSee('href="#automation-heading"', false)
            ->getContent();

        $this->assertSame(6, substr_count($html, 'data-settings-section'));
        $this->assertStringContainsString('APP_KEY', $html);
        $this->assertMatchesRegularExpression('/data-settings-haystack="[^"]*APP_KEY/', $html);
        $this->assertStringContainsString('data-env-row', $html);
        $this->assertStringContainsString('data-env-search-empty', $html);
        $this->assertStringContainsString(__('settings.env.search_empty'), $html);
    }

    public function test_turkish_jump_copy_is_used_for_a_turkish_operator(): void
    {
        $this->actingAs($this->user(OpsRole::Operator, 'tr'))
            ->get(route('ops.settings'))
            ->assertOk()
            ->assertSee(trans('settings.jump.search', [], 'tr'), false)
            ->assertSee(trans('settings.env.title', [], 'tr'), false)
            ->assertDontSee(trans('settings.jump.search', [], 'en'), false);
    }

    public function test_a_viewer_still_gets_the_jump_nav(): void
    {
        $this->actingAs($this->user(OpsRole::Viewer))
            ->get(route('ops.settings'))
            ->assertOk()
            ->assertSee('data-ops-settings-search', false)
            ->assertSee('href="#env-defaults-heading"', false);
    }

    public function test_palette_webhook_opens_the_github_settings_hash(): void
    {
        $response = $this->actingAs($this->user(OpsRole::Operator))
            ->getJson(route('ops.palette', ['q' => 'webhook']))
            ->assertOk();

        $this->assertContains(route('ops.settings').'#github-connection-heading', $this->urls($response, 'settings'));
        $this->assertNotContains(route('ops.settings').'#env-defaults-heading', $this->urls($response, 'settings'));
    }

    public function test_palette_env_opens_the_env_defaults_hash(): void
    {
        $response = $this->actingAs($this->user(OpsRole::Operator, 'tr'))
            ->getJson(route('ops.palette', ['q' => 'ortam']))
            ->assertOk();

        $this->assertContains(route('ops.settings').'#env-defaults-heading', $this->urls($response, 'settings'));
        $settingsGroup = collect($response->json('groups') ?? [])->firstWhere('key', 'settings');
        $this->assertSame(trans('ops.palette.groups.settings', [], 'tr'), $settingsGroup['label'] ?? null);
    }

    public function test_an_empty_palette_query_does_not_list_settings_hashes(): void
    {
        $response = $this->actingAs($this->user(OpsRole::Operator))
            ->getJson(route('ops.palette'))
            ->assertOk();

        $this->assertSame(['pages'], collect($response->json('groups') ?? [])->pluck('key')->all());
        $this->assertFalse(
            collect($this->urls($response))->contains(fn (string $url): bool => str_contains($url, '#')),
            'An empty query must not leak Settings hashes.'
        );
    }

    public function test_settings_jump_matching_is_literal(): void
    {
        $this->assertSame(['env'], array_column(SettingsJump::matching('ortam'), 'id'));
        $this->assertSame([], SettingsJump::matching('%'));
    }

    /**
     * @return list<string>
     */
    private function urls(mixed $response, ?string $group = null): array
    {
        $groups = collect($response->json('groups') ?? []);
        if ($group !== null) {
            $groups = $groups->where('key', $group);
        }

        return $groups
            ->flatMap(static fn (array $row): array => $row['items'] ?? [])
            ->pluck('url')
            ->all();
    }

    private function user(OpsRole $role, ?string $locale = null): User
    {
        $user = User::factory()->create($locale === null ? [] : ['locale' => $locale]);
        $user->assignRole($role->value);

        return $user;
    }
}
