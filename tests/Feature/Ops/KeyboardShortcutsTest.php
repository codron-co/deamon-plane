<?php

namespace Tests\Feature\Ops;

use App\Enums\OpsRole;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The shortcuts themselves are browser behavior. Pest pins the markup contract
 * the overlay declares; `isTyping` / `confirmOpen` live under `node --test`
 * tests/js (ADR-11). This class stays the JS-off source of truth.
 */
class KeyboardShortcutsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_every_ops_page_ships_the_overlay_and_its_script(): void
    {
        $operator = $this->user(OpsRole::Operator);

        foreach ([route('ops.fleet'), route('ops.sites'), route('ops.domains'), route('ops.themes')] as $url) {
            $this->actingAs($operator)
                ->get($url)
                ->assertOk()
                ->assertSee('data-ops-shortcuts', false)
                ->assertSee('js/ops-shortcuts.js', false)
                ->assertSee('data-ops-palette', false)
                ->assertSee('js/ops-palette.js', false)
                ->assertSee('data-ops-palette-url="'.e(route('ops.palette')).'"', false);
        }
    }

    public function test_the_overlay_starts_hidden_and_is_a_labelled_dialog(): void
    {
        $content = $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.sites'))
            ->assertOk()
            ->assertSee('role="dialog"', false)
            ->assertSee('aria-labelledby="ops-shortcuts-title"', false)
            ->assertSee('aria-labelledby="ops-palette-title"', false)
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/<div class="ops-shortcuts" data-ops-shortcuts hidden>/',
            $content,
            'The overlay must be hidden until a shortcut or the trigger opens it.'
        );
        $this->assertMatchesRegularExpression(
            '/data-ops-palette[\s\S]*?\bhidden\b/',
            $content,
            'The palette must stay hidden until Ctrl/⌘+K opens it.'
        );
    }

    public function test_jump_targets_carry_real_urls_so_the_script_never_builds_one(): void
    {
        $content = $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.fleet'))
            ->assertOk()
            ->getContent();

        $expected = [
            'f' => route('ops.fleet'),
            's' => route('ops.sites'),
            'd' => route('ops.domains'),
            't' => route('ops.themes'),
        ];

        foreach ($expected as $key => $url) {
            $this->assertStringContainsString(
                'data-ops-shortcuts-go="'.$key.'" data-ops-shortcuts-url="'.e($url).'"',
                $content,
                'The g-'.$key.' target must be rendered by the server.'
            );
        }
    }

    public function test_the_trigger_is_reachable_without_a_keyboard_and_has_a_name(): void
    {
        $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.sites'))
            ->assertOk()
            ->assertSee('data-ops-shortcuts-open', false)
            ->assertSee('aria-label="'.e(__('ops.shortcuts.open')).'"', false);
    }

    public function test_the_overlay_reads_as_turkish_for_a_turkish_operator(): void
    {
        $this->actingAs($this->user(OpsRole::Operator, 'tr'))
            ->get(route('ops.sites'))
            ->assertOk()
            ->assertSee(trans('ops.shortcuts.title', [], 'tr'), false)
            ->assertSee(trans('ops.shortcuts.search', [], 'tr'), false)
            ->assertSee(trans('ops.shortcuts.palette', [], 'tr'), false)
            ->assertSee(trans('ops.shortcuts.typing_note', [], 'tr'), false)
            ->assertSee(trans('ops.shortcuts.go_to', ['page' => trans('ops.nav.sites', [], 'tr')], 'tr'), false)
            ->assertDontSee(trans('ops.shortcuts.title', [], 'en'), false);
    }

    public function test_a_viewer_gets_the_same_shortcuts(): void
    {
        $this->actingAs($this->user(OpsRole::Viewer))
            ->get(route('ops.sites'))
            ->assertOk()
            ->assertSee('data-ops-shortcuts-go="s"', false);
    }

    private function user(OpsRole $role, ?string $locale = null): User
    {
        $user = User::factory()->create($locale === null ? [] : ['locale' => $locale]);
        $user->assignRole($role->value);

        return $user;
    }
}
