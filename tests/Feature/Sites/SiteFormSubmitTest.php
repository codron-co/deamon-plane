<?php

namespace Tests\Feature\Sites;

use App\Enums\OpsRole;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteFormSubmitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_create_and_edit_forms_submit_natively_with_a_pending_state(): void
    {
        $operator = $this->user(OpsRole::Operator);
        $site = Site::factory()->create();

        foreach ([route('ops.sites.create'), route('ops.sites.edit', $site)] as $url) {
            $html = $this->actingAs($operator)->get($url)->assertOk()->getContent();

            // ops-async.js turns every .ops-app POST into fetch unless the form opts out.
            $this->assertMatchesRegularExpression('#<form[^>]*data-ops-native[^>]*data-site-form#', $html, $url);
            $this->assertMatchesRegularExpression('#<form[^>]*data-ops-pending[^>]*data-site-form#', $html, $url);
            $this->assertStringContainsString('data-pending-label="'.__('ops.actions.saving').'"', $html);
        }
    }

    public function test_invalid_create_redirects_back_with_field_errors_and_old_input(): void
    {
        $this->actingAs($this->user(OpsRole::Operator))
            ->from(route('ops.sites.create'))
            ->post(route('ops.sites.store'), [
                'slug' => 'Bad Slug',
                'name' => 'Keep Me',
                'domain' => 'not a host',
                'channel' => 'main',
            ])
            ->assertRedirect(route('ops.sites.create'))
            ->assertSessionHasErrors(['slug', 'domain']);

        $this->actingAs($this->user(OpsRole::Operator))
            ->withSession(['_old_input' => ['name' => 'Keep Me']])
            ->get(route('ops.sites.create'))
            ->assertOk()
            ->assertSee('value="Keep Me"', false);
    }

    private function user(OpsRole $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);

        return $user;
    }
}
