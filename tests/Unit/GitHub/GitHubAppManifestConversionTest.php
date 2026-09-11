<?php

namespace Tests\Unit\GitHub;

use App\Services\GitHub\GitHubAppClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GitHubAppManifestConversionTest extends TestCase
{
    public function test_convert_app_manifest_posts_empty_body(): void
    {
        Http::fake([
            'https://api.github.com/app-manifests/manifest-code/conversions' => Http::response([
                'id' => 42,
                'slug' => 'deamon-plane-themes',
                'pem' => "-----BEGIN RSA PRIVATE KEY-----\nMIIE\n-----END RSA PRIVATE KEY-----",
                'client_id' => 'Iv1.abc',
                'client_secret' => 'secret',
                'webhook_secret' => 'hook',
            ], 201),
        ]);

        (new GitHubAppClient())->convertAppManifest('manifest-code');

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://api.github.com/app-manifests/manifest-code/conversions'
                && $request->method() === 'POST'
                && $request->body() === '';
        });
    }
}
