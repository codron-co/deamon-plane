<?php

namespace Tests\Feature\Mail;

use App\Enums\OpsRole;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SitePlatformMailTableTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_weekly_report_and_version_threshold_use_labelled_selects(): void
    {
        $site = Site::factory()->create([
            'coolify_app_uuid' => null,
            'platform_notification_overrides' => [
                'weekly_visitor_report' => ['enabled' => true, 'day' => 3, 'hour' => 9],
                'site_version_update' => ['enabled' => true, 'on' => 'minor'],
            ],
        ]);

        $user = User::factory()->create();
        $user->assignRole(OpsRole::Operator->value);

        $html = (string) $this->actingAs($user)->get(route('ops.sites.show', $site))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#<select[^>]*name="notifications\[weekly_visitor_report\]\[day\]"#', $html);
        $this->assertMatchesRegularExpression('#<select[^>]*name="notifications\[weekly_visitor_report\]\[hour\]"#', $html);
        $this->assertMatchesRegularExpression('#<option value="3" selected>'.preg_quote(__('platform_mail.weekdays.3'), '#').'</option>#', $html);
        $this->assertMatchesRegularExpression('#<option value="9" selected>09:00</option>#', $html);
        $this->assertMatchesRegularExpression('#<option value="minor" selected>'.preg_quote(__('platform_mail.version_thresholds.minor'), '#').'</option>#', $html);

        $this->assertStringNotContainsString('aria-label="day"', $html);
        $this->assertStringNotContainsString('aria-label="hour"', $html);
        $this->assertStringNotContainsString('type="number" min="0" max="6"', $html);
    }
}
