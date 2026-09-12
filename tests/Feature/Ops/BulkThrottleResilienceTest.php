<?php

namespace Tests\Feature\Ops;

use App\Enums\OpsRole;
use App\Enums\SiteStatus;
use App\Models\CoolifyConnection;
use App\Models\OpsBackgroundJob;
use App\Models\Site;
use App\Models\User;
use App\Services\Ops\OpsJobRunner;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * The product bar: an operator clicks a bulk action and every selected site
 * eventually succeeds. Coolify throttling must slow the sweep, never fail it.
 */
class BulkThrottleResilienceTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'plane-coolify-ops-token';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Queue::fake();
        Sleep::fake();
        Http::preventStrayRequests();
        config()->set('ops.coolify.rate.min_interval_ms', 0);
        config()->set('ops.coolify.retry.max_attempts', 1);
        config()->set('ops.coolify.bulk.max_site_attempts', 6);
    }

    public function test_bulk_pin_completes_every_site_even_when_coolify_throttles(): void
    {
        $sites = $this->sites(4);
        $throttleBudget = 5;

        Http::fake(function (Request $request) use (&$throttleBudget) {
            if ($throttleBudget > 0) {
                $throttleBudget--;

                return Http::response(['message' => 'Too Many Attempts.'], 429);
            }

            return $this->coolifyResponse($request);
        });

        $message = app(OpsJobRunner::class)->run($this->job('sites.bulk_pin', $sites, ['ref' => 'abc1234']));

        $this->assertStringContainsString('4', $message);
        $this->assertStringNotContainsStringIgnoringCase('Too Many Attempts', $message);

        foreach ($sites as $site) {
            $this->assertSame(
                1,
                $site->deployments()->count(),
                $site->name.' should have exactly one deployment, not a duplicate from retries.',
            );
        }
    }

    public function test_bulk_pin_summary_is_turkish_and_has_no_english_fragments(): void
    {
        $this->app->setLocale('tr');
        $sites = $this->sites(2);

        Http::fake(fn (Request $request) => $this->coolifyResponse($request));

        $message = app(OpsJobRunner::class)->run($this->job('sites.bulk_pin', $sites, ['ref' => 'abc1234']));

        $this->assertStringContainsString('tamam', $message);
        $this->assertStringNotContainsString(' ok', $message);
        $this->assertStringNotContainsString('failed', $message);
    }

    public function test_a_throttled_sweep_that_finishes_every_site_never_claims_sites_were_skipped(): void
    {
        $this->app->setLocale('tr');
        $sites = $this->sites(3);
        $throttleBudget = 4;

        Http::fake(function (Request $request) use (&$throttleBudget) {
            if ($throttleBudget > 0) {
                $throttleBudget--;

                return Http::response(['message' => 'Too Many Attempts.'], 429);
            }

            return $this->coolifyResponse($request);
        });

        $message = app(OpsJobRunner::class)->run($this->job('sites.bulk_pin', $sites, ['ref' => 'abc1234']));

        $this->assertSame(__('site_ops.pin.bulk').' '.__('ops.bulk.result', ['ok' => 3]), $message);
        $this->assertStringNotContainsString('atlandı', $message);
        $this->assertStringNotContainsString(__('ops.bulk.rate_limited'), $message);
        $this->assertStringNotContainsString('hata', $message);
    }

    public function test_a_site_the_throttle_never_let_through_is_reported_as_skipped_in_turkish(): void
    {
        $this->app->setLocale('tr');
        config()->set('ops.coolify.bulk.max_site_attempts', 2);
        $sites = $this->sites(1);

        Http::fake(['*' => Http::response(['message' => 'Too Many Attempts.'], 429)]);

        $message = app(OpsJobRunner::class)->run($this->job('sites.bulk_pin', $sites, ['ref' => 'abc1234']));

        $this->assertStringContainsString('atlandı', $message);
        $this->assertStringContainsString(__('ops.bulk.rate_limited'), $message);
        $this->assertStringNotContainsString('hata', $message);
        $this->assertStringNotContainsStringIgnoringCase('Too Many Attempts', $message);
    }

    public function test_a_non_throttle_error_is_a_failure_without_the_skipped_warning(): void
    {
        $this->app->setLocale('tr');
        $sites = $this->sites(1);

        Http::fake(function (Request $request) {
            if ($request->method() === 'PATCH' && preg_match('#/applications/[^/?]+$#', $request->url()) === 1) {
                return Http::response(['message' => 'Validation failed.'], 422);
            }

            return $this->coolifyResponse($request);
        });

        $message = app(OpsJobRunner::class)->run($this->job('sites.bulk_pin', $sites, ['ref' => 'abc1234']));

        $this->assertStringContainsString('hata', $message);
        $this->assertStringNotContainsString('atlandı', $message);
        $this->assertStringNotContainsString(__('ops.bulk.rate_limited'), $message);
    }

    public function test_mixed_sweep_counts_recovered_sites_as_ok_and_only_leftovers_as_skipped(): void
    {
        $this->app->setLocale('tr');
        config()->set('ops.coolify.bulk.max_site_attempts', 2);
        $sites = $this->sites(2);
        $blocked = $sites[1]->coolify_app_uuid;

        Http::fake(function (Request $request) use ($blocked) {
            if (str_contains($request->url(), (string) $blocked)) {
                return Http::response(['message' => 'Too Many Attempts.'], 429);
            }

            return $this->coolifyResponse($request);
        });

        $message = app(OpsJobRunner::class)->run($this->job('sites.bulk_pin', $sites, ['ref' => 'abc1234']));

        $this->assertSame(
            __('site_ops.pin.bulk').' '
            .__('ops.bulk.result_skipped', ['ok' => 1, 'skipped' => 1])
            .' — '.__('ops.bulk.rate_limited'),
            $message,
        );
    }

    public function test_bulk_sync_reports_progress_per_site(): void
    {
        $sites = $this->sites(3);
        Http::fake(fn (Request $request) => $this->coolifyResponse($request));

        $job = $this->job('sites.coolify_sync', $sites);
        app(OpsJobRunner::class)->run($job);

        $this->assertSame(100, $job->fresh()?->progress);
    }

    public function test_bulk_redeploy_retries_a_throttled_site_without_double_deploying(): void
    {
        $sites = $this->sites(2);
        $throttled = false;

        Http::fake(function (Request $request) use (&$throttled) {
            if (! $throttled && $request->method() === 'POST' && str_contains($request->url(), '/deploy')) {
                $throttled = true;

                return Http::response(['message' => 'Too Many Attempts.'], 429);
            }

            return $this->coolifyResponse($request);
        });

        app(OpsJobRunner::class)->run($this->job('sites.bulk_deploy', $sites));

        foreach ($sites as $site) {
            $this->assertSame(1, $site->deployments()->count());
        }
    }

    /**
     * @param  list<Site>  $sites
     * @param  array<string, mixed>  $payload
     */
    private function job(string $type, array $sites, array $payload = []): OpsBackgroundJob
    {
        return OpsBackgroundJob::query()->create([
            'type' => $type,
            'title' => $type,
            'status' => 'queued',
            'progress' => 0,
            'payload' => array_merge($payload, [
                'site_ids' => array_map(static fn (Site $site) => $site->id, $sites),
            ]),
            'actor_user_id' => $this->operator()->id,
        ]);
    }

    private function coolifyResponse(Request $request)
    {
        $url = $request->url();

        if (str_contains($url, '/envs')) {
            return Http::response([], 200);
        }

        if ($request->method() === 'POST' && str_contains($url, '/deploy')) {
            return Http::response([
                'deployments' => [['deployment_uuid' => 'dep-'.bin2hex(random_bytes(4))]],
            ], 200);
        }

        if (str_contains($url, '/deployments/applications/')) {
            return Http::response([], 200);
        }

        return Http::response([
            'uuid' => 'bulk-app',
            'build_pack' => 'dockercompose',
            'git_commit_sha' => 'abc1234',
            'is_auto_deploy_enabled' => false,
            'settings' => ['is_auto_deploy_enabled' => false],
        ], 200);
    }

    /**
     * @return list<Site>
     */
    private function sites(int $count): array
    {
        $connection = CoolifyConnection::factory()->create([
            'base_url' => 'https://coolify.example',
            'api_token' => self::TOKEN,
        ]);

        $sites = [];
        for ($i = 0; $i < $count; $i++) {
            $sites[] = Site::factory()->create([
                'status' => SiteStatus::Active,
                'coolify_app_uuid' => 'bulk-app-'.$i,
                'coolify_connection_id' => $connection->id,
            ]);
        }

        return $sites;
    }

    private function operator(): User
    {
        $operator = User::factory()->create();
        $operator->assignRole(OpsRole::Operator->value);

        return $operator;
    }
}
