<?php

namespace Tests\Unit\Coolify;

use App\Support\CoolifyWebhookSignature;
use Illuminate\Http\Request;
use Tests\TestCase;

class CoolifyWebhookSignatureTest extends TestCase
{
    public function test_sign_and_match_sha256_prefix(): void
    {
        $payload = '{"event":"deployment_success"}';
        $header = CoolifyWebhookSignature::sign('secret', $payload);

        $this->assertTrue(CoolifyWebhookSignature::matches('secret', $payload, $header));
        $this->assertFalse(CoolifyWebhookSignature::matches('secret', $payload, 'sha256=deadbeef'));
        $this->assertFalse(CoolifyWebhookSignature::matches('', $payload, $header));
    }

    public function test_reads_preferred_header_first(): void
    {
        $request = Request::create('/webhooks/coolify', 'POST', [], [], [], [
            'HTTP_X_SIGNATURE' => 'sha256=aaaa',
            'HTTP_X_COOLIFY_SIGNATURE' => 'sha256=bbbb',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256=cccc',
        ]);

        $this->assertSame('sha256=bbbb', CoolifyWebhookSignature::headerFromRequest($request));
    }

    public function test_query_token_matches_token_or_secret_param(): void
    {
        $tokenRequest = Request::create('/webhooks/coolify?token=plane-secret', 'POST');
        $secretRequest = Request::create('/webhooks/coolify?secret=plane-secret', 'POST');
        $emptyRequest = Request::create('/webhooks/coolify?token=', 'POST');

        $this->assertSame('plane-secret', CoolifyWebhookSignature::queryTokenFromRequest($tokenRequest));
        $this->assertSame('plane-secret', CoolifyWebhookSignature::queryTokenFromRequest($secretRequest));
        $this->assertNull(CoolifyWebhookSignature::queryTokenFromRequest($emptyRequest));

        $this->assertTrue(CoolifyWebhookSignature::queryTokenMatches('plane-secret', 'plane-secret'));
        $this->assertFalse(CoolifyWebhookSignature::queryTokenMatches('plane-secret', 'wrong'));
        $this->assertFalse(CoolifyWebhookSignature::queryTokenMatches('', 'plane-secret'));
        $this->assertFalse(CoolifyWebhookSignature::queryTokenMatches('plane-secret', ''));
        $this->assertFalse(CoolifyWebhookSignature::queryTokenMatches('plane-secret', null));
    }
}
