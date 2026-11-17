<?php

namespace Tests\Feature\Security;

use App\Enums\OpsRole;
use App\Models\FleetAllowlistEntry;
use App\Models\FleetIpBlock;
use App\Models\SiteSecurityEvent;
use App\Support\Lists\ListFragment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Security\Concerns\FakesSecuritySites;
use Tests\TestCase;

class FleetSecurityScreensTest extends TestCase
{
    use FakesSecuritySites;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootSecurityFakes();
    }

    public function test_guests_are_sent_to_login_and_every_role_can_read(): void
    {
        $this->get(route('ops.security'))->assertRedirect();

        foreach ([OpsRole::Viewer, OpsRole::Operator, OpsRole::SuperAdmin] as $role) {
            $user = $this->user($role);
            foreach (['ops.security', 'ops.security.correlation', 'ops.security.events', 'ops.security.blocks'] as $route) {
                $this->actingAs($user)->get(route($route))->assertOk();
            }
        }
    }

    public function test_overview_shows_counts_cross_site_card_and_the_nav_entry(): void
    {
        $a = $this->activeSite('alpha');
        $b = $this->activeSite('beta');
        $this->seedEvent($a, '203.0.113.7', 2);
        $this->seedEvent($b, '203.0.113.7', 1);

        $html = $this->actingAs($this->user())->get(route('ops.security'))->assertOk()->getContent();

        $this->assertStringContainsString('203.0.113.7', $html);
        $this->assertStringContainsString(route('ops.security.site', $a), $html);
        $this->assertStringContainsString(__('security.overview.cross_site'), $html);
        $this->assertStringContainsString(route('ops.security.blocks'), $html);
        $this->assertStringNotContainsString('confirm(', $html);
    }

    public function test_events_list_filters_and_answers_a_region_for_in_place_updates(): void
    {
        $a = $this->activeSite('alpha');
        $b = $this->activeSite('beta');
        $this->seedEvent($a, '203.0.113.7', 1, '/.env');
        $this->seedEvent($b, '198.51.100.9', 2, '/wp-login.php');
        $user = $this->user();

        $full = $this->actingAs($user)->get(route('ops.security.events', ['q' => 'wp-login']));
        $full->assertOk()->assertHeader('X-Ops-List-Region', '0');
        $this->assertStringContainsString('wp-login.php', $full->getContent());
        $this->assertStringNotContainsString('/.env', $full->getContent());
        $this->assertStringContainsString('data-ops-list-toolbar', $full->getContent());
        $this->assertStringContainsString('aria-label="'.__('security.events.filters').'"', $full->getContent());
        $this->assertStringContainsString('title="'.__('security.events.filters').'"', $full->getContent());

        $region = $this->actingAs($user)->get(route('ops.security.events', ['severity' => 'critical']), [ListFragment::HEADER => ListFragment::VALUE]);
        $region->assertOk()->assertHeader('X-Ops-List-Region', '1');
        $this->assertStringContainsString('198.51.100.9', $region->getContent());
        $this->assertStringNotContainsString('203.0.113.7', $region->getContent());
        $this->assertStringNotContainsString('<html', $region->getContent(), 'a fragment, not a page');

        $bySite = $this->actingAs($user)->get(route('ops.security.events', ['site' => $a->id]))->getContent();
        $this->assertStringContainsString('203.0.113.7', $bySite);
        $this->assertStringNotContainsString('198.51.100.9', $bySite);
    }

    public function test_block_buttons_only_for_roles_that_can_write(): void
    {
        $a = $this->activeSite('alpha');
        $this->seedEvent($a, '203.0.113.7', 1);

        $viewer = $this->actingAs($this->user(OpsRole::Viewer))->get(route('ops.security.events'))->getContent();
        $operator = $this->actingAs($this->user(OpsRole::Operator))->get(route('ops.security.events'))->getContent();

        $this->assertStringNotContainsString('security-inline-form', $viewer);
        $this->assertStringContainsString('security-inline-form', $operator);
        $this->assertStringContainsString('data-confirm=', $operator, 'confirmation is the in-page dialog');
    }

    public function test_site_page_and_blocks_page_render(): void
    {
        $site = $this->activeSite('alpha', ['last_health_payload' => ['security' => ['module_enabled' => true, 'critical_24h' => 1, 'blocks_active' => 0]]]);
        $this->seedEvent($site, '203.0.113.7', 2);
        FleetIpBlock::query()->create(['uid' => (string) Str::uuid(), 'ip' => '203.0.113.7', 'reason' => 'scan', 'status' => 'applied']);
        FleetAllowlistEntry::query()->create(['uid' => (string) Str::uuid(), 'ip' => '198.51.100.0/24', 'label' => 'office', 'status' => 'applied']);
        $user = $this->user();

        $this->actingAs($user)->get(route('ops.security.site', $site))->assertOk()->assertSee('203.0.113.7');
        $blocks = $this->actingAs($user)->get(route('ops.security.blocks'))->assertOk();
        $blocks->assertSee('203.0.113.7')->assertSee('198.51.100.0/24')->assertSee('office');
    }

    public function test_the_locale_files_have_the_same_keys(): void
    {
        $flatten = function (array $array, string $prefix = '') use (&$flatten): array {
            $keys = [];
            foreach ($array as $key => $value) {
                is_array($value) ? $keys = array_merge($keys, $flatten($value, $prefix.$key.'.')) : $keys[] = $prefix.$key;
            }

            return $keys;
        };

        $en = $flatten(require lang_path('en/security.php'));
        $tr = $flatten(require lang_path('tr/security.php'));
        sort($en);
        sort($tr);

        $this->assertSame($en, $tr);
    }

    private function seedEvent($site, string $ip, int $severity, string $path = '/.env'): void
    {
        SiteSecurityEvent::query()->create([
            'site_id' => $site->id,
            'event_uid' => (string) Str::uuid(),
            'occurred_at' => now()->subMinutes(10),
            'type' => 'probe',
            'severity' => $severity,
            'rule' => 'sensitive_probe',
            'ip' => $ip,
            'path' => $path,
            'method' => 'GET',
            'status' => 403,
        ]);
    }
}
