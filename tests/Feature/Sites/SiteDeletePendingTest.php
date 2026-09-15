<?php

namespace Tests\Feature\Sites;

use App\Enums\OpsRole;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SiteDeletePendingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Http::preventStrayRequests();
    }

    public function test_every_delete_form_on_the_detail_page_shows_a_pending_state(): void
    {
        $site = Site::factory()->create(['coolify_app_uuid' => null]);
        Http::fake();

        $user = User::factory()->create();
        $user->assignRole(OpsRole::SuperAdmin->value);

        $html = $this->actingAs($user)->get(route('ops.sites.show', $site))->assertOk()->getContent();

        preg_match_all('#<form\b[^>]*data-delete-site="(soft|hard)"[^>]*>(.*?)</form>#s', $html, $forms, PREG_SET_ORDER);

        // Danger tab and the Settings menu each carry a soft and a hard delete.
        $this->assertCount(4, $forms);
        foreach ($forms as $form) {
            $this->assertStringContainsString('data-ops-pending', $form[0]);
            $this->assertStringContainsString('data-pending-label="'.__('ops.actions.working').'"', $form[2]);
        }
    }
}
