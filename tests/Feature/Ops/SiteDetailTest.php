<?php

namespace Tests\Feature\Ops;

use App\Enums\Channel;
use App\Enums\OpsRole;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteDetailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_operator_can_open_site_detail_separately_from_edit(): void
    {
        $site = Site::factory()->create([
            'name' => 'Detail Site',
            'slug' => 'detail-site',
            'channel' => Channel::Alpha,
            'last_health_payload' => ['deamon_version' => '1.8.4'],
        ]);

        $operator = $this->user(OpsRole::Operator);

        $this->actingAs($operator)
            ->get(route('ops.sites.show', $site))
            ->assertOk()
            ->assertSee('Operations center', false)
            ->assertSee('Overview', false)
            ->assertSee('Live release', false)
            ->assertSee('Technical identifiers', false)
            ->assertSee('href="#deployments"', false)
            ->assertSee('href="#infrastructure"', false)
            ->assertSee('alpha', false)
            ->assertSee('1.8.4', false)
            ->assertSee('data-favicon-host="'.$site->primary_domain.'"', false)
            ->assertSee(route('ops.sites.edit', $site), false);

        $this->assertNotSame(route('ops.sites.show', $site), route('ops.sites.edit', $site));
    }

    public function test_sites_index_targets_detail_and_displays_branch_with_reported_version(): void
    {
        $site = Site::factory()->create([
            'name' => 'Versioned Site',
            'slug' => 'versioned-site',
            'channel' => Channel::Beta,
            'last_health_payload' => ['version' => '2.3.1'],
        ]);

        $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.sites'))
            ->assertOk()
            ->assertSee('Repo branch', false)
            ->assertSee('2.3.1', false)
            ->assertSee('data-href="'.route('ops.sites.show', $site).'"', false)
            ->assertSee(route('ops.sites.edit', $site), false);
    }

    public function test_viewer_can_open_detail_but_does_not_get_edit_action(): void
    {
        $site = Site::factory()->create([
            'name' => 'Read Only Detail',
            'slug' => 'read-only-detail',
        ]);

        $this->actingAs($this->user(OpsRole::Viewer))
            ->get(route('ops.sites.show', $site))
            ->assertOk()
            ->assertSee('Read Only Detail', false)
            ->assertDontSee('Edit site', false);
    }

    private function user(OpsRole $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);

        return $user;
    }
}
