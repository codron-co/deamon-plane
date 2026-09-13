<?php

namespace Tests\Feature\Sites;

use App\Enums\CmsPublishStatus;
use App\Enums\OpsRole;
use App\Models\Site;
use App\Models\User;
use App\Support\Lists\SiteListColumns;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Freshness is a word plus a relative age. A four-hour-old poll used to look
 * identical to a healthy one because the list hid the timestamp in a title
 * attribute. Null stays Hiç / Bilinmiyor and never picks up a stale tone.
 */
class SiteFreshnessBadgeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        config(['ops.agent.poll_minutes' => 10]);
    }

    public function test_a_stale_live_probe_says_so_in_text_for_a_turkish_operator(): void
    {
        Site::factory()->create([
            'name' => 'Eski Canli',
            'last_live_http_status' => 200,
            'last_live_checked_at' => now()->subHours(4),
        ]);

        $html = $this->actingAs($this->user(OpsRole::Operator, 'tr'))
            ->get(route('ops.sites'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(e(trans_choice('ops.freshness.hours', 4, ['count' => 4], 'tr')), $html);
        $this->assertStringContainsString(e(trans('ops.freshness.stale', [], 'tr')), $html);
        $this->assertStringContainsString('ops-freshness is-stale', $html);
        $this->assertStringNotContainsString('>'.trans('ops.freshness.stale', [], 'en').'<', $html);
    }

    public function test_a_null_timestamp_is_never_and_never_stale(): void
    {
        Site::factory()->create([
            'name' => 'Never Probed',
            'last_live_http_status' => null,
            'last_live_checked_at' => null,
            'cms_site_status' => null,
            'cms_site_status_at' => null,
            'last_health_at' => null,
        ]);

        $html = $this->actingAs($this->userWithHealthColumn())
            ->get(route('ops.sites'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(__('ops.never'), $html);
        $this->assertStringContainsString(__('ops.unknown'), $html);
        $this->assertStringNotContainsString('ops-freshness is-stale', $html);
        $this->assertStringNotContainsString(__('ops.freshness.stale'), $html);
    }

    public function test_a_fresh_health_check_has_no_stale_flag(): void
    {
        Site::factory()->create([
            'name' => 'Fresh Health',
            'last_health_at' => now()->subMinutes(8),
        ]);

        $html = $this->actingAs($this->userWithHealthColumn())
            ->get(route('ops.sites'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(trans_choice('ops.freshness.minutes', 8, ['count' => 8]), $html);
        $this->assertStringNotContainsString('ops-freshness is-stale', $html);
    }

    public function test_the_updated_column_is_relative_and_never_stale(): void
    {
        $site = Site::factory()->create(['name' => 'Old Edit']);
        $site->timestamps = false;
        $site->forceFill(['updated_at' => now()->subHours(4)])->save();

        $user = $this->user(OpsRole::Operator, 'tr');
        $user->saveListPreference(SiteListColumns::LIST_KEY, [
            'columns' => [...SiteListColumns::defaults(), 'updated'],
        ]);

        $html = $this->actingAs($user->fresh() ?? $user)
            ->get(route('ops.sites'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(e(trans_choice('ops.freshness.hours', 4, ['count' => 4], 'tr')), $html);
        $this->assertStringContainsString('ops-freshness', $html);
        $this->assertStringNotContainsString('ops-freshness is-stale', $html);
        $this->assertStringNotContainsString(e(trans('ops.freshness.stale', [], 'tr')), $html);
    }

    public function test_site_detail_uses_the_same_primitive_for_agent_and_publish(): void
    {
        $site = Site::factory()->create([
            'last_health_at' => now()->subHours(5),
            'cms_site_status' => CmsPublishStatus::Published,
            'cms_site_status_at' => now()->subHours(5),
        ]);

        $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.sites.show', $site))
            ->assertOk()
            ->assertSee(trans_choice('ops.freshness.hours', 5, ['count' => 5]), false)
            ->assertSee(__('ops.freshness.stale'), false)
            ->assertSee('ops-freshness is-stale', false);
    }

    /**
     * @param  list<string>  $extra
     */
    private function userWithColumns(array $extra): User
    {
        $user = $this->user(OpsRole::Operator);
        $user->saveListPreference(SiteListColumns::LIST_KEY, [
            'columns' => [...SiteListColumns::defaults(), ...$extra],
        ]);

        return $user->fresh() ?? $user;
    }

    private function userWithHealthColumn(): User
    {
        return $this->userWithColumns(['health']);
    }

    private function user(OpsRole $role, ?string $locale = null): User
    {
        $user = User::factory()->create($locale === null ? [] : ['locale' => $locale]);
        $user->assignRole($role->value);

        return $user;
    }
}
