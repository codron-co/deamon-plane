<?php

namespace Tests\Feature\Ops;

use App\Enums\OpsRole;
use App\Enums\SiteStatus;
use App\Models\CoolifyConnection;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\User;
use App\Support\Lists\ListFragment;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Leftover unbound aliases after a Cloudflare change were 30 per-row
 * deletes. The clearer is the existing bulk bar, danger-confirmed, and
 * never touches Coolify, primaries, temporary hosts, or bound rows.
 */
class DomainBulkClearTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Http::preventStrayRequests();
    }

    public function test_the_bulk_bar_publishes_a_counted_danger_clear_confirm(): void
    {
        $site = $this->siteWithPrimary('shop.example.test', bound: true);
        $this->leftoverOn($site, 'old.shop.example.test');
        $this->leftoverOn($site, 'also.shop.example.test');

        $html = $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.domains', ['unbound' => 1]))
            ->assertOk()
            ->assertSee(route('ops.domains.bulk-clear'), false)
            ->getContent();

        $this->assertStringContainsString(
            'data-confirm-template="'.e(__('domains.bulk.confirm_clear', ['count' => '__COUNT__'])).'"',
            $html,
        );
        $this->assertStringContainsString(
            'data-confirm="'.e(__('domains.bulk.confirm_clear', ['count' => 2])).'"',
            $html,
        );
        $this->assertStringContainsString('data-confirm-danger="true"', $html);
        $this->assertStringContainsString(__('domains.bulk.confirm_clear_title'), $html);
    }

    public function test_all_equals_one_under_unbound_deletes_leftovers_and_spares_primaries(): void
    {
        $site = $this->siteWithPrimary('shop.example.test', bound: false);
        $keepPrimary = $site->domains()->where('is_primary', true)->firstOrFail();
        $one = $this->leftoverOn($site, 'old.shop.example.test');
        $two = $this->leftoverOn($site, 'www.old.shop.example.test');
        $other = $this->siteWithPrimary('keep.example.test', bound: true);
        $bound = $this->leftoverOn($other, 'alias.keep.example.test');
        $bound->forceFill(['verified_at' => now()])->save();

        $this->actingAs($this->user(OpsRole::Operator))
            ->postJson(route('ops.domains.bulk-clear'), [
                'all' => '1',
                'filter_unbound' => '1',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('refresh_list', true)
            ->assertJsonPath('message', __('domains.flash.cleared_skipped', [
                'count' => 2,
                'skipped' => 1,
            ]));

        $this->assertNull(SiteDomain::query()->find($one->id));
        $this->assertNull(SiteDomain::query()->find($two->id));
        $this->assertNotNull($keepPrimary->fresh());
        $this->assertNotNull($bound->fresh());
        Http::assertNothingSent();
    }

    public function test_selected_bound_primary_and_temporary_hosts_are_skipped(): void
    {
        $live = $this->siteWithPrimary('live.example.test', bound: false);
        $primary = $live->domains()->where('is_primary', true)->firstOrFail();
        $boundSite = $this->siteWithPrimary('bound.example.test', bound: true);
        $bound = $this->leftoverOn($boundSite, 'alias.bound.example.test');
        $bound->forceFill(['verified_at' => now()])->save();
        $temp = $this->temporaryHost('tmp.example.test');
        $gone = $this->siteWithPrimary('gone.example.test', bound: true);
        $leftover = $this->leftoverOn($gone, 'old.gone.example.test');

        $this->actingAs($this->user(OpsRole::Operator))
            ->postJson(route('ops.domains.bulk-clear'), [
                'domain_ids' => [$primary->id, $bound->id, $temp->id, $leftover->id],
            ])
            ->assertOk()
            ->assertJsonPath('refresh_list', true);

        $this->assertNotNull($primary->fresh());
        $this->assertNotNull($bound->fresh());
        $this->assertNotNull($temp->fresh());
        $this->assertNull(SiteDomain::query()->find($leftover->id));
        Http::assertNothingSent();
    }

    public function test_selecting_only_protected_hosts_is_a_422_and_changes_nothing(): void
    {
        $safe = $this->siteWithPrimary('safe.example.test', bound: false);
        $primary = $safe->domains()->where('is_primary', true)->firstOrFail();

        $this->actingAs($this->user(OpsRole::Operator))
            ->postJson(route('ops.domains.bulk-clear'), [
                'domain_ids' => [$primary->id],
            ])
            ->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('message', __('domains.bulk.empty_clear'));

        $this->assertNotNull($primary->fresh());
        Http::assertNothingSent();
    }

    public function test_the_overlay_copy_reads_as_turkish_for_a_turkish_operator(): void
    {
        $site = $this->siteWithPrimary('tr.example.test', bound: true);
        $this->leftoverOn($site, 'old.tr.example.test');

        $this->actingAs($this->user(OpsRole::Operator, 'tr'))
            ->get(route('ops.domains'))
            ->assertOk()
            ->assertSee(trans('domains.bulk.confirm_clear_title', [], 'tr'), false)
            ->assertSee(trans('domains.actions.clear', [], 'tr'), false)
            ->assertDontSee(trans('domains.bulk.confirm_clear_title', [], 'en'), false);
    }

    public function test_a_viewer_cannot_clear_leftovers(): void
    {
        $site = $this->siteWithPrimary('view.example.test', bound: true);
        $row = $this->leftoverOn($site, 'old.view.example.test');

        $this->actingAs($this->user(OpsRole::Viewer))
            ->postJson(route('ops.domains.bulk-clear'), [
                'domain_ids' => [$row->id],
            ])
            ->assertForbidden();

        $this->assertNotNull($row->fresh());
    }

    public function test_the_async_region_reships_the_clear_confirm(): void
    {
        $site = $this->siteWithPrimary('a.example.test', bound: true);
        $this->leftoverOn($site, 'old.a.example.test');

        $this->actingAs($this->user(OpsRole::Operator))
            ->withHeader(ListFragment::HEADER, ListFragment::VALUE)
            ->get(route('ops.domains', ['unbound' => 1]))
            ->assertOk()
            ->assertHeader('X-Ops-List-Region', '1')
            ->assertSee('data-confirm-danger="true"', false)
            ->assertSee(route('ops.domains.bulk-clear'), false);
    }

    private function leftoverOn(Site $site, string $host): SiteDomain
    {
        return SiteDomain::factory()->create([
            'site_id' => $site->id,
            'domain' => $host,
            'is_primary' => false,
            'is_temporary' => false,
            'verified_at' => null,
        ]);
    }

    private function siteWithPrimary(string $host, bool $bound): Site
    {
        $site = $this->site($host, 'app-'.str_replace('.', '-', $host));
        SiteDomain::factory()->primary()->create([
            'site_id' => $site->id,
            'domain' => $host,
            'verified_at' => $bound ? now() : null,
        ]);

        return $site;
    }

    private function temporaryHost(string $host): SiteDomain
    {
        $site = $this->site($host, 'app-temp-'.str_replace('.', '-', $host));

        return SiteDomain::factory()->create([
            'site_id' => $site->id,
            'domain' => $host,
            'is_primary' => false,
            'is_temporary' => true,
            'verified_at' => null,
        ]);
    }

    private function site(string $primary, string $uuid): Site
    {
        $connection = CoolifyConnection::query()->first() ?? CoolifyConnection::factory()->create([
            'base_url' => 'https://coolify.test',
            'api_token' => 'domain-clear-token',
            'is_default' => true,
        ]);

        return Site::factory()->create([
            'name' => $primary,
            'primary_domain' => $primary,
            'status' => SiteStatus::Active,
            'coolify_app_uuid' => $uuid,
            'coolify_connection_id' => $connection->id,
        ]);
    }

    private function user(OpsRole $role, ?string $locale = null): User
    {
        $user = User::factory()->create($locale === null ? [] : ['locale' => $locale]);
        $user->assignRole($role->value);

        return $user;
    }
}
