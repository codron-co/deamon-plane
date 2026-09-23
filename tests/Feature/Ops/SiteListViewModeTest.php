<?php

namespace Tests\Feature\Ops;

use App\Enums\OpsRole;
use App\Models\Site;
use App\Models\User;
use App\Support\Lists\ListFragment;
use App\Support\Lists\SiteListColumns;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteListViewModeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_view_switch_renders_translated_with_list_pressed_by_default(): void
    {
        Site::factory()->create();
        $user = $this->user(OpsRole::Viewer);
        $user->forceFill(['locale' => 'tr'])->save();

        $html = $this->actingAs($user)
            ->get(route('ops.sites'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-sites-view-switch', $html);
        $this->assertStringContainsString('data-sites-view="list"', $html);
        $this->assertSame(1, preg_match('/aria-pressed="true"\s+title="[^"]*"\s+data-sites-view-option="list"/', $html));
        $this->assertSame(2, preg_match_all('/aria-pressed="false"/', $this->switchMarkup($html)));

        // The operator's locale is Turkish: every control name comes from lang/tr.
        foreach (['label', 'list', 'compact', 'cards'] as $key) {
            $this->assertStringContainsString(e(__('sites.view_mode.'.$key, [], 'tr')), $html);
        }
        $this->assertStringContainsString('aria-label="Görünüm"', $html);
    }

    public function test_view_switch_labels_exist_in_both_locales(): void
    {
        foreach (['tr', 'en'] as $locale) {
            foreach (['label', 'list', 'compact', 'cards', 'saved'] as $key) {
                $this->assertNotSame('sites.view_mode.'.$key, __('sites.view_mode.'.$key, [], $locale), $locale.' '.$key);
            }
        }

        $this->assertSame('Kart görünümü', __('sites.view_mode.cards', [], 'tr'));
        $this->assertSame('Card view', __('sites.view_mode.cards', [], 'en'));
    }

    public function test_cells_carry_column_key_and_translated_label(): void
    {
        Site::factory()->create(['primary_domain' => 'acme.example']);
        $user = $this->user(OpsRole::Operator);
        $user->saveListPreference(SiteListColumns::LIST_KEY, [
            'columns' => ['site', 'theme', 'domain', 'updated'],
        ]);

        $html = $this->actingAs($user->fresh())
            ->get(route('ops.sites'))
            ->assertOk()
            ->getContent();

        foreach (['site', 'theme', 'domain', 'updated'] as $column) {
            $this->assertStringContainsString(
                'data-col="'.$column.'" data-label="'.e(SiteListColumns::label($column)).'"',
                $html,
            );
        }
        $this->assertStringContainsString('data-col="select"', $html);
        $this->assertStringContainsString('data-col="actions"', $html);
        $this->assertStringNotContainsString('data-col="live"', $html);
        // The card identity block repeats the domain; the table hides it outside cards.
        $this->assertStringContainsString('<div class="site-card-domain">acme.example</div>', $html);
    }

    public function test_mode_is_saved_to_the_account_and_rendered_on_first_paint(): void
    {
        Site::factory()->create();
        $user = $this->user(OpsRole::Viewer);

        $this->actingAs($user)
            ->postJson(route('ops.sites.list-mode'), ['mode' => 'cards'])
            ->assertOk()
            ->assertJson(['ok' => true, 'list' => 'sites', 'mode' => 'cards']);

        $this->assertSame('cards', $user->fresh()->listPreference(SiteListColumns::LIST_KEY)['mode']);

        $html = $this->actingAs($user->fresh())
            ->get(route('ops.sites'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('<section class="plane-workspace" data-sites-view="cards">', $html);
        $this->assertSame(1, preg_match('/aria-pressed="true"\s+title="[^"]*"\s+data-sites-view-option="cards"/', $html));
    }

    public function test_mode_does_not_touch_columns_and_survives_a_column_reset(): void
    {
        $user = $this->user(OpsRole::Operator);
        $user->saveListPreference(SiteListColumns::LIST_KEY, ['columns' => ['site', 'publish']]);

        $this->actingAs($user)
            ->from(route('ops.sites'))
            ->post(route('ops.sites.list-mode'), ['mode' => 'compact'])
            ->assertRedirect(route('ops.sites'));

        $stored = $user->fresh()->listPreference(SiteListColumns::LIST_KEY);
        $this->assertSame(['site', 'publish'], $stored['columns']);
        $this->assertSame('compact', $stored['mode']);

        $this->actingAs($user->fresh())
            ->deleteJson(route('ops.sites.list-preferences.reset'))
            ->assertOk();

        $this->assertSame('compact', $user->fresh()->listPreference(SiteListColumns::LIST_KEY)['mode']);
    }

    public function test_unknown_mode_is_rejected_and_a_stale_stored_mode_falls_back_to_list(): void
    {
        Site::factory()->create();
        $user = $this->user(OpsRole::Operator);

        $this->actingAs($user)
            ->postJson(route('ops.sites.list-mode'), ['mode' => 'kanban'])
            ->assertStatus(422);

        $user->saveListPreference(SiteListColumns::LIST_KEY, ['mode' => 'kanban']);

        $this->actingAs($user->fresh())
            ->get(route('ops.sites'))
            ->assertOk()
            ->assertSee('data-sites-view="list"', false);
    }

    public function test_region_fragment_keeps_mode_outside_the_region(): void
    {
        Site::factory()->create();
        $user = $this->user(OpsRole::Operator);
        $user->saveListPreference(SiteListColumns::LIST_KEY, ['mode' => 'cards']);

        // The fetched region is swapped inside the workspace; the mode attribute
        // lives on the workspace, so the fragment must not carry its own copy.
        $fragment = $this->actingAs($user->fresh())
            ->withHeaders([ListFragment::HEADER => ListFragment::VALUE])
            ->get(route('ops.sites'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('data-sites-view=', $fragment);
        $this->assertStringContainsString('data-col="site"', $fragment);
    }

    private function switchMarkup(string $html): string
    {
        $start = strpos($html, 'data-sites-view-switch');
        $end = strpos($html, '</form>', (int) $start);

        return substr($html, (int) $start, (int) $end - (int) $start);
    }

    private function user(OpsRole $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);

        return $user;
    }
}
