<?php

namespace Tests\Feature\Sites;

use App\Enums\OpsRole;
use App\Enums\SiteImportance;
use App\Models\AuditLog;
use App\Models\Site;
use App\Models\SiteTag;
use App\Models\User;
use App\Services\Sites\SiteListSummary;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SiteTagsAndImportanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Http::preventStrayRequests();
    }

    public function test_bulk_create_makes_the_tag_and_attaches_it_to_the_selection(): void
    {
        $first = Site::factory()->create(['name' => 'Alpha']);
        $second = Site::factory()->create(['name' => 'Beta']);
        $untouched = Site::factory()->create(['name' => 'Gamma']);

        $this->actingAs($this->operator())
            ->postJson(route('ops.sites.bulk.tags'), [
                'site_ids' => [$first->id, $second->id],
                'tag_op' => 'create',
                'new_tag_name' => ' VIP müşteri ',
                'new_tag_color' => 'purple',
            ])
            ->assertOk()
            ->assertJson(['ok' => true, 'refresh_list' => true, 'keep_selection' => true]);

        $tag = SiteTag::query()->sole();
        $this->assertSame('VIP müşteri', $tag->name);
        $this->assertSame('purple', $tag->color);
        $this->assertEqualsCanonicalizing([$first->id, $second->id], $tag->sites()->pluck('sites.id')->all());
        $this->assertSame(0, $untouched->tags()->count());
        $this->assertTrue(AuditLog::query()->where('action', 'site_tag.attached')->exists());
    }

    public function test_bulk_create_reuses_a_tag_with_the_same_name(): void
    {
        $site = Site::factory()->create();
        $tag = SiteTag::query()->create(['name' => 'Kurumsal', 'color' => 'blue']);

        $this->actingAs($this->operator())
            ->postJson(route('ops.sites.bulk.tags'), [
                'site_ids' => [$site->id],
                'tag_op' => 'create',
                'new_tag_name' => 'KURUMSAL',
            ])
            ->assertOk();

        $this->assertSame(1, SiteTag::query()->count());
        $this->assertSame([$tag->id], $site->tags()->pluck('site_tags.id')->all());
    }

    public function test_bulk_create_without_a_name_is_refused(): void
    {
        $site = Site::factory()->create();

        $this->actingAs($this->operator())
            ->postJson(route('ops.sites.bulk.tags'), [
                'site_ids' => [$site->id],
                'tag_op' => 'create',
                'new_tag_name' => '   ',
            ])
            ->assertStatus(422)
            ->assertJson(['ok' => false]);

        $this->assertSame(0, SiteTag::query()->count());
    }

    public function test_attach_and_detach_an_existing_tag(): void
    {
        $site = Site::factory()->create();
        $other = Site::factory()->create();
        $tag = SiteTag::query()->create(['name' => 'E-ticaret', 'color' => 'green']);
        $tag->sites()->attach($other->id);

        $operator = $this->operator();

        $this->actingAs($operator)
            ->postJson(route('ops.sites.bulk.tags'), ['site_ids' => [$site->id, $other->id], 'tag_op' => 'attach:'.$tag->id])
            ->assertOk();
        $this->assertSame(2, $tag->sites()->count());

        $this->actingAs($operator)
            ->postJson(route('ops.sites.bulk.tags'), ['site_ids' => [$other->id], 'tag_op' => 'detach:'.$tag->id])
            ->assertOk();
        $this->assertSame([$site->id], $tag->sites()->pluck('sites.id')->all());
    }

    public function test_select_all_tags_every_site_matching_the_filter_not_only_the_page(): void
    {
        $main = Site::factory()->count(2)->create(['channel' => 'main']);
        $beta = Site::factory()->create(['channel' => 'beta']);
        $tag = SiteTag::query()->create(['name' => 'Main filo', 'color' => 'gray']);

        $this->actingAs($this->operator())
            ->postJson(route('ops.sites.bulk.tags'), [
                'all' => '1',
                'filter_channel' => 'main',
                'tag_op' => 'attach:'.$tag->id,
            ])
            ->assertOk();

        $this->assertEqualsCanonicalizing($main->pluck('id')->all(), $tag->sites()->pluck('sites.id')->all());
        $this->assertSame(0, $beta->tags()->count());
    }

    public function test_viewer_cannot_tag_or_change_importance(): void
    {
        $site = Site::factory()->create();
        $tag = SiteTag::query()->create(['name' => 'Salt okunur', 'color' => 'gray']);
        $viewer = $this->viewer();

        $this->actingAs($viewer)
            ->postJson(route('ops.sites.bulk.tags'), ['site_ids' => [$site->id], 'tag_op' => 'attach:'.$tag->id])
            ->assertForbidden();
        $this->actingAs($viewer)
            ->postJson(route('ops.sites.bulk.importance'), ['site_ids' => [$site->id], 'importance' => 'critical'])
            ->assertForbidden();
        $this->actingAs($viewer)
            ->postJson(route('ops.site-tags.update', $tag), ['tag_names' => [$tag->id => 'Yeni']])
            ->assertForbidden();
        $this->actingAs($viewer)
            ->postJson(route('ops.site-tags.destroy', $tag))
            ->assertForbidden();

        $this->assertSame(0, $tag->sites()->count());
        $this->assertSame('Salt okunur', $tag->fresh()->name);
        $this->assertSame(SiteImportance::Normal, $site->fresh()->importanceLevel());
    }

    public function test_rename_recolour_and_delete_a_tag(): void
    {
        $site = Site::factory()->create();
        $tag = SiteTag::query()->create(['name' => 'Eski', 'color' => 'gray']);
        SiteTag::query()->create(['name' => 'Dolu', 'color' => 'red']);
        $tag->sites()->attach($site->id);
        $operator = $this->operator();

        $this->actingAs($operator)
            ->postJson(route('ops.site-tags.update', $tag), [
                'tag_names' => [$tag->id => 'Yeni ad'],
                'tag_colors' => [$tag->id => 'teal'],
            ])
            ->assertOk();
        $this->assertSame(['Yeni ad', 'teal'], [$tag->fresh()->name, $tag->fresh()->color]);

        // A name another tag already uses is refused, whatever its case.
        $this->actingAs($operator)
            ->postJson(route('ops.site-tags.update', $tag), ['tag_names' => [$tag->id => 'dolu']])
            ->assertStatus(422);
        $this->assertSame('Yeni ad', $tag->fresh()->name);

        $this->actingAs($operator)
            ->postJson(route('ops.site-tags.destroy', $tag))
            ->assertOk();
        $this->assertNull(SiteTag::query()->find($tag->id));
        $this->assertSame(0, $site->tags()->count());
        $this->assertNotNull($site->fresh());
    }

    public function test_bulk_importance_sets_the_level_without_touching_updated_at(): void
    {
        $site = Site::factory()->create();
        Site::query()->whereKey($site->id)->toBase()->update(['updated_at' => '2026-01-01 00:00:00']);
        $other = Site::factory()->create();

        $this->actingAs($this->operator())
            ->postJson(route('ops.sites.bulk.importance'), ['site_ids' => [$site->id], 'importance' => 'critical'])
            ->assertOk()
            ->assertJson(['ok' => true, 'keep_selection' => true]);

        $fresh = $site->fresh();
        $this->assertSame(SiteImportance::Critical, $fresh->importance);
        $this->assertSame('2026-01-01 00:00:00', $fresh->updated_at->format('Y-m-d H:i:s'));
        $this->assertSame(SiteImportance::Normal, $other->fresh()->importanceLevel());
        $this->assertSame(1, AuditLog::query()->where('action', 'site.importance_changed')->count());
    }

    public function test_list_filters_by_tag_untagged_and_importance(): void
    {
        $tagged = Site::factory()->create(['name' => 'Tagged Site']);
        $critical = Site::factory()->create(['name' => 'Critical Site', 'importance' => SiteImportance::Critical]);
        $important = Site::factory()->create(['name' => 'Important Site', 'importance' => SiteImportance::Important]);
        $tag = SiteTag::query()->create(['name' => 'Ajans', 'color' => 'blue']);
        $tag->sites()->attach($tagged->id);

        $ids = static fn (array $filters): array => Site::query()->matchingListFilters(...$filters)->pluck('id')->all();

        $this->assertSame([$tagged->id], $ids(['tag' => $tag->id]));
        $this->assertEqualsCanonicalizing([$critical->id, $important->id], $ids(['tag' => 'none']));
        $this->assertEqualsCanonicalizing([$critical->id, $important->id], $ids(['importance' => 'flagged']));
        $this->assertSame([$critical->id], $ids(['importance' => 'critical']));
        $this->assertSame([$tagged->id], $ids(['importance' => 'normal']));

        $this->actingAs($this->operator())
            ->get(route('ops.sites', ['tag' => $tag->id]))
            ->assertOk()
            ->assertSee('Tagged Site')
            ->assertDontSee('Critical Site')
            ->assertSee('name="filter_tag" value="'.$tag->id.'"', false);

        $this->actingAs($this->operator())
            ->get(route('ops.sites', ['importance' => 'flagged']))
            ->assertOk()
            ->assertSee('Critical Site')
            ->assertSee('Important Site')
            ->assertDontSee('Tagged Site');
    }

    public function test_index_renders_tags_badges_bulk_menus_and_the_important_tile(): void
    {
        $site = Site::factory()->create(['name' => 'Shown Site', 'importance' => SiteImportance::Critical]);
        $tag = SiteTag::query()->create(['name' => 'Görünür etiket', 'color' => 'pink']);
        $tag->sites()->attach($site->id);

        $html = (string) $this->actingAs($this->operator())
            ->get(route('ops.sites'))
            ->assertOk()
            ->assertSee('Görünür etiket')
            ->assertSee(__('sites.summary.important'))
            ->assertSee('site-importance is-critical', false)
            ->getContent();

        $this->assertStringContainsString('formaction="'.route('ops.sites.bulk.tags').'"', $html);
        $this->assertStringContainsString('formaction="'.route('ops.sites.bulk.importance').'"', $html);
        $this->assertStringContainsString('value="attach:'.$tag->id.'"', $html);
        $this->assertStringContainsString('formaction="'.route('ops.site-tags.update', $tag).'"', $html);
        $this->assertStringContainsString('name="new_tag_name"', $html);

        // Viewers see the labels but get no bulk bar.
        $this->actingAs($this->viewer())
            ->get(route('ops.sites'))
            ->assertOk()
            ->assertSee('Görünür etiket')
            ->assertDontSee('name="new_tag_name"', false);
    }

    public function test_important_summary_counts_flagged_sites_and_their_problems(): void
    {
        Site::factory()->create(['importance' => SiteImportance::Important]);
        $broken = Site::factory()->create(['importance' => SiteImportance::Critical]);
        $unflagged = Site::factory()->create();
        // The verdict column is stamped on save from the payload; write it directly.
        Site::query()->whereIn('id', [$broken->id, $unflagged->id])->toBase()->update(['app_has_issues' => true]);

        $counts = app(SiteListSummary::class)->importantCounts();

        $this->assertSame(2, $counts['total']);
        $this->assertSame(1, $counts['critical']);
        $this->assertSame(1, $counts['problems']);
    }

    private function operator(): User
    {
        $user = User::factory()->create();
        $user->assignRole(OpsRole::Operator->value);

        return $user;
    }

    private function viewer(): User
    {
        $user = User::factory()->create();
        $user->assignRole(OpsRole::Viewer->value);

        return $user;
    }
}
