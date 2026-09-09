<?php

namespace Tests\Feature\Ops;

use App\Enums\OpsRole;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_viewer_cannot_post_settings(): void
    {
        $viewer = User::factory()->create();
        $viewer->assignRole(OpsRole::Viewer->value);

        $this->actingAs($viewer)
            ->post(route('ops.settings.update'))
            ->assertForbidden();
    }

    public function test_operator_can_post_settings_stub(): void
    {
        $operator = User::factory()->create();
        $operator->assignRole(OpsRole::Operator->value);

        $this->actingAs($operator)
            ->post(route('ops.settings.update'))
            ->assertRedirect();
    }

    public function test_viewer_can_read_settings(): void
    {
        $viewer = User::factory()->create();
        $viewer->assignRole(OpsRole::Viewer->value);

        $this->actingAs($viewer)
            ->get(route('ops.settings'))
            ->assertOk();
    }
}
