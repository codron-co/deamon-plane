<?php

namespace Tests\Feature\Sites;

use App\Enums\CmsPublishStatus;
use App\Enums\OpsRole;
use App\Enums\SiteStatus;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sites list cells say one thing per state: an unknown publish state is one
 * chip, a never-probed live check is one quiet label, and App issues are
 * listed in a popover rather than hidden behind a copy-only chip.
 */
class SiteListCellStatesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_unknown_publish_state_renders_the_label_once(): void
    {
        Site::factory()->create([
            'cms_site_status' => null,
            'cms_site_status_at' => null,
        ]);

        $cell = $this->cell($this->listHtml('tr'), 'data-publish-chip');
        $label = e(trans('sites.publish.states.unknown', [], 'tr'));

        $this->assertSame(1, substr_count($cell, $label));
        $this->assertStringContainsString(e(trans('sites.publish.unknown_hint', [], 'tr')), $cell);
        $this->assertStringNotContainsString('ops-freshness', $cell);
    }

    public function test_known_publish_state_keeps_chip_and_freshness(): void
    {
        Site::factory()->create([
            'cms_site_status' => CmsPublishStatus::Published,
            'cms_site_status_at' => now()->subMinutes(5),
        ]);

        $cell = $this->cell($this->listHtml(), 'data-publish-chip');

        $this->assertStringContainsString(CmsPublishStatus::Published->label(), $cell);
        $this->assertStringContainsString('ops-freshness', $cell);
    }

    public function test_never_checked_live_renders_a_single_muted_state(): void
    {
        Site::factory()->create([
            'last_live_http_status' => null,
            'last_live_checked_at' => null,
        ]);

        $cell = $this->cell($this->listHtml('tr'), 'data-live-chip');

        $this->assertStringContainsString('data-live-unchecked', $cell);
        $this->assertStringContainsString(e(trans('sites.live.not_checked', [], 'tr')), $cell);
        $this->assertStringNotContainsString('status-chip', $cell);
        $this->assertStringNotContainsString('ops-freshness', $cell);
        $this->assertStringNotContainsString(trans('ops.never', [], 'tr'), $cell);
    }

    public function test_checked_live_keeps_chip_and_freshness(): void
    {
        Site::factory()->create([
            'last_live_http_status' => 200,
            'last_live_checked_at' => now()->subMinutes(3),
        ]);

        $cell = $this->cell($this->listHtml(), 'data-live-chip');

        $this->assertStringContainsString('status-chip status-ok', $cell);
        $this->assertStringContainsString('>200<', $cell);
        $this->assertStringContainsString('ops-freshness', $cell);
        $this->assertStringNotContainsString('data-live-unchecked', $cell);
    }

    public function test_app_issues_chip_opens_a_popover_listing_each_issue(): void
    {
        $site = Site::factory()->dockerfilePack()->create([
            'name' => 'Legacy Pack Site',
            'status' => SiteStatus::Active,
        ]);
        $view = $site->appHealth()->toView($site);
        $this->assertNotSame([], $view['issues']);

        $cell = $this->cell($this->listHtml(), 'data-app-health-pop');

        $this->assertStringContainsString('data-row-action', $cell);
        $this->assertStringContainsString('data-app-health-trigger', $cell);
        $this->assertStringContainsString('aria-expanded="false"', $cell);
        $this->assertStringContainsString('aria-controls="app-health-pop-'.$site->id.'"', $cell);
        $this->assertStringContainsString('id="app-health-pop-'.$site->id.'"', $cell);
        $this->assertStringContainsString('role="dialog"', $cell);
        $this->assertMatchesRegularExpression('/data-app-health-popover\s+hidden/', $cell);
        $this->assertSame(count($view['issues']), substr_count($cell, '<li>'));
        foreach ($view['issues'] as $issue) {
            $this->assertStringContainsString('<li>'.e($issue['message']).'</li>', $cell);
        }
        // Copy stays available, now as an action inside the popover.
        $this->assertStringContainsString('data-app-health-copy', $cell);
        $this->assertStringContainsString(e(__('sites.app_health.copy')), $cell);
    }

    public function test_healthy_app_keeps_the_simple_chip(): void
    {
        $site = Site::factory()->withSecrets()->create([
            'status' => SiteStatus::Active,
            'coolify_app_uuid' => 'healthy-app-1',
        ]);
        $this->assertSame([], $site->appHealth()->toView($site)['issues'], 'A linked site with secrets should have no App issues.');

        $html = $this->listHtml();

        $this->assertStringNotContainsString('data-app-health-pop', $html);
        $this->assertStringContainsString('data-app-health-copy', $html);
    }

    private function listHtml(?string $locale = null): string
    {
        $user = User::factory()->create($locale === null ? [] : ['locale' => $locale]);
        $user->assignRole(OpsRole::Operator->value);

        return $this->actingAs($user)
            ->get(route('ops.sites'))
            ->assertOk()
            ->getContent();
    }

    /**
     * The table cell that contains the given marker.
     */
    private function cell(string $html, string $marker): string
    {
        $at = strpos($html, $marker);
        $this->assertNotFalse($at, "Marker {$marker} not found.");
        $start = strrpos(substr($html, 0, $at), '<td');
        $end = strpos($html, '</td>', $at);
        $this->assertNotFalse($start);
        $this->assertNotFalse($end);

        return substr($html, $start, $end - $start);
    }
}
