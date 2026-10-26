<?php

namespace Tests\Feature\Ops;

use App\Enums\OpsRole;
use App\Enums\SiteStatus;
use App\Models\InstagramAccount;
use App\Models\InstagramDeletionRequest;
use App\Models\InstagramSetting;
use App\Models\Site;
use App\Models\User;
use App\Services\Agent\ControlPlaneAgentContract;
use App\Support\ControlPlaneAgentSignature;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class InstagramMetaCallbackTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'abc123secret';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Http::preventStrayRequests();
        InstagramSetting::query()->create(['app_id' => '1234567890', 'app_secret' => self::SECRET]);
    }

    public function test_bad_or_missing_signature_is_rejected(): void
    {
        $this->post('/webhooks/instagram/data-deletion', ['signed_request' => $this->signed(['user_id' => '42'], 'wrong-secret')])
            ->assertStatus(400);
        $this->post('/webhooks/instagram/deauthorize', [])->assertStatus(400);
        $this->post('/webhooks/instagram/data-deletion', ['signed_request' => $this->signed(['user_id' => '42'], self::SECRET, 'HMAC-SHA1')])
            ->assertStatus(400);

        $this->assertSame(0, InstagramDeletionRequest::query()->count());
    }

    public function test_data_deletion_disconnects_the_site_and_returns_status_url(): void
    {
        $site = $this->site();
        $secret = (string) $site->agent_secret_encrypted;
        InstagramAccount::recordConnection($site, '42', 'musteri');
        Http::fake([
            'https://shop.example.test/internal/control/v1/instagram/disconnect' => Http::response(['ok' => true, 'instagram' => ['connected' => false]]),
        ]);

        $response = $this->post('/webhooks/instagram/data-deletion', ['signed_request' => $this->signed(['user_id' => '42'])])
            ->assertOk()
            ->assertJsonStructure(['url', 'confirmation_code']);

        $deletion = InstagramDeletionRequest::query()->sole();
        $this->assertSame($deletion->confirmation_code, $response->json('confirmation_code'));
        $this->assertSame(route('instagram.data-deletion.status', $deletion->confirmation_code), $response->json('url'));
        $this->assertSame(InstagramDeletionRequest::STATUS_COMPLETED, $deletion->status);
        $this->assertSame([(string) $site->id], $deletion->site_ids);
        $this->assertNotNull(InstagramAccount::query()->sole()->disconnected_at);

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), ControlPlaneAgentContract::INSTAGRAM_DISCONNECT_PATH)
            && ControlPlaneAgentSignature::matches(
                $secret,
                (string) ($request->header(ControlPlaneAgentContract::HEADER_TIMESTAMP)[0] ?? ''),
                (string) ($request->header(ControlPlaneAgentContract::HEADER_NONCE)[0] ?? ''),
                $request->body(),
                (string) ($request->header(ControlPlaneAgentContract::HEADER_SIGNATURE)[0] ?? ''),
            ));

        config(['ops.access.ip_allowlist' => '10.9.9.9']);
        $this->get($response->json('url'))
            ->assertOk()
            ->assertSee($deletion->confirmation_code)
            ->assertSee(__('instagram.status.state.completed'));
    }

    public function test_deauthorize_without_known_site_is_left_for_ops(): void
    {
        $this->post('/webhooks/instagram/deauthorize', ['signed_request' => $this->signed(['user_id' => '777'])])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $deletion = InstagramDeletionRequest::query()->sole();
        $this->assertSame(InstagramDeletionRequest::TYPE_DEAUTHORIZE, $deletion->type);
        $this->assertSame(InstagramDeletionRequest::STATUS_MANUAL, $deletion->status);
        $this->assertSame('no_connected_site', $deletion->note);
    }

    public function test_site_on_old_cms_is_left_for_ops_without_calling_it(): void
    {
        $site = $this->site(version: '1.2.88');
        InstagramAccount::recordConnection($site, '42', 'musteri');

        $this->post('/webhooks/instagram/data-deletion', ['signed_request' => $this->signed(['user_id' => '42'])])->assertOk();

        $deletion = InstagramDeletionRequest::query()->sole();
        $this->assertSame(InstagramDeletionRequest::STATUS_MANUAL, $deletion->status);
        $this->assertStringContainsString('cms_too_old', (string) $deletion->note);
        $this->assertNull(InstagramAccount::query()->sole()->disconnected_at);
        Http::assertNothingSent();
    }

    public function test_connecting_another_account_retires_the_previous_mapping(): void
    {
        $site = $this->site();
        InstagramAccount::recordConnection($site, '42', 'eski');
        InstagramAccount::recordConnection($site, '43', 'yeni');

        $this->assertSame(['43'], InstagramAccount::query()->active()->pluck('ig_user_id')->all());
    }

    public function test_ops_page_lists_callback_urls_and_requests(): void
    {
        InstagramDeletionRequest::open(InstagramDeletionRequest::TYPE_DELETION, '42');
        $user = User::factory()->create();
        $user->assignRole(OpsRole::SuperAdmin->value);

        $this->actingAs($user)->get(route('ops.instagram.edit'))
            ->assertOk()
            ->assertSee('/webhooks/instagram/deauthorize')
            ->assertSee('/webhooks/instagram/data-deletion')
            ->assertSee('/instagram/gizlilik')
            ->assertSee(__('instagram.requests.status.received'));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function signed(array $payload, string $secret = self::SECRET, string $algorithm = 'HMAC-SHA256'): string
    {
        $encode = static fn (string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
        $body = $encode((string) json_encode($payload + ['algorithm' => $algorithm, 'issued_at' => time()]));

        return $encode(hash_hmac('sha256', $body, $secret, true)).'.'.$body;
    }

    private function site(string $domain = 'shop.example.test', string $version = '1.2.89'): Site
    {
        return Site::factory()->withSecrets()->create([
            'status' => SiteStatus::Active,
            'primary_domain' => $domain,
            'agent_base_url' => 'https://'.$domain,
            'last_health_payload' => ['deamon_version' => $version],
        ]);
    }
}
