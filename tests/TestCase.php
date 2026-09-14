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
