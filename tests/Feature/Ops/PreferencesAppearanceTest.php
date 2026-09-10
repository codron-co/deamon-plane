<?php

namespace Tests\Feature\Ops;

use App\Enums\Appearance;
use App\Enums\OpsRole;
use App\Models\User;
use App\Support\OpsAppearance;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PreferencesAppearanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_get_appearance_redirects_to_preferences(): void
    {
        $this->actingAs($this->operator())
            ->get('/account/appearance')
            ->assertRedirect(route('ops.account.preferences'));
    }

    public function test_post_appearance_does_not_500_and_sets_cookie(): void
    {
        $user = $this->operator();

        $this->actingAs($user)
            ->from(route('ops.fleet'))
            ->post(route('ops.account.appearance'), [
                'appearance' => Appearance::SemiDark->value,
            ])
            ->assertRedirect(route('ops.fleet'))
            ->assertCookie(OpsAppearance::COOKIE, Appearance::SemiDark->value);

        $this->assertSame(Appearance::SemiDark, $user->fresh()->appearance);
    }

    public function test_json_appearance_and_locale_update_without_navigating_to_get_route(): void
    {
        $user = $this->operator();

        $this->actingAs($user)
            ->postJson(route('ops.account.appearance'), [
                'appearance' => Appearance::Light->value,
            ])
            ->assertOk()
            ->assertJson([
                'appearance' => Appearance::Light->value,
                'reload' => false,
            ]);

        $this->actingAs($user)
            ->postJson(route('ops.account.locale'), [
                'locale' => 'tr',
            ])
            ->assertOk()
            ->assertJson([
                'locale' => 'tr',
                'reload' => true,
            ]);

        $this->assertSame('tr', $user->fresh()->locale);
        $this->assertSame(Appearance::Light, $user->fresh()->appearance);
    }

    public function test_user_menu_uses_cycle_buttons_not_a_preferences_page_link(): void
    {
        $html = $this->actingAs($this->operator())
            ->get(route('ops.fleet'))
            ->assertOk()
            ->assertSee('data-pref="appearance"', false)
            ->assertSee('data-pref="locale"', false)
            ->getContent();

        $this->assertTrue(
            str_contains($html, '"value":"light"') || str_contains($html, '&quot;value&quot;:&quot;light&quot;'),
            'Cycle button must expose parseable appearance options',
        );
        $this->assertStringNotContainsString('ops-theme-choice', $html);
        $this->assertStringNotContainsString('href="'.route('ops.account.preferences').'"', $html);
    }

    private function operator(): User
    {
        $operator = User::factory()->create();
        $operator->assignRole(OpsRole::Operator->value);

        return $operator;
    }
}
