<?php

namespace Tests\Feature\Sites;

use App\Enums\OpsRole;
use App\Enums\SiteStatus;
use App\Jobs\ReconcileSiteTurnstileJob;
use App\Jobs\SyncSiteTurnstileJob;
use App\Models\AuditLog;
use App\Models\CloudflareSetting;
use App\Models\Site;
use App\Models\SiteTurnstile;
use App\Models\TurnstileWidget;
use App\Models\User;
use App\Services\Agent\ControlPlaneAgentContract;
use App\Services\Turnstile\SiteTurnstileAgent;
use App\Services\Turnstile\SiteTurnstileSync;
use App\Services\Turnstile\TurnstileHostnames;
use App\Support\ControlPlaneAgentSignature;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SiteTurnstileTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'cf-api-token-secret-never-show';

    private const ACCOUNT_ID = 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4';

    private int $created = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Http::preventStrayRequests();
    }

    public function test_customer_domains_use_the_root_and_agency_hosts_stay_exact(): void
    {
        $account = $this->account();
        $hostnames = app(TurnstileHostnames::class);

        $this->assertSame(['musteri.com.tr'], $hostnames->forSite($this->site('www.musteri.com.tr'), $account));
        $this->assertSame(['kirmizi-tilki.codron.co'], $hostnames->forSite($this->site('kirmizi-tilki.codron.co'), $account));
    }

    public function test_first_sync_creates_one_widget_per_mode_and_pushes_signed_keys(): void
    {
        $account = $this->account();
        $site = $this->site('musteri.com.tr');
        $this->fakeCloudflareAndSite('musteri.com.tr');

        $sync = app(SiteTurnstileSync::class);
        $sync->enroll($site);
        $this->assertTrue($sync->syncWidgets($site));

        $record = $site->turnstile()->firstOrFail();
        $this->assertSame((int) $account->id, (int) $record->cloudflare_setting_id);
        $this->assertSame(['musteri.com.tr'], $record->hostnameList());
        $this->assertSame(SiteTurnstile::WIDGET_MODES, array_keys($record->widgetIdMap()));
        $this->assertSame(3, TurnstileWidget::query()->count());

        $widget = TurnstileWidget::query()->where('mode', 'invisible')->firstOrFail();
        $this->assertSame('Deamon · invisible · 01', $widget->name);
        $this->assertStringNotContainsString('secret-', (string) $widget->getRawOriginal('secret'));

        $this->assertTrue(app(SiteTurnstileAgent::class)->push($site->fresh()));
        $this->assertSame('ok', $record->fresh()->pushState());

        Http::assertSent(function (Request $request) use ($site): bool {
            if ($request->url() !== 'https://musteri.com.tr'.ControlPlaneAgentContract::TURNSTILE_PATH) {
                return false;
            }

            $data = $request->data();

            return ControlPlaneAgentSignature::matches(
                (string) $site->agent_secret_encrypted,
                $request->header(ControlPlaneAgentContract::HEADER_TIMESTAMP)[0],
                $request->header(ControlPlaneAgentContract::HEADER_NONCE)[0],
                $request->body(),
                $request->header(ControlPlaneAgentContract::HEADER_SIGNATURE)[0],
            )
                && $data['enabled'] === true
                && $data['mode'] === 'interaction-only'
                && $data['hostnames'] === ['musteri.com.tr']
                && str_starts_with((string) $data['keys']['managed']['secret'], 'secret-');
        });
    }

    public function test_sites_share_a_widget_until_it_is_full(): void
    {
        $this->account();
        $this->fakeCloudflareAndSite('a-site.com');
        $sync = app(SiteTurnstileSync::class);

        foreach (['a-site.com', 'b-site.com'] as $host) {
            $site = $this->site($host);
            $sync->enroll($site);
            $this->assertTrue($sync->syncWidgets($site));
        }

        $this->assertSame(3, TurnstileWidget::query()->count());
        $managed = TurnstileWidget::query()->where('mode', 'managed')->firstOrFail();
        $this->assertEqualsCanonicalizing(['a-site.com', 'b-site.com'], $managed->domainList());
        Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
            && str_ends_with($request->url(), '/challenges/widgets/'.$managed->sitekey)
            && $request['mode'] === 'managed');

        // Full widget: the next site gets a new one in that mode.
        $managed->forceFill(['domains' => array_map(fn (int $i): string => "dolu{$i}.com", range(1, 10))])->save();
        $site = $this->site('c-site.com');
        $sync->enroll($site);
        $this->assertTrue($sync->syncWidgets($site));

        $this->assertSame(2, TurnstileWidget::query()->where('mode', 'managed')->count());
        $this->assertSame('Deamon · managed · 02', TurnstileWidget::query()->where('mode', 'managed')->latest('created_at')->latest('id')->firstOrFail()->name);
    }

    public function test_domain_change_moves_the_site_hosts_inside_its_widget(): void
    {
        $this->account();
        $this->fakeCloudflareAndSite('eski-alan.com');
        $sync = app(SiteTurnstileSync::class);
        $site = $this->site('eski-alan.com');
        $sync->enroll($site);
        $sync->syncWidgets($site);

        $site->forceFill(['primary_domain' => 'yeni-alan.com'])->save();
        $this->assertTrue($sync->syncWidgets($site->fresh()));

        $this->assertSame(3, TurnstileWidget::query()->count());
        foreach (TurnstileWidget::query()->get() as $widget) {
            $this->assertSame(['yeni-alan.com'], $widget->domainList());
        }
        $this->assertSame(['yeni-alan.com'], $site->turnstile()->firstOrFail()->hostnameList());
    }

    public function test_cloudflare_errors_are_recorded_without_the_token(): void
    {
        $this->account();
        $site = $this->site('musteri.com.tr');
        Http::fake(fn () => Http::response(['success' => false, 'errors' => [['code' => 10000, 'message' => 'Authentication error']]], 403));

        $sync = app(SiteTurnstileSync::class);
        $sync->enroll($site);
        $this->assertFalse($sync->syncWidgets($site));
        $this->assertSame('cloudflare_forbidden', $site->turnstile()->value('sync_error'));

        TurnstileWidget::query()->delete();
        for ($i = 1; $i <= TurnstileWidget::MAX_WIDGETS_PER_ACCOUNT; $i++) {
            TurnstileWidget::query()->create([
                'cloudflare_setting_id' => CloudflareSetting::query()->value('id'),
                'mode' => 'non-interactive',
                'name' => 'Başka '.$i,
                'sitekey' => sprintf('0x4AAAAAAAFULL%04d', $i),
                'secret' => 'secret-full-'.$i,
                'domains' => array_map(fn (int $j): string => "w{$i}d{$j}.com", range(1, 10)),
            ]);
        }

        $this->assertFalse($sync->syncWidgets($site));
        $this->assertSame('widget_limit', $site->turnstile()->value('sync_error'));
    }

    public function test_push_is_held_for_an_old_cms_and_fails_when_the_secret_is_echoed(): void
    {
        $this->account();
        $this->fakeCloudflareAndSite('musteri.com.tr', echoSecret: true);
        $site = $this->site('musteri.com.tr');
        $sync = app(SiteTurnstileSync::class);
        $sync->enroll($site);
        $sync->syncWidgets($site);

        $site->forceFill(['last_health_at' => now(), 'last_health_payload' => ['deamon_version' => '1.2.78']])->save();
        $this->assertFalse(app(SiteTurnstileAgent::class)->push($site->fresh()));
        $this->assertSame('cms_too_old', $site->turnstile()->value('push_error'));
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/internal/control/v1/turnstile'));

        $site->forceFill(['last_health_at' => now(), 'last_health_payload' => ['deamon_version' => '1.2.78', 'turnstile' => ['enabled' => false, 'ready' => false, 'sitekeys' => []]]])->save();
        $this->assertFalse(app(SiteTurnstileAgent::class)->push($site->fresh()));
        $this->assertSame('secret_echoed', $site->turnstile()->value('push_error'));
    }

    public function test_operator_save_audits_without_secrets_and_queues_a_sync(): void
    {
        Queue::fake();
        $this->account();
        $site = $this->site('musteri.com.tr');
        $operator = User::factory()->create(['locale' => 'tr']);
        $operator->assignRole(OpsRole::Operator->value);

        $this->actingAs($operator)
            ->post(route('ops.sites.turnstile.update', $site), [
                'enabled' => '1',
                'mode' => 'invisible',
                'forms' => ['forms', 'admin_auth'],
            ])
            ->assertRedirect(route('ops.sites.show', $site).'#infrastructure');

        $record = $site->turnstile()->firstOrFail();
        $this->assertSame('invisible', $record->mode);
        $this->assertSame(['forms', 'admin_auth'], $record->formList());
        Queue::assertPushed(SyncSiteTurnstileJob::class, fn (SyncSiteTurnstileJob $job): bool => $job->siteId === (string) $site->id);

        $audit = AuditLog::query()->where('action', 'site.turnstile.updated')->firstOrFail();
        $this->assertSame('invisible', $audit->after['mode']);

        $this->actingAs($operator)
            ->post(route('ops.sites.turnstile.update', $site), ['mode' => 'loud'])
            ->assertSessionHasErrorsIn('turnstile', 'mode');

        $viewer = User::factory()->create(['locale' => 'tr']);
        $viewer->assignRole(OpsRole::Viewer->value);
        $this->actingAs($viewer)
            ->post(route('ops.sites.turnstile.update', $site), ['mode' => 'managed'])
            ->assertForbidden();
    }

    public function test_site_page_shows_the_card_and_never_a_secret(): void
    {
        $this->account();
        $this->fakeCloudflareAndSite('musteri.com.tr');
        $site = $this->site('musteri.com.tr');
        $sync = app(SiteTurnstileSync::class);
        $sync->enroll($site);
        $sync->syncWidgets($site);

        $operator = User::factory()->create(['locale' => 'tr']);
        $operator->assignRole(OpsRole::Operator->value);

        $this->actingAs($operator)
            ->get(route('ops.sites.show', $site))
            ->assertOk()
            ->assertSee('Bot koruması')
            ->assertSee('Deamon · managed · 01')
            ->assertSee('musteri.com.tr')
            ->assertDontSee('secret-')
            ->assertDontSee(self::TOKEN);
    }

    public function test_reconcile_pushes_pending_sites_and_enroll_command_queues_live_sites(): void
    {
        $this->account();
        $this->fakeCloudflareAndSite('musteri.com.tr');
        $site = $this->site('musteri.com.tr');
        app(SiteTurnstileSync::class)->enroll($site);

        (new ReconcileSiteTurnstileJob)->handle(app(SiteTurnstileSync::class), app(SiteTurnstileAgent::class), app(TurnstileHostnames::class));

        $record = $site->turnstile()->firstOrFail();
        $this->assertCount(3, $record->widgetIdMap());
        $this->assertSame('ok', $record->pushState());

        Queue::fake();
        $other = $this->site('ikinci-musteri.com');
        $this->artisan('ops:turnstile-enroll', ['--all' => true, '--dry-run' => true])->assertSuccessful();
        $this->assertFalse($other->turnstile()->exists());

        $this->artisan('ops:turnstile-enroll', ['sites' => [$other->slug]])->assertSuccessful();
        $this->assertTrue($other->turnstile()->exists());
        Queue::assertPushed(SyncSiteTurnstileJob::class, 1);
    }

    private function account(): CloudflareSetting
    {
        return CloudflareSetting::factory()->create([
            'name' => 'Prod CF',
            'account_id' => self::ACCOUNT_ID,
            'api_token' => self::TOKEN,
            'wildcard_domain' => 'codron.co',
            'is_enabled' => true,
            'is_default' => true,
        ]);
    }

    private function site(string $host): Site
    {
        return Site::factory()->withSecrets()->create([
            'status' => SiteStatus::Active,
            'primary_domain' => $host,
            'agent_base_url' => 'https://'.$host,
            'last_health_payload' => ['deamon_version' => '1.2.78', 'turnstile' => ['enabled' => false, 'ready' => false, 'sitekeys' => []]],
        ]);
    }

    private function fakeCloudflareAndSite(string $siteHost, bool $echoSecret = false): void
    {
        Http::fake(function (Request $request) use ($echoSecret) {
            $url = $request->url();

            if (str_contains($url, '/challenges/widgets')) {
                if ($request->method() === 'POST') {
                    $this->created++;

                    return Http::response(['success' => true, 'result' => [
                        'sitekey' => sprintf('0x4AAAAAAATEST%04d', $this->created),
                        'secret' => sprintf('secret-%04d-abcdef', $this->created),
                        'mode' => $request['mode'],
                        'domains' => $request['domains'],
                    ]], 200);
                }

                return Http::response(['success' => true, 'result' => ['domains' => $request['domains'] ?? []]], 200);
            }

            if (str_contains($url, ControlPlaneAgentContract::TURNSTILE_PATH)) {
                $body = ['ok' => true, 'turnstile' => ['enabled' => true, 'ready' => true]];
                if ($echoSecret) {
                    $body['debug'] = $request['keys']['managed']['secret'] ?? '';
                }

                return Http::response($body, 200);
            }

            return Http::response(['success' => false], 404);
        });
    }
}
