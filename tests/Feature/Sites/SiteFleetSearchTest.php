<?php

namespace Tests\Feature\Sites;

use App\Enums\OpsRole;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * An operator searching the fleet pastes whatever they have in hand: a `www.` host,
 * an alias, the temporary preview host, or the Coolify app uuid they just copied out
 * of Coolify. Every one of those must find the site, exactly once, and the row must
 * say which host matched when the name and primary domain do not show it.
 */
class SiteFleetSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_an_alias_host_finds_its_site_exactly_once(): void
    {
        $site = Site::factory()->create([
            'slug' => 'beyazlar',
            'name' => 'Beyazlar Otel',
            'primary_domain' => 'beyazlar.test',
        ]);
        // Two hosts match the term: an exists subquery must not turn that into two rows.
        SiteDomain::factory()->create(['site_id' => $site->id, 'domain' => 'kumsal.test', 'is_primary' => false]);
        SiteDomain::factory()->create(['site_id' => $site->id, 'domain' => 'www.kumsal.test', 'is_www' => true]);

        $other = Site::factory()->create(['slug' => 'digeri', 'name' => 'Diger Site', 'primary_domain' => 'digeri.test']);

        $response = $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.sites', ['q' => 'kumsal']))
            ->assertOk()
            ->assertSee('Beyazlar Otel')
            ->assertDontSee('Diger Site');

        $this->assertSame(
            1,
            substr_count($response->getContent(), 'data-site-id="'.$site->id.'"'),
            'A site with two matching alias hosts must still be one row.'
        );
        $this->assertStringNotContainsString('data-site-id="'.$other->id.'"', $response->getContent());
    }

    public function test_a_temporary_preview_host_is_searchable(): void
    {
        $site = Site::factory()->create(['name' => 'Onizleme Sitesi', 'primary_domain' => 'onizleme.test']);
        SiteDomain::factory()->create([
            'site_id' => $site->id,
            'domain' => 'r4nd0m.sslip.io',
            'is_temporary' => true,
        ]);
        Site::factory()->create(['name' => 'Alakasiz Site']);

        $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.sites', ['q' => 'r4nd0m.sslip.io']))
            ->assertOk()
            ->assertSee('Onizleme Sitesi')
            ->assertDontSee('Alakasiz Site');
    }

    public function test_a_full_coolify_app_uuid_finds_exactly_that_site(): void
    {
        $site = Site::factory()->create([
            'name' => 'Uuid Sitesi',
            'coolify_app_uuid' => 'crxguq6nodorlzy88wf9x305',
        ]);
        Site::factory()->create(['name' => 'Baska Site', 'coolify_app_uuid' => 'zzz111nodorlzy88wf9x305']);

        $response = $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.sites', ['q' => 'crxguq6nodorlzy88wf9x305']))
            ->assertOk()
            ->assertSee('Uuid Sitesi')
            ->assertDontSee('Baska Site');

        $this->assertSame(1, substr_count($response->getContent(), 'data-site-id="'.$site->id.'"'));
    }

    public function test_a_partial_uuid_is_not_a_match(): void
    {
        Site::factory()->create(['name' => 'Uuid Sitesi', 'coolify_app_uuid' => 'crxguq6nodorlzy88wf9x305']);

        // The uuid leg is an exact match on purpose: a prefix would silently pull in
        // every app on the same server and read like a broken search.
        $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.sites', ['q' => 'crxguq6']))
            ->assertOk()
            ->assertDontSee('Uuid Sitesi');
    }

    public function test_a_wildcard_term_is_not_honoured_as_a_wildcard(): void
    {
        Site::factory()->create(['name' => 'Yuzde Site', 'primary_domain' => 'yuzde.test']);
        $aliased = Site::factory()->create(['name' => 'Alias Site', 'primary_domain' => 'alias.test']);
        SiteDomain::factory()->create(['site_id' => $aliased->id, 'domain' => 'alias-ikinci.test']);

        // `%` and `_` stay literal (addcslashes), so a wildcard term must not return the
        // fleet — including through the new alias leg.
        $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.sites', ['q' => '%']))
            ->assertOk()
            ->assertDontSee('Yuzde Site')
            ->assertDontSee('Alias Site');

        $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.sites', ['q' => '_']))
            ->assertOk()
            ->assertDontSee('Yuzde Site')
            ->assertDontSee('Alias Site');
    }

    public function test_a_row_matched_by_an_alias_says_which_host_matched(): void
    {
        $site = Site::factory()->create([
            'slug' => 'beyazlar',
            'name' => 'Beyazlar Otel',
            'primary_domain' => 'beyazlar.test',
        ]);
        SiteDomain::factory()->create(['site_id' => $site->id, 'domain' => 'www.kumsal.test', 'is_www' => true]);

        // A Turkish operator must read Turkish: the string comes from lang/tr, not a fallback.
        $this->actingAs($this->user(OpsRole::Operator, 'tr'))
            ->get(route('ops.sites', ['q' => 'kumsal']))
            ->assertOk()
            ->assertSee(trans('sites.search_match.alias', ['value' => 'www.kumsal.test'], 'tr'))
            ->assertDontSee('Matched host');
    }

    public function test_a_row_matched_by_a_uuid_says_so(): void
    {
        Site::factory()->create([
            'name' => 'Uuid Sitesi',
            'coolify_app_uuid' => 'crxguq6nodorlzy88wf9x305',
        ]);

        $this->actingAs($this->user(OpsRole::Operator, 'tr'))
            ->get(route('ops.sites', ['q' => 'crxguq6nodorlzy88wf9x305']))
            ->assertOk()
            ->assertSee(trans('sites.search_match.uuid', ['value' => 'crxguq6nodorlzy88wf9x305'], 'tr'))
            ->assertDontSee('Matched Coolify uuid');
    }

    public function test_a_visible_match_stays_quiet(): void
    {
        $site = Site::factory()->create([
            'slug' => 'beyazlar',
            'name' => 'Beyazlar Otel',
            'primary_domain' => 'beyazlar.test',
        ]);
        SiteDomain::factory()->create(['site_id' => $site->id, 'domain' => 'beyazlar.test', 'is_primary' => true]);

        // The term is already on screen in the name, so explaining the match would be noise.
        $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.sites', ['q' => 'Beyazlar']))
            ->assertOk()
            ->assertSee('Beyazlar Otel')
            ->assertDontSee('site-search-match', false);
    }

    public function test_an_unfiltered_list_never_loads_domains_or_explains_matches(): void
    {
        $site = Site::factory()->create(['name' => 'Sade Site']);
        SiteDomain::factory()->create(['site_id' => $site->id, 'domain' => 'sade.test', 'is_primary' => true]);

        $queries = $this->countDomainQueries(fn () => $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.sites'))
            ->assertOk()
            ->assertDontSee('site-search-match', false));

        $this->assertSame(0, $queries, 'Browsing the fleet must not pay for host lookups it does not render.');
    }

    public function test_a_search_loads_hosts_once_for_the_whole_page(): void
    {
        foreach (range(1, 6) as $index) {
            $site = Site::factory()->create(['name' => 'Kumsal '.$index, 'primary_domain' => 'kumsal-'.$index.'.test']);
            SiteDomain::factory()->create(['site_id' => $site->id, 'domain' => 'alias-kumsal-'.$index.'.test']);
        }

        $queries = $this->countDomainQueries(fn () => $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.sites', ['q' => 'alias-kumsal']))
            ->assertOk()
            ->assertSee('Kumsal 1'));

        // One eager load for the page, not one lookup per row.
        $this->assertSame(1, $queries);
    }

    /**
     * Statements that read `site_domains` on their own. The `whereHas` exists clause is
     * part of the sites query, not a separate read, so it is deliberately not counted.
     */
    private function countDomainQueries(callable $run): int
    {
        $count = 0;
        DB::listen(function ($query) use (&$count): void {
            if (str_starts_with($query->sql, 'select * from "site_domains"')) {
                $count++;
            }
        });

        $run();

        return $count;
    }

    private function user(OpsRole $role, ?string $locale = null): User
    {
        $user = User::factory()->create($locale === null ? [] : ['locale' => $locale]);
        $user->assignRole($role->value);

        return $user;
    }
}
