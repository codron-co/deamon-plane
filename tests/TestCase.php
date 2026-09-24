<?php

namespace Tests;

use App\Enums\Channel;
use App\Services\Coolify\EnvCatalog\CoolifyEnvCatalogSync;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

abstract class TestCase extends BaseTestCase
{
    /**
     * Copy of the CMS `.env.production.example` (the live catalog source per branch).
     */
    public const ENV_EXAMPLE_FIXTURE = __DIR__.'/Fixtures/deamon/env-production.example';

    protected function setUp(): void
    {
        parent::setUp();

        // The migrated schema has no catalog rows (they come from GitHub in production),
        // so every database test starts from the fixture for all channels.
        if (in_array(RefreshDatabase::class, class_uses_recursive(static::class), true)) {
            $this->seedEnvCatalogFixture();
        }
    }

    protected function seedEnvCatalogFixture(): void
    {
        if (! Schema::hasTable('coolify_env_defaults')) {
            return;
        }

        $contents = (string) file_get_contents(self::ENV_EXAMPLE_FIXTURE);
        $sync = app(CoolifyEnvCatalogSync::class);

        foreach (Channel::cases() as $channel) {
            $sync->importContents($channel, $contents, sha: 'fixture0'.$channel->value, repo: 'codron-co/deamon');
        }
    }

    /**
     * assertSame for an object read back from a JSON column. MySQL's JSON type
     * stores object keys in its own order (shortest key first); sqlite keeps
     * insertion order. Key order is not part of the contract, list order is.
     *
     * @param  array<array-key, mixed>  $expected
     */
    protected function assertSameJsonObject(array $expected, mixed $actual, string $message = ''): void
    {
        $this->assertIsArray($actual, $message);
        $this->assertSame(self::sortObjectKeys($expected), self::sortObjectKeys($actual), $message);
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private static function sortObjectKeys(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::sortObjectKeys($item);
            }
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    /**
     * Plane deploy syncs Coolify env against Settings catalogs (GET /envs + PATCH /envs/bulk).
     *
     * @return PromiseInterface|null
     */
    protected function coolifyEnvSyncResponse(Request $request): mixed
    {
        $url = $request->url();
        $method = $request->method();

        if ($method === 'GET' && str_contains($url, '/envs') && ! str_contains($url, '/envs/bulk')) {
            return Http::response([], 200);
        }

        if ($method === 'PATCH' && str_contains($url, '/envs/bulk')) {
            return Http::response([], 200);
        }

        return null;
    }
}
