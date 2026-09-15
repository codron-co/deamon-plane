<?php

namespace Tests\Feature\Sites;

use App\Enums\OpsRole;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteDangerArchiveLinkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_danger_section_points_to_the_archive_page(): void
    {
        $site = Site::factory()->create(['coolify_app_uuid' => null]);
        $user = User::factory()->create();
        $user->assignRole(OpsRole::SuperAdmin->value);

        $html = (string) $this->actingAs($user)->get(route('ops.sites.show', $site))->assertOk()->getContent();

        preg_match('#<section id="danger".*?</section>#s', $html, $danger);
        $this->assertNotEmpty($danger);
        $this->assertStringContainsString('href="'.route('ops.sites.archived').'" data-danger-archive-link', $danger[0]);
        $this->assertStringContainsString(__('sites.danger.archive_hint'), $danger[0]);
    }
}
