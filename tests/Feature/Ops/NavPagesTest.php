<?php

namespace Tests\Feature\Ops;

use App\Enums\OpsRole;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NavPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_operator_can_open_ops_nav_pages(): void
    {
        $operator = User::factory()->create();
        $operator->assignRole(OpsRole::Operator->value);

        $this->actingAs($operator)->get(route('ops.fleet'))->assertOk();
        $this->actingAs($operator)->get(route('ops.sites'))->assertOk();
        $this->actingAs($operator)->get(route('ops.themes'))->assertOk()->assertSee('git-only', false);
        $this->actingAs($operator)->get(route('ops.coolify.index'))->assertOk()->assertSee('Coolify', false);
        $this->actingAs($operator)
            ->get(route('ops.settings'))
            ->assertOk()
            ->assertSee('GitHub theme catalog', false)
            ->assertDontSee('Coolify is under Coolify menu.', false)
            ->assertDontSee('name="api_token"', false)
            ->assertDontSee('Test connection', false);
    }
}
