<?php

namespace Tests\Feature\Sites;

use App\Enums\OpsRole;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteAliasRowsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_error_on_the_third_alias_is_shown_on_its_own_row(): void
    {
        $response = $this->actingAs($this->operator())
            ->from(route('ops.sites.create'))
            ->followingRedirects()
            ->post(route('ops.sites.store'), [
                'slug' => 'izyem',
                'name' => 'Izyem',
                'domain' => 'izyem.test',
                'aliases' => ['shop.izyem.test', 'blog.izyem.test', 'not a host'],
                'channel' => 'main',
            ])
            ->assertOk();

        $html = (string) $response->getContent();

        // Before: only aliases.0 and aliases.1 were rendered, the third error was invisible.
        $this->assertMatchesRegularExpression(
            '#id="site_alias_2".*?data-alias-remove.*?<p class="field-error">Enter a valid hostname \(for example shop\.example\.com\)\.</p>#s',
            $html,
        );
        $this->assertSame(3, substr_count($html, 'data-alias-remove aria-label'));
        $this->assertSame(0, Site::query()->count());
    }

    public function test_readonly_form_has_no_remove_buttons(): void
    {
        $site = Site::factory()->create(['primary_domain' => 'izyem.test']);
        $site->domains()->createMany([
            ['domain' => 'izyem.test', 'is_primary' => true],
            ['domain' => 'shop.izyem.test'],
        ]);

        $viewer = User::factory()->create();
        $viewer->assignRole(OpsRole::Viewer->value);

        $this->actingAs($viewer)
            ->get(route('ops.sites.edit', $site))
            ->assertOk()
            ->assertDontSee('data-alias-remove', false)
            ->assertDontSee('data-alias-add', false);
    }

    private function operator(): User
    {
        $user = User::factory()->create();
        $user->assignRole(OpsRole::Operator->value);

        return $user;
    }
}
