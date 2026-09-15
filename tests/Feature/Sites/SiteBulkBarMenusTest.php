<?php

namespace Tests\Feature\Sites;

use App\Enums\OpsRole;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteBulkBarMenusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_deploy_and_auto_deploy_sweeps_are_grouped_in_menus(): void
    {
        Site::factory()->count(2)->create();
        $user = User::factory()->create();
        $user->assignRole(OpsRole::Operator->value);

        $html = (string) $this->actingAs($user)->get(route('ops.sites'))->assertOk()->getContent();

        $deploy = $this->menu($html, 'deploy');
        foreach (['ops.sites.bulk.deploy', 'ops.sites.bulk.follow-head', 'ops.sites.bulk.pin'] as $name) {
            $this->assertStringContainsString('formaction="'.route($name).'"', $deploy, $name);
        }
        $this->assertStringContainsString('name="ref"', $deploy);

        $auto = $this->menu($html, 'auto');
        $this->assertSame(2, substr_count($auto, 'formaction="'.route('ops.sites.bulk.auto-deploy').'"'));

        // Every sweep kept its confirm.
        foreach ([$deploy, $auto] as $menu) {
            preg_match_all('#<button\b[^>]*formaction=[^>]*>#s', $menu, $buttons);
            foreach ($buttons[0] as $button) {
                $this->assertStringContainsString('data-confirm=', $button);
            }
        }

        preg_match('#<div class="form-actions sites-bulk-actions"[^>]*>(.*?)\n                            <details class="ops-action-menu sites-bulk-danger"#s', $html, $bar);
        $this->assertNotEmpty($bar);
        $topLevel = preg_match_all('#^ {28}<(button|details|label)\b#m', $bar[1]);
        $this->assertLessThanOrEqual(6, $topLevel);
    }

    private function menu(string $html, string $key): string
    {
        preg_match('#<details class="ops-action-menu" data-ops-action-menu data-bulk-menu="'.$key.'">(.*?)</details>#s', $html, $match);
        $this->assertNotEmpty($match, "bulk menu {$key} missing");

        return $match[1];
    }
}
