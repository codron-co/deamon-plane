<?php

namespace Tests\Feature\Webhooks;

use App\Enums\ThemeInstallationStatus;
use App\Models\GithubSetting;
use App\Models\Site;
use App\Models\SiteThemeInstallation;
use App\Models\Theme;
use App\Models\ThemeGitConnection;
use App\Support\GitHubWebhookSignature;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class GitHubWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'plane-github-webhook-secret';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Http::preventStrayRequests();

        GithubSetting::factory()->create([
            'org' => 'deamon-themes',
            'webhook_secret' => self::SECRET,
        ]);
    }

    public function test_invalid_signature_is_rejected(): void
    {
        $theme = Theme::factory()->create([
            'repo_full_name' => 'deamon-themes/deamon-theme-beyazoglu',
        ]);

        $this->signedPost('push', [
            'ref' => 'refs/heads/main',
            'after' => 'fff111',
            'repository' => ['full_name' => $theme->repo_full_name],
        ], 'wrong-secret')->assertUnauthorized();

        $this->assertNull($theme->fresh()->latest_sha);
    }

    public function test_empty_secret_rejects_even_with_hmac_of_empty_key(): void
    {
        GithubSetting::query()->update(['webhook_secret' => null]);
        config(['ops.github.webhook_secret' => null]);

        $body = json_encode(['zen' => 'test'], JSON_THROW_ON_ERROR);

        $this->call('POST', '/webhooks/github', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, ''),
            'HTTP_X_GITHUB_EVENT' => 'ping',
        ], $body)->assertUnauthorized();
    }

    public function test_push_updates_catalog_and_fans_out_opt_in_installations(): void
    {
        $theme = Theme::factory()->publicCatalog()->create([
            'repo_full_name' => 'deamon-themes/deamon-theme-beyazoglu',
            'theme_id' => 'beyazoglu',
        ]);
        $optIn = Site::factory()->withSecrets()->create([
            'primary_domain' => 'optin.example.test',
            'agent_base_url' => 'https://optin.example.test',
        ]);
        $optOut = Site::factory()->withSecrets()->create([
            'primary_domain' => 'optout.example.test',
            'agent_base_url' => 'https://optout.example.test',
        ]);
        $optInInstall = SiteThemeInstallation::factory()->active()->autoUpdate()->create([
            'site_id' => $optIn->id,
            'theme_id' => $theme->id,
            'ref' => 'main',
        ]);
        $optOutInstall = SiteThemeInstallation::factory()->active()->create([
            'site_id' => $optOut->id,
            'theme_id' => $theme->id,
            'auto_update' => false,
        ]);

        Http::fake([
            'https://optin.example.test/internal/control/v1/themes/update' => Http::response(['ok' => true, 'sha' => 'fff111aaa'], 200),
        ]);

        $this->signedPost('push', [
            'ref' => 'refs/heads/main',
            'after' => 'fff111aaa',
            'repository' => ['full_name' => $theme->repo_full_name],
        ])->assertOk()->assertJson([
            'ok' => true,
            'updated' => true,
            'fanout' => 1,
        ]);

        $this->assertSame('fff111aaa', $theme->fresh()->latest_sha);
        $this->assertSame('fff111aaa', $optInInstall->fresh()->pinned_sha);
        $this->assertSame(ThemeInstallationStatus::Active, $optInInstall->fresh()->status);
        $this->assertNull($optOutInstall->fresh()->pinned_sha);
        $this->assertNull($optOutInstall->fresh()->updated_from_webhook_at);
        $this->assertNotNull($optInInstall->fresh()->updated_from_webhook_at);
    }

    public function test_minimum_version_skip_does_not_call_agent(): void
    {
        $theme = Theme::factory()->publicCatalog()->create([
            'repo_full_name' => 'deamon-themes/deamon-theme-old',
            'minimum_deamon_version' => '2.0.0',
        ]);
        $site = Site::factory()->withSecrets()->create([
            'primary_domain' => 'old.example.test',
            'agent_base_url' => 'https://old.example.test',
            'last_health_payload' => ['deamon_version' => '1.0.0'],
        ]);
        SiteThemeInstallation::factory()->active()->autoUpdate()->create([
            'site_id' => $site->id,
            'theme_id' => $theme->id,
        ]);

        $this->signedPost('push', [
            'ref' => 'refs/heads/main',
            'after' => 'bbb222',
            'repository' => ['full_name' => $theme->repo_full_name],
        ])->assertOk()->assertJson(['fanout' => 1]);

        $this->assertTrue($site->auditLogs()->where('action', 'theme.update_skipped_version')->exists());
        Http::assertNothingSent();
    }

    public function test_secret_is_never_written_to_logs(): void
    {
        Log::spy();

        $this->signedPost('push', ['repository' => ['full_name' => 'x/y']], 'wrong-secret')
            ->assertUnauthorized();

        Log::shouldHaveReceived('warning')->withArgs(function (string $message): bool {
            $this->assertStringNotContainsString(self::SECRET, $message);
            $this->assertStringNotContainsString('wrong-secret', $message);

            return true;
        });
    }

    public function test_push_maps_installation_to_the_connection_theme(): void
    {
        $connection = ThemeGitConnection::factory()->githubApp('8811')->connected()->create();
        $theme = Theme::factory()->publicCatalog()->create([
            'repo_full_name' => 'acme/mapped-theme',
            'theme_git_connection_id' => $connection->id,
        ]);

        $this->signedPost('push', [
            'ref' => 'refs/heads/main',
            'after' => 'map123aaa',
            'installation' => ['id' => 8811],
            'repository' => ['full_name' => $theme->repo_full_name],
        ])->assertOk()->assertJson([
            'ok' => true,
            'updated' => true,
        ]);

        $this->assertSame('map123aaa', $theme->fresh()->latest_sha);
    }

    public function test_selected_connection_ignores_excluded_repo_push(): void
    {
        $connection = ThemeGitConnection::factory()->githubApp('8812')->connected()->selected()->create();
        $theme = Theme::factory()->create([
            'repo_full_name' => 'acme/excluded-theme',
            'theme_git_connection_id' => $connection->id,
            'latest_sha' => null,
        ]);

        $this->signedPost('push', [
            'ref' => 'refs/heads/main',
            'after' => 'should-not-apply',
            'installation' => ['id' => 8812],
            'repository' => ['full_name' => $theme->repo_full_name],
        ])->assertOk()->assertJson([
            'updated' => false,
        ]);

        $this->assertNull($theme->fresh()->latest_sha);
    }

    public function test_coolify_webhook_secret_does_not_validate_github(): void
    {
        $body = json_encode(['zen' => 'hi'], JSON_THROW_ON_ERROR);
        $coolifySig = 'sha256='.hash_hmac('sha256', $body, 'coolify-other-secret');

        $this->call('POST', '/webhooks/github', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => $coolifySig,
            'HTTP_X_GITHUB_EVENT' => 'ping',
        ], $body)->assertUnauthorized();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function signedPost(string $event, array $payload, string $secret = self::SECRET): TestResponse
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $signature = GitHubWebhookSignature::sign($secret, $body);

        return $this->call('POST', '/webhooks/github', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => $signature,
            'HTTP_X_GITHUB_EVENT' => $event,
        ], $body);
    }
}
