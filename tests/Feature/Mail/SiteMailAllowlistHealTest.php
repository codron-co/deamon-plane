<?php

namespace Tests\Feature\Mail;

use App\Enums\Channel;
use App\Enums\CoolifyEnvKind;
use App\Enums\OpsRole;
use App\Enums\SiteStatus;
use App\Jobs\ConfigureSiteMailJob;
use App\Jobs\PushPlatformMailJob;
use App\Models\CoolifyConnection;
use App\Models\CoolifyEnvDefault;
use App\Models\MailServer;
use App\Models\Site;
use App\Models\User;
use App\Services\Mail\SiteMailConfigurer;
use App\Services\Sites\SitePlaneAllowlistHeal;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SiteMailAllowlistHealTest extends TestCase
{
    use RefreshDatabase;

    private const APP = 'heal-app-1';

    private const REJECTION = 'URL host is not on the allowlist.';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Http::preventStrayRequests();
        config(['app.url' => 'https://plane.example.com']);

        foreach (Channel::cases() as $channel) {
            CoolifyEnvDefault::query()->updateOrCreate([
                'channel' => $channel->value,
                'key' => 'CONTROL_PLANE_HOST_ALLOWLIST',
            ], [
                'kind' => CoolifyEnvKind::Site,
                'value' => '{{plane.host}}',
                'is_secret' => false,
                'sort' => 10,
            ]);
        }
    }

    public function test_allowlist_rejection_rewrites_the_cms_env_and_marks_a_push_after_deploy(): void
    {
        $site = $this->site();
        $this->fakeCoolifyAndCms(configureStatus: 422);

        $result = app(SiteMailConfigurer::class)->sync($site);

        $this->assertSame('failed', $result->status);
        $site->refresh();
        $this->assertSame(self::REJECTION, $site->mail_configure_message);
        $this->assertTrue($site->mail_push_after_deploy);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH'
            && str_contains($request->url(), '/applications/'.self::APP.'/envs/bulk')
            && $this->envValue($request, 'CONTROL_PLANE_HOST_ALLOWLIST') === 'plane.example.com');
    }

    public function test_resend_after_an_allowlist_rejection_syncs_env_redeploys_and_audits(): void
    {
        Queue::fake();
        $site = $this->site([
            'mail_configure_failed_at' => now(),
            'mail_configure_error' => 'http_422',
            'mail_configure_message' => self::REJECTION,
        ]);
        $this->fakeCoolifyAndCms(configureStatus: 422);

        $this->actingAs($this->operator())
            ->from(route('ops.sites.show', $site))
            ->post(route('ops.sites.mail-configure', $site))
            ->assertRedirect(route('ops.sites.show', $site))
            ->assertSessionHas('status', __('mail.flash.allowlist_redeploy_queued'));

        Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH'
            && str_contains($request->url(), '/envs/bulk')
            && $this->envValue($request, 'CONTROL_PLANE_HOST_ALLOWLIST') === 'plane.example.com');
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && str_contains($request->url(), '/deploy'));

        $this->assertTrue($site->fresh()->mail_push_after_deploy);
        $this->assertDatabaseHas('audit_logs', ['action' => 'site.mail_allowlist_healed', 'subject_id' => $site->id]);
        // The CMS still has the old env until it reboots: nothing is pushed yet.
        Queue::assertNotPushed(ConfigureSiteMailJob::class);
    }

    public function test_finished_deploy_pushes_both_mail_settings_once(): void
    {
        Queue::fake();
        $site = $this->site(['mail_push_after_deploy' => true]);
        $heal = app(SitePlaneAllowlistHeal::class);

        $this->assertTrue($heal->pushAfterDeploy($site));
        Queue::assertPushed(ConfigureSiteMailJob::class, fn (ConfigureSiteMailJob $job): bool => $job->siteId === (string) $site->id);
        Queue::assertPushed(PushPlatformMailJob::class, fn (PushPlatformMailJob $job): bool => $job->siteId === (string) $site->id);
        $this->assertFalse($site->fresh()->mail_push_after_deploy);

        $this->assertFalse($heal->pushAfterDeploy($site->fresh()));
        Queue::assertPushed(ConfigureSiteMailJob::class, 1);
    }

    public function test_mail_card_explains_the_allowlist_and_offers_sync_and_redeploy(): void
    {
        $site = $this->site([
            'mail_configure_failed_at' => now(),
            'mail_configure_error' => 'http_422',
            'mail_configure_message' => self::REJECTION,
        ]);
        Http::fake();

        $this->actingAs($this->operator())
            ->get(route('ops.sites.show', $site))
            ->assertOk()
            ->assertSee('data-mail-allowlist-hint', false)
            ->assertSee(__('mail.configure_state.allowlist_hint', ['host' => 'plane.example.com']), false)
            ->assertSee(__('mail.configure_state.resend_with_redeploy'), false);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function site(array $overrides = []): Site
    {
        $connection = CoolifyConnection::factory()->create([
            'base_url' => 'https://coolify.example',
            'api_token' => 'heal-token',
        ]);
        $server = MailServer::factory()->hostingerReady('hapi-heal-token')->create();

        return Site::factory()->withSecrets()->create(array_merge([
            'status' => SiteStatus::Active,
            'channel' => Channel::Main,
            'primary_domain' => 'shop.example.test',
            'agent_base_url' => 'https://shop.example.test',
            'coolify_app_uuid' => self::APP,
            'coolify_connection_id' => $connection->id,
            'mail_server_id' => $server->id,
        ], $overrides));
    }

    private function fakeCoolifyAndCms(int $configureStatus): void
    {
        Http::fake(function (Request $request) use ($configureStatus) {
            $url = $request->url();
            $method = $request->method();

            if (str_ends_with($url, '/internal/control/v1/mail/configure')) {
                return $configureStatus === 200
                    ? Http::response(['ok' => true], 200)
                    : Http::response(['ok' => false, 'error' => 'validation_failed', 'message' => self::REJECTION], $configureStatus);
            }
            if ($method === 'GET' && str_contains($url, '/applications/'.self::APP.'/envs')) {
                return Http::response([['key' => 'CONTROL_PLANE_HOST_ALLOWLIST', 'value' => 'old-plane.example.com']], 200);
            }
            if ($method === 'PATCH' && str_contains($url, '/envs/bulk')) {
                return Http::response([], 200);
            }
            if ($method === 'POST' && str_contains($url, '/deploy')) {
                return Http::response(['deployments' => [['deployment_uuid' => 'dep-1']]], 200);
            }

            return Http::response(['error' => 'unexpected '.$url], 404);
        });
    }

    private function envValue(Request $request, string $key): ?string
    {
        foreach ($request->data()['data'] ?? [] as $row) {
            if (is_array($row) && ($row['key'] ?? null) === $key) {
                return (string) ($row['value'] ?? '');
            }
        }

        return null;
    }

    private function operator(): User
    {
        $user = User::factory()->create();
        $user->assignRole(OpsRole::Operator->value);

        return $user;
    }
}
