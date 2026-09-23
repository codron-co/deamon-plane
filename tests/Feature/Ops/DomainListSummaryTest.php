<?php

namespace Tests\Feature\Ops;

use App\Enums\OpsRole;
use App\Models\SiteDomain;
use App\Models\User;
use App\Support\Lists\ListFragment;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Domains list in the workspace pattern: registry tiles above and the
 * unbound filter as a segment in the toolbar.
 */
class DomainListSummaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_tiles_count_bound_unbound_and_temporary_hosts(): void
    {
        SiteDomain::factory()->count(2)->create(['verified_at' => now()]);
        SiteDomain::factory()->count(3)->create(['verified_at' => null]);
        SiteDomain::factory()->create(['verified_at' => null, 'is_temporary' => true]);

        $html = $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.domains'))
            ->assertOk()
            ->assertSee('class="plane-metrics"', false)
            ->assertSee('class="plane-workspace"', false)
            ->assertSee(__('domains.summary.total'))
            ->assertSee(__('domains.summary.bound'))
            ->assertSee(__('domains.summary.temporary'))
            ->assertSee(e(route('ops.domains', ['unbound' => 1])), false)
            ->assertSee('name="unbound"', false)
            ->assertSee('form="domains-list-filters"', false)
            ->getContent();

        $this->assertMatchesRegularExpression('#'.preg_quote(__('domains.summary.total'), '#').'</span>[\s\S]*?<strong>6</strong>#', $html);
        $this->assertMatchesRegularExpression('#data-domains-summary="bound"[\s\S]*?<strong>2</strong>#', $html);
        // Unbound follows the list filter: temporary hosts are not counted.
        $this->assertMatchesRegularExpression('#data-domains-summary="unbound"[\s\S]*?<strong>3</strong>#', $html);
        $this->assertMatchesRegularExpression('#data-domains-summary="temporary"[\s\S]*?<strong>1</strong>#', $html);
        $this->assertMatchesRegularExpression('#name="unbound" value="1"[^>]*>\s*<span>'.preg_quote(e(__('domains.filter_unbound')), '#').'</span>\s*<span class="plane-segment-count">3</span>#', $html);
    }

    public function test_the_unbound_segment_is_checked_when_filtered_and_chips_name_it(): void
    {
        SiteDomain::factory()->create(['domain' => 'loose.example.test', 'verified_at' => null]);
        SiteDomain::factory()->create(['domain' => 'bound.example.test', 'verified_at' => now()]);

        $html = $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.domains', ['unbound' => 1]))
            ->assertOk()
            ->assertSee('loose.example.test')
            ->assertDontSee('bound.example.test')
            ->assertSee('ops-filter-chips', false)
            ->getContent();

        $this->assertMatchesRegularExpression('#name="unbound" value="1"\s+form="domains-list-filters"\s+data-ops-list-filter\s+checked#', $html);
    }

    public function test_the_async_region_leaves_the_tiles_alone(): void
    {
        SiteDomain::factory()->create(['domain' => 'shop.example.test']);

        $this->actingAs($this->user(OpsRole::Operator))
            ->withHeader(ListFragment::HEADER, ListFragment::VALUE)
            ->get(route('ops.domains', ['q' => 'shop']))
            ->assertOk()
            ->assertHeader('X-Ops-List-Region', '1')
            ->assertSee('shop.example.test')
            ->assertDontSee('plane-metrics', false);
    }

    private function user(OpsRole $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);

        return $user;
    }
}
