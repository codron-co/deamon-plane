<?php

namespace Tests\Feature\Ops;

use App\Enums\OpsRole;
use App\Models\PlatformMailSetting;
use App\Models\Site;
use App\Models\User;
use App\Services\Mail\PlatformMailConfigurer;
use App\Services\Mail\PlatformMailState;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PlatformMailStateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Http::preventStrayRequests();
    }

    public function test_every_page_reaches_platform_mail_in_one_click(): void
    {
        $operator = $this->operator('tr');

        foreach ([route('ops.fleet'), route('ops.sites'), route('ops.themes')] as $url) {
            $this->actingAs($operator)
                ->get($url)
                ->assertOk()
                ->assertSee('href="'.route('ops.platform-mail.edit').'"', false)
                ->assertSee(trans('ops.nav.platform_mail', [], 'tr'), false)
                ->assertSee(trans('ops.nav.mail_servers', [], 'tr'), false);
        }
    }

    public function test_mail_servers_nav_entry_is_not_active_on_the_platform_mail_page(): void
    {
        $operator = $this->operator();

        $html = $this->actingAs($operator)->get(route('ops.platform-mail.edit'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/class="ops-nav-item is-sub is-active" href="'.preg_quote(route('ops.platform-mail.edit'), '/').'"/',
            $html,
        );
        $this->assertStringNotContainsString(
            'class="ops-nav-item is-sub is-active" href="'.route('ops.mail-servers.index').'"',
            $html,
        );
    }

    public function test_unconfigured_smtp_is_named_on_both_mail_pages(): void
    {
        $operator = $this->operator('tr');
        $label = trans('platform_mail.state.unconfigured', [], 'tr');

        $this->actingAs($operator)->get(route('ops.platform-mail.edit'))->assertOk()->assertSee($label, false);
        $this->actingAs($operator)->get(route('ops.mail-servers.index'))->assertOk()->assertSee($label, false);
    }

    public function test_saved_but_never_pushed_settings_do_not_claim_to_be_live(): void
    {
        $this->readySettings();

        $this->actingAs($this->operator('tr'))
            ->get(route('ops.platform-mail.edit'))
            ->assertOk()
            ->assertSee(trans('platform_mail.state.not_pushed', [], 'tr'), false)
            ->assertSee('status-warning', false)
            ->assertDontSee('status-active', false);
    }

    public function test_a_site_that_refused_the_last_push_turns_the_chip_red(): void
    {
        $this->readySettings(pushed: true);
        Site::factory()->create([
            'platform_mail_push_failed_at' => now()->subMinutes(3),
            'platform_mail_push_error' => 'http_500',
        ]);

        $this->actingAs($this->operator('tr'))
            ->get(route('ops.mail-servers.index'))
            ->assertOk()
            ->assertSee(trans('platform_mail.state.push_failed', ['count' => 1], 'tr'), false)
            ->assertSee('status-error', false);
    }

    public function test_a_pushed_and_accepted_configuration_reads_as_active(): void
    {
        $this->readySettings(pushed: true);
        Site::factory()->create(['platform_mail_pushed_at' => now()->subMinutes(2)]);

        $this->actingAs($this->operator('tr'))
            ->get(route('ops.platform-mail.edit'))
            ->assertOk()
            ->assertSee(trans('platform_mail.state.active', [], 'tr'), false)
            ->assertSee('status-active', false)
            ->assertDontSee('super-secret-smtp', false);
    }

    public function test_configurer_records_the_push_outcome_per_site(): void
    {
        $this->readySettings();
        $site = Site::factory()->withSecrets()->create([
            'agent_base_url' => 'https://mail-state.example.test',
        ]);

        Http::fake([
            'https://mail-state.example.test/*' => Http::sequence()
                ->push([], 500)
                ->push([], 200),
        ]);

        app(PlatformMailConfigurer::class)->sync($site->fresh());

        $site->refresh();
        $this->assertNotNull($site->platform_mail_push_failed_at);
        $this->assertSame('http_500', $site->platform_mail_push_error);
        $this->assertSame(PlatformMailState::PUSH_FAILED, PlatformMailState::current()->key);

        app(PlatformMailConfigurer::class)->sync($site->fresh());

        $site->refresh();
        $this->assertNull($site->platform_mail_push_failed_at);
        $this->assertNull($site->platform_mail_push_error);
        $this->assertNotNull($site->platform_mail_pushed_at);
    }

    public function test_a_site_that_was_never_pushed_is_not_counted_as_a_failure(): void
    {
        $this->readySettings(pushed: true);
        Site::factory()->create();

        $this->assertSame(PlatformMailState::ACTIVE, PlatformMailState::current()->key);
    }

    private function readySettings(bool $pushed = false): PlatformMailSetting
    {
        return PlatformMailSetting::query()->create([
            'enabled' => true,
            'host' => 'smtp.example.com',
            'port' => 465,
            'encryption' => 'ssl',
            'username' => 'u@example.com',
            'password' => 'super-secret-smtp',
            'from_address' => 'noreply@example.com',
            'from_name' => 'Test',
            'default_admin_recipient' => 'ops@example.com',
            'notifications' => [],
            'last_pushed_at' => $pushed ? now()->subMinutes(5) : null,
        ]);
    }

    private function operator(?string $locale = null): User
    {
        $operator = User::factory()->create($locale === null ? [] : ['locale' => $locale]);
        $operator->assignRole(OpsRole::Operator->value);

        return $operator;
    }
}
