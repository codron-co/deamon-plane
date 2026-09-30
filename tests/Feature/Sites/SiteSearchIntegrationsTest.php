<?php

namespace Tests\Feature\Sites;

use App\Enums\OpsRole;
use App\Enums\SiteStatus;
use App\Jobs\PushSiteSearchIntegrationsJob;
use App\Jobs\ReconcileSiteSearchIntegrationsJob;
use App\Models\AuditLog;
use App\Models\Site;
use App\Models\SiteSearchIntegration;
use App\Models\User;
use App\Services\Agent\ControlPlaneAgentContract;
use App\Services\SearchIntegrations\SiteSearchIntegrationsAgent;
use App\Support\ControlPlaneAgentSignature;
use App\Support\Lists\SiteListColumns;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class SiteSearchIntegrationsTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://shop.example.test/internal/control/v1/search-integrations';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Http::preventStrayRequests();
    }

    public function test_pasted_meta_tag_and_file_name_are_reduced_to_the_stored_value(): void
    {
        $values = SiteSearchIntegration::normalizeInput([
            'google_verification' => '<meta name="google-site-verification" content="AbC_123-xyz=" />',
            'bing_verification' => " BING123 \n",
            'google_file_token' => 'https://shop.example.test/google1a2b3c4d.html',
            'ga4_id' => 'g-abc1234',
            'gtm_id' => 'gtm-xyz99',
            'google_mode' => '',
        ]);

        $this->assertSame('AbC_123-xyz=', $values['google_verification']);
        $this->assertSame('BING123', $values['bing_verification']);
        $this->assertSame('1a2b3c4d', $values['google_file_token']);
        $this->assertSame('G-ABC1234', $values['ga4_id']);
        $this->assertSame('GTM-XYZ99', $values['gtm_id']);
        $this->assertSame('off', $values['google_mode']);
    }

    public function test_empty_fields_plane_never_set_are_not_pushed_but_cleared_ones_are(): void
    {
        $first = SiteSearchIntegration::managedAfter(
            SiteSearchIntegration::normalizeInput(['google_verification' => 'gsc', 'google_mode' => 'off']),
            [],
        );
        $this->assertSame(['verifications.google'], $first);

        $second = SiteSearchIntegration::managedAfter(
            SiteSearchIntegration::normalizeInput(['google_verification' => '', 'google_mode' => 'ga4', 'ga4_id' => 'G-ABC1234']),
            $first,
        );
        $this->assertSame(['verifications.google', 'google.mode', 'google.ga4_id'], $second);
    }

    public function test_operator_save_stores_desired_state_audits_and_queues_a_push(): void
    {
        Queue::fake();
        $site = $this->site();

        $this->actingAs($this->user(OpsRole::Operator))
            ->post(route('ops.sites.search-integrations.update', $site), [
                'google_verification' => '<meta name="google-site-verification" content="gsc-token-1" />',
                'google_mode' => 'ga4',
                'ga4_id' => 'G-ABC1234',
                'enable_module' => '1',
            ])
            ->assertRedirect(route('ops.sites.show', $site).'#search')
            ->assertSessionHas('status', __('search_integrations.flash.saved'));

        $record = $site->searchIntegration()->firstOrFail();
        $this->assertSame('gsc-token-1', $record->google_verification);
        $this->assertSame('ga4', $record->google_mode);
        $this->assertSame(['verifications.google', 'google.mode', 'google.ga4_id'], $record->managed_fields);
        $this->assertSame('pending', $record->pushState());
        $this->assertSame([
            'verifications' => ['google' => 'gsc-token-1'],
            'google' => ['mode' => 'ga4', 'ga4_id' => 'G-ABC1234'],
            'enable_module' => true,
        ], $record->pushPayload());

        Queue::assertPushed(PushSiteSearchIntegrationsJob::class, fn (PushSiteSearchIntegrationsJob $job): bool => $job->siteId === (string) $site->id);

        $audit = AuditLog::query()->where('action', 'site.search_integrations.updated')->firstOrFail();
        $this->assertSame('gsc-token-1', $audit->after['google_verification']);
        $this->assertNull($audit->before);
    }

    public function test_invalid_ids_and_tokens_are_rejected_and_nothing_is_saved(): void
    {
        Queue::fake();
        $site = $this->site();
        $user = $this->user(OpsRole::Operator);

        foreach ([
            ['google_mode' => 'ga4', 'ga4_id' => 'UA-12345-1'],
            ['google_mode' => 'gtm', 'gtm_id' => ''],
            ['google_mode' => 'gtm', 'gtm_id' => 'GTM_ABC'],
            ['google_mode' => 'off', 'google_verification' => 'abc"def'],
            ['google_mode' => 'off', 'bing_verification' => '<script>x</script>'],
            ['google_mode' => 'off', 'yandex_metrica_id' => '12ab'],
        ] as $payload) {
            $this->actingAs($user)
                ->post(route('ops.sites.search-integrations.update', $site), $payload)
                ->assertRedirect(route('ops.sites.show', $site).'#search')
                ->assertSessionHasErrors([], null, 'searchIntegrations');
        }

        $this->assertFalse($site->searchIntegration()->exists());
        Queue::assertNothingPushed();
    }

    public function test_viewers_cannot_change_or_pull(): void
    {
        $site = $this->site();
        $viewer = $this->user(OpsRole::Viewer);

        $this->actingAs($viewer)
            ->post(route('ops.sites.search-integrations.update', $site), ['google_mode' => 'off', 'google_verification' => 'x'])
            ->assertForbidden();
        $this->actingAs($viewer)->post(route('ops.sites.search-integrations.pull', $site))->assertForbidden();
        $this->actingAs($viewer)->post(route('ops.sites.search-integrations.push', $site))->assertForbidden();

        $this->actingAs($viewer)
            ->get(route('ops.sites.show', $site))
            ->assertOk()
            ->assertSee('id="search"', false)
            ->assertDontSee('data-search-integrations-form', false);
    }

    public function test_site_detail_shows_the_section_for_operators(): void
    {
        $site = $this->site();

        $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.sites.show', $site))
            ->assertOk()
            ->assertSee('href="#search"', false)
            ->assertSee('data-search-integrations-form', false)
            ->assertSee(route('ops.sites.search-integrations.pull', $site), false);
    }

    public function test_push_is_signed_sends_only_managed_fields_and_records_success(): void
    {
        $site = $this->site();
        $secret = (string) $site->agent_secret_encrypted;
        $this->record($site, [
            'google_verification' => 'gsc-token',
            'bing_verification' => '',
            'managed_fields' => ['verifications.google'],
        ]);

        Http::fake([self::URL => Http::response(['ok' => true, 'module_enabled' => true, 'changed' => ['verifications.google']], 200)]);

        $this->assertTrue(app(SiteSearchIntegrationsAgent::class)->push($site));

        Http::assertSent(function (Request $request) use ($secret): bool {
            $timestamp = (string) ($request->header(ControlPlaneAgentContract::HEADER_TIMESTAMP)[0] ?? '');
            $nonce = (string) ($request->header(ControlPlaneAgentContract::HEADER_NONCE)[0] ?? '');
            $signature = (string) ($request->header(ControlPlaneAgentContract::HEADER_SIGNATURE)[0] ?? '');

            return $request->method() === 'POST'
                && ControlPlaneAgentSignature::matches($secret, $timestamp, $nonce, $request->body(), $signature)
                && $request->data() === ['verifications' => ['google' => 'gsc-token'], 'enable_module' => true];
        });

        $record = $site->searchIntegration()->firstOrFail();
        $this->assertSame('ok', $record->pushState());
        $this->assertTrue($record->site_module_enabled);
        $this->assertNull($record->push_error);
    }

    public function test_a_rejected_push_records_the_cms_reason_and_the_job_does_not_retry_it(): void
    {
        $site = $this->site();
        $this->record($site, ['ga4_id' => 'G-ABC1234', 'google_mode' => 'ga4', 'managed_fields' => ['google.mode', 'google.ga4_id']]);

        Http::fake([self::URL => Http::response([
            'ok' => false,
            'error' => 'validation_failed',
            'message' => 'GA4 ölçüm kimliği "G-XXXXXXXX" biçiminde olmalıdır.',
        ], 422)]);

        (new PushSiteSearchIntegrationsJob((string) $site->id))->handle(app(SiteSearchIntegrationsAgent::class));

        $record = $site->searchIntegration()->firstOrFail();
        $this->assertSame('failed', $record->pushState());
        $this->assertStringStartsWith('validation_failed: GA4', (string) $record->push_error);
    }

    public function test_an_older_cms_fails_the_job_so_the_queue_retries(): void
    {
        $site = $this->site();
        $this->record($site, ['google_verification' => 'gsc', 'managed_fields' => ['verifications.google']]);

        Http::fake([self::URL => Http::response('', 404)]);

        $this->expectException(RuntimeException::class);

        try {
            (new PushSiteSearchIntegrationsJob((string) $site->id))->handle(app(SiteSearchIntegrationsAgent::class));
        } finally {
            $this->assertSame('http_404', $site->searchIntegration()->value('push_error'));
        }
    }

    public function test_pull_imports_site_values_and_marks_every_field_managed(): void
    {
        $site = $this->site();

        Http::fake([self::URL => Http::response([
            'ok' => true,
            'module_installed' => true,
            'module_enabled' => false,
            'search_integrations' => [
                'verifications' => ['google' => 'site-gsc', 'bing' => 'BING1', 'yandex' => ''],
                'verification_files' => ['google' => ''],
                'google' => ['mode' => 'gtm', 'gtm_id' => 'GTM-SITE1', 'ga4_id' => ''],
                'yandex_metrica' => ['counter_id' => '123456'],
                'clarity' => ['project_id' => 'bad value!'],
            ],
            'managed' => ['by' => '', 'at' => '', 'fields' => []],
            'last_changed_at' => null,
        ], 200)]);

        $this->actingAs($this->user(OpsRole::Operator))
            ->post(route('ops.sites.search-integrations.pull', $site))
            ->assertRedirect(route('ops.sites.show', $site).'#search')
            ->assertSessionHas('status', __('search_integrations.flash.pulled'));

        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET' && $request->url() === self::URL);

        $record = $site->searchIntegration()->firstOrFail();
        $this->assertSame('site-gsc', $record->google_verification);
        $this->assertSame('gtm', $record->google_mode);
        $this->assertSame('GTM-SITE1', $record->gtm_id);
        $this->assertSame('123456', $record->yandex_metrica_id);
        $this->assertSame('', (string) $record->clarity_id);
        $this->assertFalse($record->site_module_enabled);
        $this->assertNotNull($record->pulled_at);
        $this->assertCount(count(SiteSearchIntegration::FIELDS), $record->managed_fields);
        $this->assertTrue(AuditLog::query()->where('action', 'site.search_integrations.pulled')->exists());
    }

    public function test_failed_pull_leaves_plane_untouched(): void
    {
        $site = $this->site();
        Http::fake([self::URL => Http::response('', 405)]);

        $this->actingAs($this->user(OpsRole::Operator))
            ->post(route('ops.sites.search-integrations.pull', $site))
            ->assertSessionHas('error', __('search_integrations.flash.pull_failed', ['reason' => __('search_integrations.errors.cms_too_old')]));

        $this->assertFalse($site->searchIntegration()->exists());
    }

    public function test_reconcile_pushes_pending_and_failed_sites_only(): void
    {
        $pending = $this->site('pending.example.test');
        $failed = $this->site('failed.example.test');
        $done = $this->site('done.example.test');

        $this->record($pending, ['google_verification' => 'a', 'managed_fields' => ['verifications.google'], 'changed_at' => now(), 'pushed_at' => null]);
        $this->record($failed, ['google_verification' => 'b', 'managed_fields' => ['verifications.google'], 'changed_at' => now()->subHour(), 'pushed_at' => now()->subHours(2), 'push_failed_at' => now()->subMinutes(30)]);
        $this->record($done, ['google_verification' => 'c', 'managed_fields' => ['verifications.google'], 'changed_at' => now()->subHour(), 'pushed_at' => now()->subMinutes(30)]);

        Http::fake(['*' => Http::response(['ok' => true, 'module_enabled' => true], 200)]);

        (new ReconcileSiteSearchIntegrationsJob)->handle(app(SiteSearchIntegrationsAgent::class));

        Http::assertSentCount(2);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'done.example.test'));
        $this->assertSame('ok', $failed->searchIntegration()->firstOrFail()->pushState());
    }

    public function test_fleet_list_column_and_filter_show_which_sites_have_gsc_and_measurement(): void
    {
        $configured = $this->site('configured.example.test', 'Configured Site');
        $bare = $this->site('bare.example.test', 'Bare Site');
        $this->record($configured, ['google_verification' => 'gsc', 'google_mode' => 'ga4', 'ga4_id' => 'G-FLEET1', 'managed_fields' => []]);

        $user = $this->user(OpsRole::Operator);
        $user->saveListPreference(SiteListColumns::LIST_KEY, [
            'columns' => [...SiteListColumns::defaults(), 'search'],
        ]);
        $user = $user->fresh() ?? $user;

        $this->actingAs($user)
            ->get(route('ops.sites'))
            ->assertOk()
            ->assertSee('data-search-cell', false)
            ->assertSee('G-FLEET1');

        $this->actingAs($user)
            ->get(route('ops.sites', ['analytics' => 'gsc_missing']))
            ->assertOk()
            ->assertSee('Bare Site')
            ->assertDontSee('Configured Site');

        $this->actingAs($user)
            ->get(route('ops.sites', ['analytics' => 'measurement_missing']))
            ->assertOk()
            ->assertSee('Bare Site')
            ->assertDontSee('Configured Site');
    }

    private function site(string $host = 'shop.example.test', ?string $name = null): Site
    {
        return Site::factory()->withSecrets()->create(array_filter([
            'status' => SiteStatus::Active,
            'primary_domain' => $host,
            'agent_base_url' => 'https://'.$host,
            'name' => $name,
        ]));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function record(Site $site, array $attributes): SiteSearchIntegration
    {
        return SiteSearchIntegration::query()->create(array_merge([
            'site_id' => $site->id,
            'google_mode' => 'off',
            'enable_module' => true,
            'changed_at' => now(),
        ], $attributes));
    }

    private function user(OpsRole $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);

        return $user;
    }
}
