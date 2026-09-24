<?php

namespace Tests\Feature\Ops;

use App\Enums\OpsRole;
use App\Models\AutomationSetting;
use App\Models\Site;
use App\Models\User;
use App\Services\Ops\AutomationGuard;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Audit P13: every automatic action passes one guard with a runtime switch,
 * budgets and a fleet-incident brake.
 */
class AutomationGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Cache::flush();
    }

    public function test_the_env_flag_is_a_floor_the_runtime_switch_cannot_lift(): void
    {
        $guard = app(AutomationGuard::class);
        $this->assertTrue($guard->enabled(AutomationGuard::DEPLOY_AUTO_FIX));

        AutomationSetting::query()->create(['rule' => AutomationGuard::DEPLOY_AUTO_FIX, 'enabled' => false]);
        $this->assertFalse($guard->enabled(AutomationGuard::DEPLOY_AUTO_FIX));
        $this->assertSame('disabled', $guard->deny(AutomationGuard::DEPLOY_AUTO_FIX));

        AutomationSetting::query()->where('rule', AutomationGuard::DEPLOY_AUTO_FIX)->update(['enabled' => true]);
        config(['ops.diagnosis.auto_fix' => false]);
        $this->assertFalse($guard->enabled(AutomationGuard::DEPLOY_AUTO_FIX));
    }

    public function test_a_site_runs_out_of_daily_budget(): void
    {
        $guard = app(AutomationGuard::class);
        $site = Site::factory()->create();
        $limit = AutomationGuard::RULES[AutomationGuard::CORE_THEME_RESTART]['per_site_per_day'];

        foreach (range(1, $limit) as $attempt) {
            $this->assertNull($guard->deny(AutomationGuard::CORE_THEME_RESTART, $site));
        }

        $this->assertSame('site_budget', $guard->deny(AutomationGuard::CORE_THEME_RESTART, $site));
        $this->assertNull($guard->deny(AutomationGuard::CORE_THEME_RESTART, Site::factory()->create()));
    }

    public function test_the_same_signal_on_several_sites_pauses_the_rule_fleet_wide(): void
    {
        $guard = app(AutomationGuard::class);
        $sites = Site::factory()->count(AutomationGuard::INCIDENT_SITES + 1)->create();

        foreach ($sites->take(AutomationGuard::INCIDENT_SITES - 1) as $site) {
            $this->assertNull($guard->deny(AutomationGuard::DEPLOY_AUTO_FIX, $site, 'registry_rate_limited'));
        }

        $third = $sites[AutomationGuard::INCIDENT_SITES - 1];
        $this->assertSame('fleet_incident', $guard->deny(AutomationGuard::DEPLOY_AUTO_FIX, $third, 'registry_rate_limited'));
        $this->assertNotNull($guard->pausedUntil(AutomationGuard::DEPLOY_AUTO_FIX));

        // Paused for everyone, whatever the signal.
        $this->assertSame('fleet_paused', $guard->deny(AutomationGuard::DEPLOY_AUTO_FIX, $sites->last(), 'mysql_no_root_password'));
    }

    public function test_super_admin_switches_a_rule_and_it_is_audited(): void
    {
        $admin = $this->userWith(OpsRole::SuperAdmin);

        $this->actingAs($admin)
            ->get(route('ops.settings'))
            ->assertOk()
            ->assertSee(__('settings.automation.title'), false)
            ->assertSee(route('ops.settings.automation', AutomationGuard::DOMAIN_AUTO_REBIND), false);

        $this->actingAs($admin)
            ->post(route('ops.settings.automation', AutomationGuard::DOMAIN_AUTO_REBIND), ['enabled' => '0'])
            ->assertRedirect();

        $this->assertFalse(app(AutomationGuard::class)->enabled(AutomationGuard::DOMAIN_AUTO_REBIND));
        $this->assertDatabaseHas('audit_logs', ['action' => 'automation.toggled', 'actor_user_id' => $admin->id]);
    }

    public function test_operator_sees_the_rules_but_cannot_switch_them(): void
    {
        $operator = $this->userWith(OpsRole::Operator);

        $this->actingAs($operator)
            ->get(route('ops.settings'))
            ->assertOk()
            ->assertSee(__('settings.automation.rules.deploy_auto_fix.name'), false)
            ->assertDontSee(route('ops.settings.automation', AutomationGuard::DEPLOY_AUTO_FIX), false);

        $this->actingAs($operator)
            ->post(route('ops.settings.automation', AutomationGuard::DEPLOY_AUTO_FIX), ['enabled' => '0'])
            ->assertForbidden();

        $this->actingAs($this->userWith(OpsRole::SuperAdmin))
            ->post(route('ops.settings.automation', 'no_such_rule'), ['enabled' => '0'])
            ->assertNotFound();

        $this->assertSame(0, AutomationSetting::query()->count());
    }

    private function userWith(OpsRole $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);

        return $user;
    }
}
