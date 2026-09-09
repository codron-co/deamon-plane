<?php

namespace Tests\Feature\Import;

use App\Enums\Channel;
use App\Enums\SiteStatus;
use App\Models\AuditLog;
use App\Models\CoolifySetting;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class ImportCoolifyAppsCommandTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'test-coolify-import-token';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        CoolifySetting::factory()->create([
            'base_url' => 'https://coolify.test',
            'api_token' => self::TOKEN,
        ]);
    }

    public function test_dry_run_prints_table_and_writes_nothing(): void
    {
        $this->fakeApplicationList();

        $this->artisan('ops:import-coolify-apps')
            ->expectsOutputToContain('Dry-run (no writes)')
            ->expectsTable(
                ['uuid', 'name', 'repo', 'branch', 'pack', 'domain', 'action'],
                [
                    ['app-susa', 'Susa', 'codron-co/deamon', 'beta', 'dockercompose', 'susa.demo.codron.co', 'create'],
                    ['app-old', 'Old Dockerfile Site', 'codron-co/deamon', 'main', 'dockerfile', 'old.example.test', 'create'],
                    ['app-review', 'Review Me', 'codron-co/deamon', 'develop', 'dockercompose', 'review.example.test', 'create'],
                    ['app-plane', 'Deamon Plane', 'codron-co/deamon-plane', 'main', 'dockercompose', 'plane.example.test', 'skip'],
                    ['app-theme', 'Theme Izyem', 'deamon-themes/deamon-theme-izyem', 'main', 'dockercompose', 'theme.example.test', 'skip'],
                    ['app-entron', 'Entron', 'codron-co/entron', 'main', 'dockercompose', 'entron.example.test', 'skip'],
                    ['app-transfer', 'Transfer', 'codron-co/webapp-transfer', 'main', 'dockercompose', 'transfer.example.test', 'skip'],
                    ['app-nix', 'Nix Site', 'codron-co/deamon', 'main', 'nixpacks', 'nix.example.test', 'skip'],
                ],
            )
            ->expectsOutputToContain('3 create, 0 update, 5 skip')
            ->expectsOutputToContain('No database changes were made.')
            ->assertSuccessful();

        $this->assertDatabaseCount('sites', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_apply_upserts_by_uuid_then_domain_without_agent_secrets(): void
    {
        $existingByDomain = Site::factory()->create([
            'slug' => 'old-name',
            'name' => 'Previous Name',
            'primary_domain' => 'old.example.test',
            'channel' => Channel::Alpha,
            'status' => SiteStatus::Draft,
            'coolify_app_uuid' => null,
        ]);

        $this->fakeApplicationList();

        $this->artisan('ops:import-coolify-apps', ['--apply' => true])
            ->expectsOutputToContain('Applying Coolify fleet import.')
            ->expectsOutputToContain('Wrote 2 create(s), 1 update(s). Agent secrets were not generated.')
            ->assertSuccessful();

        $susa = Site::query()->where('coolify_app_uuid', 'app-susa')->first();
        $this->assertNotNull($susa);
        $this->assertSame('susa', $susa->slug);
        $this->assertSame('susa.demo.codron.co', $susa->primary_domain);
        $this->assertSame(Channel::Beta, $susa->channel);
        $this->assertSame(SiteStatus::Active, $susa->status);
        $this->assertSame('srv_test', $susa->coolify_server_uuid);
        $this->assertNull($susa->app_key_encrypted);
        $this->assertNull($susa->agent_secret_encrypted);
        $this->assertSame('https://susa.demo.codron.co', $susa->agent_base_url);

        $this->assertDatabaseHas('site_domains', [
            'site_id' => $susa->id,
            'domain' => 'susa.demo.codron.co',
            'is_primary' => 1,
        ]);

        $existingByDomain->refresh();
        $this->assertSame('app-old', $existingByDomain->coolify_app_uuid);
        $this->assertSame('Old Dockerfile Site', $existingByDomain->name);
        $this->assertSame(Channel::Main, $existingByDomain->channel);
        $this->assertSame(SiteStatus::Active, $existingByDomain->status);
        $this->assertNull($existingByDomain->agent_secret_encrypted);
        $this->assertStringContainsString('dockerfile_build_pack', (string) $existingByDomain->notes);

        $review = Site::query()->where('coolify_app_uuid', 'app-review')->first();
        $this->assertNotNull($review);
        $this->assertSame(Channel::Main, $review->channel);
        $this->assertSame(SiteStatus::Error, $review->status);
        $this->assertStringContainsString('needs_review', (string) $review->notes);
        $this->assertNull($review->agent_secret_encrypted);

        $this->assertDatabaseMissing('sites', ['coolify_app_uuid' => 'app-plane']);
        $this->assertDatabaseMissing('sites', ['coolify_app_uuid' => 'app-entron']);
        $this->assertSame(3, Site::query()->count());

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'site.imported',
            'subject_id' => $susa->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'site.import_updated',
            'subject_id' => $existingByDomain->id,
        ]);

        foreach (AuditLog::query()->get() as $log) {
            $payload = json_encode([$log->before, $log->after]);
            $this->assertIsString($payload);
            $this->assertStringNotContainsString(self::TOKEN, $payload);
            $this->assertArrayNotHasKey('agent_secret_encrypted', $log->after ?? []);
            $this->assertArrayNotHasKey('app_key_encrypted', $log->after ?? []);
        }
    }

    public function test_apply_updates_existing_uuid_and_skips_in_flight_status(): void
    {
        $site = Site::factory()->create([
            'slug' => 'susa',
            'name' => 'Susa old',
            'primary_domain' => 'susa.demo.codron.co',
            'channel' => Channel::Main,
            'status' => SiteStatus::Provisioning,
            'coolify_app_uuid' => 'app-susa',
            'agent_secret_encrypted' => 'keep-existing-secret',
        ]);

        $this->fakeApplicationList([$this->susaPayload()]);

        $this->artisan('ops:import-coolify-apps', ['--apply' => true])
            ->assertSuccessful();

        $site->refresh();
        $this->assertSame('Susa', $site->name);
        $this->assertSame(Channel::Beta, $site->channel);
        $this->assertSame(SiteStatus::Provisioning, $site->status);
        $this->assertSame('keep-existing-secret', $site->agent_secret_encrypted);
    }

    public function test_command_fails_without_coolify_credentials(): void
    {
        CoolifySetting::query()->delete();
        config([
            'ops.coolify.base_url' => null,
            'ops.coolify.api_token' => null,
        ]);

        $this->artisan('ops:import-coolify-apps')
            ->expectsOutputToContain('Coolify is not configured')
            ->assertFailed();

        Http::assertNothingSent();
    }

    public function test_token_never_appears_in_output_or_logs(): void
    {
        Log::spy();
        $this->fakeApplicationList([$this->susaPayload()]);

        $this->artisan('ops:import-coolify-apps', ['--apply' => true])
            ->doesntExpectOutputToContain(self::TOKEN)
            ->assertSuccessful();

        $this->artisan('ops:import-coolify-apps')
            ->doesntExpectOutputToContain(self::TOKEN)
            ->assertSuccessful();

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://coolify.test/api/v1/applications'
                && $request->hasHeader('Authorization', 'Bearer '.self::TOKEN);
        });
    }

    /**
     * @param  list<array<string, mixed>>|null  $apps
     */
    private function fakeApplicationList(?array $apps = null): void
    {
        Http::fake([
            'https://coolify.test/api/v1/applications*' => Http::response($apps ?? $this->listPayload(), 200),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listPayload(): array
    {
        return [
            $this->susaPayload(),
            [
                'uuid' => 'app-old',
                'name' => 'Old Dockerfile Site',
                'git_branch' => 'main',
                'build_pack' => 'dockerfile',
                'fqdn' => 'https://old.example.test',
                'git_repository' => 'https://github.com/codron-co/deamon.git',
                'status' => 'running:healthy',
            ],
            [
                'uuid' => 'app-review',
                'name' => 'Review Me',
                'git_branch' => 'develop',
                'build_pack' => 'dockercompose',
                'fqdn' => 'review.example.test',
                'git_repository' => 'https://github.com/codron-co/deamon',
                'status' => 'exited:unhealthy',
                'docker_compose_domains' => '{"app":{"domain":"https://review.example.test"}}',
            ],
            [
                'uuid' => 'app-plane',
                'name' => 'Deamon Plane',
                'git_branch' => 'main',
                'build_pack' => 'dockercompose',
                'fqdn' => 'https://plane.example.test',
                'git_repository' => 'https://github.com/codron-co/deamon-plane.git',
                'status' => 'running:healthy',
            ],
            [
                'uuid' => 'app-theme',
                'name' => 'Theme Izyem',
                'git_branch' => 'main',
                'build_pack' => 'dockercompose',
                'fqdn' => 'https://theme.example.test',
                'git_repository' => 'https://github.com/deamon-themes/deamon-theme-izyem.git',
                'status' => 'running:healthy',
            ],
            [
                'uuid' => 'app-entron',
                'name' => 'Entron',
                'git_branch' => 'main',
                'build_pack' => 'dockercompose',
                'fqdn' => 'https://entron.example.test',
                'git_repository' => 'https://github.com/codron-co/entron.git',
                'status' => 'running:healthy',
            ],
            [
                'uuid' => 'app-transfer',
                'name' => 'Transfer',
                'git_branch' => 'main',
                'build_pack' => 'dockercompose',
                'fqdn' => 'https://transfer.example.test',
                'git_repository' => 'https://github.com/codron-co/webapp-transfer.git',
                'status' => 'running:healthy',
            ],
            [
                'uuid' => 'app-nix',
                'name' => 'Nix Site',
                'git_branch' => 'main',
                'build_pack' => 'nixpacks',
                'fqdn' => 'https://nix.example.test',
                'git_repository' => 'https://github.com/codron-co/deamon.git',
                'status' => 'running:healthy',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function susaPayload(): array
    {
        return [
            'uuid' => 'app-susa',
            'name' => 'Susa',
            'git_branch' => 'beta',
            'build_pack' => 'dockercompose',
            'fqdn' => null,
            'git_repository' => 'https://github.com/codron-co/deamon.git',
            'status' => 'running:healthy',
            'docker_compose_domains' => '{"app":{"domain":"https://susa.demo.codron.co,https://www.susa.demo.codron.co/"}}',
            'destination' => [
                'server' => [
                    'uuid' => 'srv_test',
                ],
            ],
        ];
    }
}
