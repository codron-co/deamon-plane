<?php

namespace Tests;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
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
