<?php

namespace Tests\Feature\Security\Concerns;

use App\Enums\OpsRole;
use App\Enums\SiteStatus;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Stand-ins for the CMS security endpoints: nothing here reaches a real site.
 * `fakeSite()` registers one host; every request it gets is kept in `$this->siteCalls`.
 */
trait FakesSecuritySites
{
    /** @var list<array{host: string, method: string, path: string, body: array<string, mixed>, signed: bool}> */
    protected array $siteCalls = [];

    protected function bootSecurityFakes(): void
    {
        $this->seed(RoleSeeder::class);
        Http::preventStrayRequests();
        $this->siteCalls = [];
    }

    protected function activeSite(string $slug, array $attributes = []): Site
    {
        return Site::factory()->withSecrets()->create($attributes + [
            'slug' => $slug,
            'name' => ucfirst($slug),
            'primary_domain' => $slug.'.example.test',
            'status' => SiteStatus::Active,
        ]);
    }

    protected function user(OpsRole $role = OpsRole::SuperAdmin): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);

        return $user;
    }

    /**
     * @param  callable(Request): mixed|array<string, mixed>  $answer  array = 200 JSON, callable = custom response
     */
    protected function fakeSite(string $slug, callable|array $answer): void
    {
        Http::fake([
            'https://'.$slug.'.example.test/internal/control/v1/security/*' => function (Request $request) use ($slug, $answer) {
                $this->siteCalls[] = [
                    'host' => $slug,
                    'method' => $request->method(),
                    'path' => parse_url($request->url(), PHP_URL_PATH),
                    'body' => $request->method() === 'POST' ? (array) json_decode($request->body(), true) : [],
                    'signed' => $request->hasHeader('X-Deamon-Signature') && $request->hasHeader('X-Deamon-Timestamp') && $request->hasHeader('X-Deamon-Nonce'),
                    'query' => parse_url($request->url(), PHP_URL_QUERY),
                ];

                return is_callable($answer) ? $answer($request) : Http::response($answer, 200);
            },
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function eventPayload(string $uid, array $overrides = []): array
    {
        return $overrides + [
            'uid' => $uid,
            'occurred_at' => now()->subMinutes(5)->toIso8601String(),
            'type' => 'probe',
            'severity' => 1,
            'rule' => 'sensitive_probe',
            'ip' => '203.0.113.7',
            'country' => 'US',
            'user_agent' => 'python-httpx/0.27',
            'method' => 'GET',
            'path' => '/.env',
            'status' => 403,
            'count' => 1,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $events
     * @return array<string, mixed>
     */
    protected function summaryPayload(array $events, array $overrides = []): array
    {
        return $overrides + [
            'module_enabled' => true,
            'generated_at' => now()->toIso8601String(),
            'counts' => [
                '24h' => ['total' => count($events), 'by_severity' => ['0' => 0, '1' => count($events), '2' => 0], 'by_type' => ['probe' => count($events)]],
                '7d' => ['total' => count($events), 'by_severity' => ['0' => 0, '1' => count($events), '2' => 0], 'by_type' => ['probe' => count($events)]],
            ],
            'top_ips' => [['ip' => '203.0.113.7', 'events' => count($events), 'critical' => 0, 'rules' => ['sensitive_probe']]],
            'blocks_active' => 0,
            'cursor' => 'c-'.count($events),
            'has_more' => false,
            'events' => $events,
        ];
    }
}
