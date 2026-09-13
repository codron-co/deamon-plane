<?php

namespace Tests\Feature\Ops;

use App\Enums\OpsRole;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\Theme;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The palette itself is browser behavior. These tests pin the JSON contract
 * it consumes: the same scopes as the lists, and only URLs the operator can open.
 */
class PaletteSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_a_guest_cannot_search_the_palette(): void
    {
        $this->getJson(route('ops.palette'))->assertUnauthorized();
        $this->get(route('ops.palette'))->assertRedirect(route('login'));
    }

    public function test_an_empty_query_returns_only_pages_the_operator_can_open(): void
    {
        Site::factory()->create(['name' => 'Gizli Site']);

        $response = $this->actingAs($this->user(OpsRole::Operator))
            ->getJson(route('ops.palette'))
            ->assertOk()
            ->assertJsonPath('groups.0.key', 'pages');

        $this->assertSame(['pages'], $this->groupKeys($response));
        $this->assertContains(route('ops.fleet'), $this->urls($response));
        $this->assertContains(route('ops.sites'), $this->urls($response));
        $this->assertContains(route('ops.themes'), $this->urls($response));
        $this->assertNotContains(route('ops.sites.create'), $this->urls($response));
        $this->assertNotContains(route('ops.jobs'), $this->urls($response));
        $this->assertFalse(
            collect($this->urls($response))->contains(fn (string $url): bool => str_contains($url, '/sites/')),
            'An empty query must not leak site show URLs.'
        );
    }

    public function test_an_alias_host_finds_its_site_exactly_once(): void
    {
        $site = Site::factory()->create([
            'name' => 'Beyazlar Otel',
            'primary_domain' => 'beyazlar.test',
        ]);
        SiteDomain::factory()->create(['site_id' => $site->id, 'domain' => 'kumsal.test', 'is_primary' => false]);
        SiteDomain::factory()->create(['site_id' => $site->id, 'domain' => 'www.kumsal.test', 'is_www' => true]);
        Site::factory()->create(['name' => 'Diger Site', 'primary_domain' => 'digeri.test']);

        $response = $this->actingAs($this->user(OpsRole::Operator))
            ->getJson(route('ops.palette', ['q' => 'kumsal']))
            ->assertOk();

        $siteUrls = array_values(array_filter(
            $this->urls($response, 'sites'),
            fn (string $url): bool => $url === route('ops.sites.show', $site)
        ));

        $this->assertCount(1, $siteUrls);
        $this->assertNotContains(
            route('ops.sites.show', Site::query()->where('name', 'Diger Site')->firstOrFail()),
            $this->urls($response)
        );
    }

    public function test_a_full_coolify_app_uuid_finds_exactly_that_site(): void
    {
        $site = Site::factory()->create([
            'name' => 'Uuid Sitesi',
            'coolify_app_uuid' => 'crxguq6nodorlzy88wf9x305',
        ]);
        Site::factory()->create([
            'name' => 'Baska Site',
            'coolify_app_uuid' => 'aaaaaaaaaaaaaaaaaaaaaaaa',
        ]);

        $response = $this->actingAs($this->user(OpsRole::Operator))
            ->getJson(route('ops.palette', ['q' => 'crxguq6nodorlzy88wf9x305']))
            ->assertOk();

        $this->assertContains(route('ops.sites.show', $site), $this->urls($response, 'sites'));
        $this->assertCount(1, $this->urls($response, 'sites'));
    }

    public function test_a_wildcard_term_is_not_honoured_as_a_wildcard(): void
    {
        $site = Site::factory()->create(['name' => 'Yuzde Site', 'primary_domain' => 'yuzde.test']);
        $theme = Theme::factory()->create(['name' => 'Yuzde Tema', 'theme_id' => 'yuzde-tema']);

        $response = $this->actingAs($this->user(OpsRole::Operator))
            ->getJson(route('ops.palette', ['q' => '%']))
            ->assertOk();

        $this->assertNotContains(route('ops.sites.show', $site), $this->urls($response));
        $this->assertNotContains(route('ops.themes.show', $theme), $this->urls($response));
    }

    public function test_a_theme_is_found_by_repo_and_opens_its_show_page(): void
    {
        $theme = Theme::factory()->publicCatalog()->create([
            'name' => 'Nova Retail',
            'theme_id' => 'nova-retail',
            'repo_full_name' => 'deamon-themes/deamon-theme-nova-retail',
        ]);
        Theme::factory()->create(['theme_id' => 'other-theme', 'name' => 'Other']);

        $response = $this->actingAs($this->user(OpsRole::Operator))
            ->getJson(route('ops.palette', ['q' => 'nova-retail']))
            ->assertOk();

        $this->assertContains(route('ops.themes.show', $theme), $this->urls($response, 'themes'));
        $this->assertCount(1, $this->urls($response, 'themes'));
    }

    public function test_a_bound_domain_jumps_to_its_site(): void
    {
        $site = Site::factory()->create(['name' => 'Kumsal', 'primary_domain' => 'kumsal.test']);
        SiteDomain::factory()->create(['site_id' => $site->id, 'domain' => 'www.kumsal.test', 'is_www' => true]);

        $response = $this->actingAs($this->user(OpsRole::Operator))
            ->getJson(route('ops.palette', ['q' => 'www.kumsal.test']))
            ->assertOk();

        $this->assertContains(route('ops.sites.show', $site), $this->urls($response, 'domains'));
    }

    public function test_a_viewer_gets_the_same_openable_urls_and_never_a_write_only_route(): void
    {
        $site = Site::factory()->create(['name' => 'Izleme Sitesi', 'primary_domain' => 'izle.test']);

        $response = $this->actingAs($this->user(OpsRole::Viewer))
            ->getJson(route('ops.palette', ['q' => 'Izleme']))
            ->assertOk();

        $this->assertContains(route('ops.sites.show', $site), $this->urls($response, 'sites'));
        $this->assertNotContains(route('ops.sites.create'), $this->urls($response));
        $this->assertNotContains(route('ops.jobs'), $this->urls($response));
    }

    public function test_turkish_copy_is_used_for_a_turkish_operator(): void
    {
        $this->actingAs($this->user(OpsRole::Operator, 'tr'))
            ->getJson(route('ops.palette'))
            ->assertOk()
            ->assertJsonPath('groups.0.label', trans('ops.palette.groups.pages', [], 'tr'))
            ->assertJsonFragment(['label' => trans('ops.nav.sites', [], 'tr')])
            ->assertJsonMissing(['label' => trans('ops.nav.sites', [], 'en')]);
    }

    /**
     * @return list<string>
     */
    private function groupKeys(mixed $response): array
    {
        return collect($response->json('groups') ?? [])->pluck('key')->all();
    }

    /**
     * @return list<string>
     */
    private function urls(mixed $response, ?string $group = null): array
    {
        $groups = collect($response->json('groups') ?? []);
        if ($group !== null) {
            $groups = $groups->where('key', $group);
        }

        return $groups
            ->flatMap(static fn (array $row): array => $row['items'] ?? [])
            ->pluck('url')
            ->all();
    }

    private function user(OpsRole $role, ?string $locale = null): User
    {
        $user = User::factory()->create($locale === null ? [] : ['locale' => $locale]);
        $user->assignRole($role->value);

        return $user;
    }
}
