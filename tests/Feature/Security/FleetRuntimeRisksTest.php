<?php

namespace Tests\Feature\Security;

use App\Enums\OpsRole;
use App\Mcp\Tools\PushSecurityScanResultsTool;
use App\Models\AuditLog;
use App\Models\McpToken;
use App\Models\SiteSecurityCursor;
use App\Services\Agent\SiteHealthChecker;
use App\Services\Security\FleetRuntimeRisks;
use App\Services\Security\SecurityCollector;
use App\Services\Security\SiteSecurityClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Security\Concerns\FakesSecuritySites;
use Tests\TestCase;

class FleetRuntimeRisksTest extends TestCase
{
    use FakesSecuritySites;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootSecurityFakes();
    }

    /**
     * @return array<string, mixed>
     */
    private function system(array $overrides = []): array
    {
        return $overrides + [
            'insecure_settings' => 2,
            'insecure_keys' => ['app_debug', 'turnstile'],
            'exempt' => false,
            'ini_drift' => [['key' => 'display_errors', 'expected' => '0', 'actual' => '1']],
            'php_eol' => ['version' => '8.2', 'eol' => '2026-12-31', 'days_left' => 43, 'status' => 'warning'],
            'telemetry_gaps' => ['ingest_heartbeat'],
            'integrity' => ['status' => 'ok', 'open' => 0],
        ];
    }

    public function test_summary_system_and_scans_blocks_are_stored_sanitized_and_null_when_absent(): void
    {
        $a = $this->activeSite('alpha');
        $old = $this->activeSite('old');
        $payload = $this->summaryPayload([], [
            'system' => $this->system(['insecure_keys' => ['app_debug', 'bad key!'], 'secret' => 'x']),
            'scans' => ['critical_production' => 2, 'high_production' => 5, 'pending_review' => 1, 'last_scan_at' => '2026-11-18T01:00:00Z', 'by_type' => ['composer_audit' => 7]],
        ]);
        $this->fakeSite('alpha', $payload);
        $this->fakeSite('old', $this->summaryPayload([]));

        app(SecurityCollector::class)->collect($a);
        app(SecurityCollector::class)->collect($old);

        $summary = SiteSecurityCursor::query()->where('site_id', $a->id)->firstOrFail()->summary;
        $this->assertSame(2, $summary['system']['insecure_settings']);
        $this->assertSame(['app_debug', 'badkey'], $summary['system']['insecure_keys']);
        $this->assertArrayNotHasKey('secret', $summary['system']);
        $this->assertSame('display_errors', $summary['system']['ini_drift'][0]['key']);
        $this->assertSame(43, $summary['system']['php_eol']['days_left']);
        $this->assertSame(2, $summary['scans']['critical_production']);
        $this->assertSame(7, $summary['scans']['by_type']['composer_audit']);

        $oldSummary = SiteSecurityCursor::query()->where('site_id', $old->id)->firstOrFail()->summary;
        $this->assertNull($oldSummary['system']);
        $this->assertNull($oldSummary['scans']);
    }

    public function test_health_block_keeps_insecure_settings_and_telemetry_and_null_on_old_cms(): void
    {
        $site = $this->activeSite('alpha');
        Http::fake(['https://alpha.example.test/internal/control/v1/health' => Http::response([
            'queue_ok' => true,
            'security' => ['module_enabled' => true, 'critical_24h' => 0, 'blocks_active' => 0, 'insecure_settings' => 3,
                'telemetry' => ['ingest_age_seconds' => 900, 'dropped_24h' => 4, 'outbox_lag_seconds' => null, 'x' => 1]],
        ], 200)]);

        app(SiteHealthChecker::class)->check($site);

        $block = $site->fresh()->last_health_payload['security'];
        $this->assertSame(3, $block['insecure_settings']);
        $this->assertSame(['ingest_age_seconds' => 900, 'dropped_24h' => 4, 'outbox_lag_seconds' => null], $block['telemetry']);
    }

    public function test_fleet_risks_rank_critical_first_and_show_the_red_badge(): void
    {
        $a = $this->activeSite('alpha');
        $b = $this->activeSite('beta');
        $this->activeSite('quiet');
        $this->fakeSite('alpha', $this->summaryPayload([], ['system' => $this->system(), 'scans' => ['critical_production' => 1, 'high_production' => 0, 'pending_review' => 0, 'last_scan_at' => null, 'by_type' => []]]));
        $this->fakeSite('beta', $this->summaryPayload([], ['system' => $this->system([
            'insecure_settings' => 0, 'insecure_keys' => [], 'ini_drift' => [], 'telemetry_gaps' => [],
            'php_eol' => ['version' => '8.3', 'eol' => '2027-12-31', 'days_left' => 400, 'status' => 'ok'],
        ])]));
        app(SecurityCollector::class)->collect($a);
        app(SecurityCollector::class)->collect($b);

        $runtime = app(FleetRuntimeRisks::class)->build();

        $this->assertTrue($runtime['red']);
        $this->assertSame(2, $runtime['sites_reporting']);
        $this->assertSame(2, $runtime['insecure_settings']);
        $this->assertSame(1, $runtime['sites_ini_drift']);
        $this->assertSame(1, $runtime['sites_php_eol']);
        $this->assertSame(1, $runtime['sites_telemetry_gap']);
        $this->assertSame(1, $runtime['critical_production']);
        $this->assertLessThanOrEqual(5, count($runtime['top_risks']));
        $severities = array_column($runtime['top_risks'], 'severity');
        $sorted = $severities;
        rsort($sorted);
        $this->assertSame($sorted, $severities, 'critical risks come first');
        $this->assertSame('Alpha', $runtime['top_risks'][0]['site']);
        $this->assertNotContains('Beta', array_column($runtime['top_risks'], 'site'), 'a clean site raises nothing');
    }

    public function test_telemetry_silence_from_the_health_block_alone_is_a_critical_risk(): void
    {
        $this->activeSite('alpha', ['last_health_payload' => ['security' => [
            'module_enabled' => true, 'critical_24h' => 0, 'blocks_active' => 0, 'insecure_settings' => null,
            'telemetry' => ['ingest_age_seconds' => 1200, 'dropped_24h' => 0, 'outbox_lag_seconds' => null],
        ]]]);

        $runtime = app(FleetRuntimeRisks::class)->build();

        $this->assertTrue($runtime['red']);
        $this->assertSame('telemetry_ingest', $runtime['top_risks'][0]['kind']);
        $this->assertSame('20', $runtime['top_risks'][0]['value']);
    }

    public function test_an_old_cms_is_unknown_not_clean_and_raises_nothing(): void
    {
        $this->activeSite('old');

        $runtime = app(FleetRuntimeRisks::class)->build();

        $this->assertFalse($runtime['red']);
        $this->assertSame(0, $runtime['sites_reporting']);
        $this->assertSame([], $runtime['top_risks']);
    }

    public function test_screens_show_the_badge_top_risks_and_site_details(): void
    {
        $site = $this->activeSite('alpha');
        $this->fakeSite('alpha', $this->summaryPayload([], ['system' => $this->system(), 'scans' => ['critical_production' => 1, 'high_production' => 2, 'pending_review' => 0, 'last_scan_at' => '2026-11-18T01:00:00Z', 'by_type' => []]]));
        app(SecurityCollector::class)->collect($site);
        $user = $this->user(OpsRole::Viewer);

        $overview = $this->actingAs($user)->get(route('ops.security'))->assertOk()->getContent();
        $this->assertStringContainsString('data-security-runtime-badge="red"', $overview);
        $this->assertStringContainsString(__('security.runtime.risk.insecure_settings', ['value' => 2]), $overview);

        $detail = $this->actingAs($user)->get(route('ops.security.site', $site))->assertOk()->getContent();
        $this->assertStringContainsString('data-security-runtime-badge="red"', $detail);
        $this->assertStringContainsString('display_errors', $detail);
        $this->assertStringContainsString('2026-12-31', $detail);

        $old = $this->activeSite('old');
        $this->actingAs($user)->get(route('ops.security.site', $old))->assertOk()->assertSee(__('security.runtime.unknown'));
    }

    public function test_client_pushes_a_signed_scan_result_to_the_v1_route(): void
    {
        $site = $this->activeSite('alpha');
        $this->fakeSite('alpha', ['ok' => true, 'type' => 'composer_audit', 'items' => 3, 'new' => 2, 'updated' => 1, 'resolved' => 0, 'events' => 1]);

        $raw = app(SiteSecurityClient::class)->pushScanResults($site, 'composer_audit', ['advisories' => ['x' => []]], ['lockfile' => 'composer.lock', 'production' => true, 'junk' => 'dropped']);
        $items = app(SiteSecurityClient::class)->pushScanResults($site, 'npm_audit', ['items' => [['package' => 'a']]]);

        $this->assertTrue($raw->isOk());
        $this->assertSame('/internal/control/v1/security/scan-results', $this->siteCalls[0]['path']);
        $this->assertTrue($this->siteCalls[0]['signed']);
        $this->assertSame('composer_audit', $this->siteCalls[0]['body']['type']);
        $this->assertSame(['advisories' => ['x' => []]], $this->siteCalls[0]['body']['payload']);
        $this->assertSame('composer.lock', $this->siteCalls[0]['body']['lockfile']);
        $this->assertArrayNotHasKey('junk', $this->siteCalls[0]['body']);
        $this->assertSame([['package' => 'a']], $this->siteCalls[1]['body']['items']);
        $this->assertArrayNotHasKey('payload', $this->siteCalls[1]['body']);
        $this->assertTrue($items->isOk());

        $this->assertFalse(app(SiteSecurityClient::class)->pushScanResults($site, 'nmap', [])->isOk(), 'unknown types never leave Plane');
    }

    public function test_an_old_cms_answers_404_to_a_scan_push_as_unsupported(): void
    {
        $site = $this->activeSite('alpha');
        $this->fakeSite('alpha', fn (Request $request) => Http::response('Not Found', 404));

        $this->assertTrue(app(SiteSecurityClient::class)->pushScanResults($site, 'trivy', ['items' => []])->isUnsupported());
    }

    public function test_the_mcp_scan_tool_needs_write_a_role_and_confirmation_and_audits(): void
    {
        $this->activeSite('alpha');
        $this->fakeSite('alpha', ['ok' => true, 'type' => 'gitleaks', 'items' => 1, 'new' => 1, 'updated' => 0, 'resolved' => 0, 'events' => 1]);
        $names = collect(app(\App\Mcp\McpToolRegistry::class)->catalog(McpToken::issue($this->user(), 'a', ['read'])[0]))->pluck('name');
        $this->assertNotContains('push_security_scan_results', $names);

        $operator = $this->user(OpsRole::Operator);
        [, $plain] = McpToken::issue($operator, 'agent', ['read', 'write']);
        $call = fn (array $args, string $token = null) => $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'push_security_scan_results', 'arguments' => (object) $args]], ['Authorization' => 'Bearer '.($token ?? $plain)])->json('result.structuredContent');

        $no = $call(['site' => 'alpha', 'type' => 'gitleaks', 'payload' => ['a' => 1]]);
        $this->assertSame('confirmation_required', $no['code']);
        $this->assertSame([], $this->siteCalls);

        [, $viewerToken] = McpToken::issue($this->user(OpsRole::Viewer), 'agent', ['read', 'write']);
        $this->assertSame('forbidden', $call(['site' => 'alpha', 'type' => 'gitleaks', 'payload' => ['a' => 1], 'confirmed' => true], $viewerToken)['code']);

        $ok = $call(['site' => 'alpha', 'type' => 'gitleaks', 'payload' => ['a' => 1], 'confirmed' => true]);
        $this->assertTrue($ok['ok']);
        $this->assertSame(1, $ok['new']);
        $this->assertCount(1, $this->siteCalls);
        $audit = AuditLog::query()->where('action', 'security.scan_push')->sole();
        $this->assertSame($operator->id, $audit->actor_user_id);
        $this->assertSame('gitleaks', $audit->after['type']);
        $this->assertArrayNotHasKey('payload', $audit->after, 'scanner output is never copied into the audit log');

        $bad = $call(['site' => 'alpha', 'type' => 'nmap', 'payload' => ['a' => 1], 'confirmed' => true]);
        $this->assertSame('validation_error', $bad['code']);
        $this->assertTrue(class_exists(PushSecurityScanResultsTool::class));
    }
}
