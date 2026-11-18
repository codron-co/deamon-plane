<?php

namespace Tests\Feature\Mcp;

use App\Enums\OpsRole;
use App\Models\AuditLog;
use App\Models\FleetIpBlock;
use App\Models\McpToken;
use App\Models\SiteSecurityEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Security\Concerns\FakesSecuritySites;
use Tests\TestCase;

class McpSecurityToolsTest extends TestCase
{
    use FakesSecuritySites;
    use RefreshDatabase;

    private const READ_TOOLS = ['list_security_events', 'get_site_security', 'get_fleet_security_summary', 'list_fleet_allowlist'];

    private const WRITE_TOOLS = ['block_ip_fleet', 'unblock_ip_fleet', 'add_fleet_allowlist'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootSecurityFakes();
    }

    public function test_tools_are_listed_by_ability(): void
    {
        $user = $this->user();
        $names = fn (array $abilities): array => collect(
            $this->rpc('tools/list', [], McpToken::issue($user, 'agent', $abilities)[1])->json('result.tools'),
        )->pluck('name')->all();

        $read = $names(['read']);
        foreach (self::READ_TOOLS as $tool) {
            $this->assertContains($tool, $read);
        }
        foreach (self::WRITE_TOOLS as $tool) {
            $this->assertNotContains($tool, $read);
            $this->assertContains($tool, $names(['read', 'write']));
        }
    }

    public function test_read_tools_return_events_summary_and_site_picture(): void
    {
        $a = $this->activeSite('alpha');
        $b = $this->activeSite('beta');
        $this->seedEvent($a, '203.0.113.7', 2, '/.env');
        $this->seedEvent($b, '203.0.113.7', 1, '/wp-login.php');
        [, $plain] = McpToken::issue($this->user(OpsRole::Viewer), 'agent', ['read']);

        $events = $this->payload($this->tool('list_security_events', ['severity' => 'critical'], $plain));
        $this->assertSame(1, $events['count']);
        $this->assertSame('alpha', $events['events'][0]['site']);
        $this->assertSame('/.env', $events['events'][0]['path']);
        $this->assertArrayNotHasKey('body', $events['events'][0]);

        $summary = $this->payload($this->tool('get_fleet_security_summary', [], $plain));
        $this->assertSame('203.0.113.7', $summary['cross_site'][0]['ip']);
        $this->assertSame(2, $summary['cross_site'][0]['sites_count']);
        $this->assertSame(0, $summary['blocks_active']);

        $site = $this->payload($this->tool('get_site_security', ['site' => 'alpha'], $plain));
        $this->assertSame('alpha', $site['site']['slug']);
        $this->assertSame(1, count($site['recent_events']));

        $this->assertSame(0, $this->payload($this->tool('list_fleet_allowlist', [], $plain))['count']);
    }

    public function test_write_tools_need_the_ability_a_write_role_and_the_users_confirmation(): void
    {
        Queue::fake();
        [, $readOnly] = McpToken::issue($this->user(OpsRole::Operator), 'agent', ['read']);
        $this->tool('block_ip_fleet', ['ip' => '203.0.113.7', 'confirmed' => true], $readOnly)->assertOk();
        $this->assertSame(0, FleetIpBlock::query()->count(), 'a read token cannot block');

        [, $viewerToken] = McpToken::issue($this->user(OpsRole::Viewer), 'agent', ['read', 'write']);
        $viewer = $this->payload($this->tool('block_ip_fleet', ['ip' => '203.0.113.7', 'confirmed' => true], $viewerToken));
        $this->assertSame('forbidden', $viewer['code'], 'the Plane role still decides');

        [, $plain] = McpToken::issue($this->user(OpsRole::Operator), 'agent', ['read', 'write']);
        $unconfirmed = $this->payload($this->tool('block_ip_fleet', ['ip' => '203.0.113.7'], $plain));
        $this->assertSame('confirmation_required', $unconfirmed['code']);
        $this->assertSame(0, FleetIpBlock::query()->count());
    }

    public function test_block_unblock_and_allowlist_write_audit_rows_and_queue_one_job_each(): void
    {
        Queue::fake();
        $user = $this->user(OpsRole::Operator);
        [, $plain] = McpToken::issue($user, 'agent', ['read', 'write']);

        $blocked = $this->payload($this->tool('block_ip_fleet', ['ip' => '203.0.113.7', 'reason' => 'scanner', 'hours' => 12, 'confirmed' => true], $plain));
        $this->assertTrue($blocked['ok']);
        $this->assertSame('203.0.113.7', $blocked['block']['ip']);
        $this->assertNotNull($blocked['block']['expires_at']);
        $this->assertNotEmpty($blocked['job']['id']);
        $this->assertSame($user->id, AuditLog::query()->where('action', 'security.fleet_block')->value('actor_user_id'));

        $again = $this->payload($this->tool('block_ip_fleet', ['ip' => '203.0.113.7', 'confirmed' => true], $plain));
        $this->assertTrue($again['reused']);

        $refused = $this->payload($this->tool('block_ip_fleet', ['ip' => '10.0.0.1', 'confirmed' => true], $plain));
        $this->assertSame('validation_error', $refused['code']);

        $allowed = $this->payload($this->tool('add_fleet_allowlist', ['ip' => '198.51.100.9', 'label' => 'office', 'confirmed' => true], $plain));
        $this->assertSame('198.51.100.9', $allowed['entry']['ip']);
        $this->assertSame(1, $this->payload($this->tool('list_fleet_allowlist', [], $plain))['count']);
        $this->assertNotNull(AuditLog::query()->where('action', 'security.fleet_allowlist_add')->first());

        $released = $this->payload($this->tool('unblock_ip_fleet', ['ip' => '203.0.113.7', 'confirmed' => true], $plain));
        $this->assertTrue($released['released']);
        $this->assertNotNull(AuditLog::query()->where('action', 'security.fleet_unblock')->first());

        $nothing = $this->payload($this->tool('unblock_ip_fleet', ['ip' => '203.0.113.7', 'confirmed' => true], $plain));
        $this->assertFalse($nothing['released']);
    }

    private function seedEvent($site, string $ip, int $severity, string $path): void
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
        ]);
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function tool(string $name, array $arguments, string $plain): TestResponse
    {
        return $this->rpc('tools/call', ['name' => $name, 'arguments' => (object) $arguments], $plain);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(TestResponse $response): array
    {
        $text = $response->json('result.content.0.text');

        return is_string($text) ? (array) json_decode($text, true) : [];
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function rpc(string $method, array $params, string $plain): TestResponse
    {
        return $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => (object) $params], ['Authorization' => 'Bearer '.$plain]);
    }
}
