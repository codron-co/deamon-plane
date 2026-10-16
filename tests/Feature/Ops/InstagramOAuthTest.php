<?php

namespace Tests\Feature\Ops;

use App\Enums\OpsRole;
use App\Enums\SiteStatus;
use App\Jobs\PushInstagramOAuthConfigJob;
use App\Jobs\ReconcileInstagramPushJob;
use App\Models\InstagramSetting;
use App\Models\Site;
use App\Models\User;
use App\Services\Agent\ControlPlaneAgentContract;
use App\Services\Instagram\InstagramAgentClient;
use App\Services\Instagram\InstagramConnectLink;
use App\Support\ControlPlaneAgentSignature;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class InstagramOAuthTest extends TestCase
{
    use RefreshDatabase;

    private const RETURN_URL = 'https://shop.example.test/admin/integrations/instagram';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Http::preventStrayRequests();
    }

    public function test_super_admin_saves_app_and_secret_is_encrypted_and_kept_when_blank(): void
    {
        Queue::fake();
        $admin = $this->superAdmin();

        $this->actingAs($admin)->put(route('ops.instagram.update'), ['app_id' => '1234567890', 'app_secret' => 'abc123secret'])
            ->assertRedirect(route('ops.instagram.edit'));

        $settings = InstagramSetting::current();
        $this->assertTrue($settings->isReady());
        $this->assertStringNotContainsString('abc123secret', (string) $settings->getRawOriginal('app_secret'));

        $this->actingAs($admin)->put(route('ops.instagram.update'), ['app_id' => '1234567890', 'app_secret' => '']);
        $this->assertSame('abc123secret', InstagramSetting::current()->app_secret);

        $this->actingAs($admin)->get(route('ops.instagram.edit'))
            ->assertOk()
            ->assertSee('/instagram/callback')
            ->assertDontSee('abc123secret');
    }

    public function test_operator_cannot_change_app_secret(): void
    {
        $user = User::factory()->create();
        $user->assignRole(OpsRole::Operator->value);

        $this->actingAs($user)->put(route('ops.instagram.update'), ['app_id' => '1', 'app_secret' => 'x'])
            ->assertForbidden();
    }

    public function test_connect_with_valid_signed_link_redirects_to_instagram(): void
    {
        $this->readySetting();
        $site = $this->site();

        $response = $this->get('/instagram/connect?'.http_build_query($this->signedQuery($site)));

        $response->assertRedirect();
        $location = (string) $response->headers->get('Location');
        $this->assertStringStartsWith('https://www.instagram.com/oauth/authorize?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame('1234567890', $query['client_id']);
        $this->assertSame('instagram_business_basic', $query['scope']);
        $this->assertStringEndsWith('/instagram/callback', $query['redirect_uri']);
        $this->assertNotEmpty($query['state']);
    }

    public function test_connect_rejects_bad_signature_foreign_return_and_reuse(): void
    {
        $this->readySetting();
        $site = $this->site();

        $bad = $this->signedQuery($site);
        $bad['sig'] = str_repeat('0', 64);
        $this->get('/instagram/connect?'.http_build_query($bad))->assertForbidden();

        $foreign = $this->signedQuery($site, 'https://evil.example/admin');
        $this->get('/instagram/connect?'.http_build_query($foreign))->assertForbidden();

        $expired = $this->signedQuery($site, self::RETURN_URL, now()->subHour()->timestamp);
        $this->get('/instagram/connect?'.http_build_query($expired))->assertForbidden();

        $valid = $this->signedQuery($site);
        $this->get('/instagram/connect?'.http_build_query($valid))->assertRedirect();
        $this->get('/instagram/connect?'.http_build_query($valid))->assertForbidden();
    }

    public function test_connect_without_configured_app_shows_error(): void
    {
        $site = $this->site();

        $this->get('/instagram/connect?'.http_build_query($this->signedQuery($site)))->assertStatus(503);
    }

    public function test_callback_exchanges_code_pushes_token_and_returns_to_site(): void
    {
        $this->readySetting();
        $site = $this->site();
        $secret = (string) $site->agent_secret_encrypted;

        Http::fake([
            'https://api.instagram.com/oauth/access_token' => Http::response(['access_token' => 'IGshort', 'user_id' => 42]),
            'https://graph.instagram.com/access_token*' => Http::response(['access_token' => 'IGAAlonglivedtoken', 'token_type' => 'bearer', 'expires_in' => 5183944]),
            'https://shop.example.test/internal/control/v1/instagram/connect' => Http::response(['ok' => true, 'instagram' => ['username' => 'musteri']]),
        ]);

        $state = $this->startFlow($site);
        $this->get('/instagram/callback?code=AQDcode123&state='.$state)
            ->assertRedirect(self::RETURN_URL.'?instagram=connected');

        Http::assertSent(function (Request $request) use ($secret): bool {
            if (! str_ends_with($request->url(), ControlPlaneAgentContract::INSTAGRAM_CONNECT_PATH)) {
                return false;
            }

            return ControlPlaneAgentSignature::matches(
                $secret,
                (string) ($request->header(ControlPlaneAgentContract::HEADER_TIMESTAMP)[0] ?? ''),
                (string) ($request->header(ControlPlaneAgentContract::HEADER_NONCE)[0] ?? ''),
                $request->body(),
                (string) ($request->header(ControlPlaneAgentContract::HEADER_SIGNATURE)[0] ?? ''),
            ) && $request['access_token'] === 'IGAAlonglivedtoken' && $request['expires_in'] === 5183944;
        });

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.instagram.com/oauth/access_token'
            && $request['client_secret'] === 'abc123secret'
            && $request['code'] === 'AQDcode123');
    }

    public function test_callback_denied_and_bad_state(): void
    {
        $this->readySetting();
        $site = $this->site();

        $state = $this->startFlow($site);
        $this->get('/instagram/callback?error=access_denied&state='.$state)
            ->assertRedirect(self::RETURN_URL.'?instagram=denied');

        $this->get('/instagram/callback?code=x&state=forged')->assertStatus(400);
    }

    public function test_callback_reports_site_error_back(): void
    {
        $this->readySetting();
        $site = $this->site();

        Http::fake([
            'https://api.instagram.com/oauth/access_token' => Http::response(['access_token' => 'IGshort', 'user_id' => 42]),
            'https://graph.instagram.com/access_token*' => Http::response(['access_token' => 'IGAAlong', 'expires_in' => 100]),
            'https://shop.example.test/*' => Http::response(['ok' => false, 'error' => 'module_disabled'], 409),
        ]);

        $state = $this->startFlow($site);
        $location = (string) $this->get('/instagram/callback?code=x&state='.$state)->headers->get('Location');

        $this->assertStringStartsWith(self::RETURN_URL.'?instagram=error&reason=', $location);
        $this->assertStringContainsString(rawurlencode('modülü'), $location);
    }

    public function test_oauth_config_push_only_for_supported_sites(): void
    {
        $this->readySetting();
        $site = $this->site();
        $old = $this->site('old.example.test', '1.2.75');
        $agent = app(InstagramAgentClient::class);

        Http::fake(['https://shop.example.test/internal/control/v1/instagram/oauth-config' => Http::response(['ok' => true])]);

        $this->assertFalse($agent->supports($old));
        $this->assertFalse($agent->pushOAuthConfig($old));
        $this->assertTrue($agent->pushOAuthConfig($site));

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/instagram/oauth-config')
            && $request['enabled'] === true
            && $request['plane_site_id'] === (string) $site->id
            && str_ends_with((string) $request['connect_url'], '/instagram/connect'));
    }

    public function test_reconcile_pushes_sites_reporting_missing_config(): void
    {
        Queue::fake();
        $this->readySetting();
        $site = $this->site();
        $site->forceFill(['last_health_payload' => ['deamon_version' => '1.2.76', 'instagram' => ['oauth_configured' => false]]])->save();

        (new ReconcileInstagramPushJob)->handle(app(InstagramAgentClient::class));

        Queue::assertPushed(PushInstagramOAuthConfigJob::class, fn ($job): bool => $job->siteId === (string) $site->id);
    }

    public function test_public_pages_bypass_ops_ip_allowlist(): void
    {
        config(['ops.access.ip_allowlist' => '10.9.9.9']);

        $this->get('/instagram/veri-silme')->assertOk();
        $this->get('/instagram/callback?state=x')->assertStatus(400);
    }

    private function startFlow(Site $site): string
    {
        $location = (string) $this->get('/instagram/connect?'.http_build_query($this->signedQuery($site)))->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        return (string) $query['state'];
    }

    /**
     * @return array<string, string>
     */
    private function signedQuery(Site $site, string $return = self::RETURN_URL, ?int $timestamp = null): array
    {
        $timestamp = (string) ($timestamp ?? now()->timestamp);
        $nonce = Str::random(32);

        return [
            'site' => (string) $site->id,
            'ts' => $timestamp,
            'nonce' => $nonce,
            'return' => $return,
            'sig' => ControlPlaneAgentSignature::sign((string) $site->agent_secret_encrypted, $timestamp, $nonce, InstagramConnectLink::canonical((string) $site->id, $return)),
        ];
    }

    private function readySetting(): void
    {
        InstagramSetting::query()->create(['app_id' => '1234567890', 'app_secret' => 'abc123secret']);
    }

    private function site(string $domain = 'shop.example.test', string $version = '1.2.76'): Site
    {
        return Site::factory()->withSecrets()->create([
            'status' => SiteStatus::Active,
            'primary_domain' => $domain,
            'agent_base_url' => 'https://'.$domain,
            'last_health_payload' => ['deamon_version' => $version],
        ]);
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create();
        $user->assignRole(OpsRole::SuperAdmin->value);

        return $user;
    }
}
