<?php

namespace Tests\Feature\Sites;

use App\Enums\OpsRole;
use App\Enums\SiteStatus;
use App\Models\CoolifyConnection;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SiteArchiveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Http::preventStrayRequests();
    }

    public function test_archive_page_lists_only_archived_sites(): void
    {
        Site::factory()->create(['name' => 'Live Shop', 'slug' => 'live']);
        $archived = Site::factory()->create(['name' => 'Old Shop', 'slug' => 'old', 'primary_domain' => 'old.example.test']);
        $archived->delete();

        $this->actingAs($this->user(OpsRole::Viewer))
            ->get(route('ops.sites.archived'))
            ->assertOk()
            ->assertSee('Old Shop', false)
            ->assertSee('old.example.test', false)
            ->assertDontSee('Live Shop', false)
            ->assertDontSee(route('ops.sites.restore', $archived), false)
            ->assertDontSee(route('ops.sites.purge', $archived), false);
    }

    public function test_index_links_to_the_archive_and_archiving_says_where_it_went(): void
    {
        $site = Site::factory()->create(['slug' => 'gone']);

        $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.sites'))
            ->assertOk()
            ->assertSee(route('ops.sites.archived'), false);

        $this->actingAs($this->user(OpsRole::Operator))
            ->delete(route('ops.sites.destroy', $site))
            ->assertRedirect(route('ops.sites'))
            ->assertSessionHas('status', __('sites.flash.archived').' '.__('sites.archive.where'));
    }

    public function test_super_admin_restores_an_archived_site(): void
    {
        $site = Site::factory()->create(['name' => 'Old Shop', 'slug' => 'old', 'status' => SiteStatus::Active]);
        $site->delete();

        $this->actingAs($this->user(OpsRole::SuperAdmin))
            ->get(route('ops.sites.archived'))
            ->assertSee(route('ops.sites.restore', $site), false);

        $this->actingAs($this->user(OpsRole::SuperAdmin))
            ->post(route('ops.sites.restore', $site))
            ->assertRedirect(route('ops.sites.show', $site))
            ->assertSessionHas('status', __('sites.archive.restored', ['name' => 'Old Shop']));

        $this->assertNull($site->fresh()->deleted_at);
        $this->assertSame(SiteStatus::Active, $site->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'site.restored', 'subject_id' => $site->id]);
    }

    public function test_operator_cannot_restore(): void
    {
        $site = Site::factory()->create(['slug' => 'old']);
        $site->delete();

        $this->actingAs($this->user(OpsRole::Operator))
            ->post(route('ops.sites.restore', $site))
            ->assertForbidden();

        $this->assertNotNull(Site::withTrashed()->find($site->id)->deleted_at);
    }

    public function test_archived_site_can_be_hard_deleted_from_the_archive(): void
    {
        $connection = CoolifyConnection::factory()->create(['base_url' => 'https://coolify.example', 'api_token' => 'archive-token']);
        $site = Site::factory()->create([
            'slug' => 'old',
            'coolify_app_uuid' => 'old-app',
            'coolify_connection_id' => $connection->id,
        ]);
        $site->delete();

        Http::fake(['https://coolify.example/api/v1/applications/old-app*' => Http::response('', 200)]);

        $this->actingAs($this->user(OpsRole::Operator))
            ->delete(route('ops.sites.purge', $site))
            ->assertRedirect(route('ops.sites'));

        $this->assertNull(Site::withTrashed()->find($site->id));
        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && str_contains($request->url(), '/applications/old-app'));
    }

    public function test_live_site_is_not_restorable(): void
    {
        $site = Site::factory()->create(['slug' => 'live']);

        $this->actingAs($this->user(OpsRole::SuperAdmin))
            ->post(route('ops.sites.restore', $site))
            ->assertRedirect(route('ops.sites.show', $site))
            ->assertSessionHas('error', __('sites.archive.not_archived'));
    }

    private function user(OpsRole $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);

        return $user;
    }
}
