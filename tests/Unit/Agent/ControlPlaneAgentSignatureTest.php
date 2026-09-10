<?php

namespace Tests\Unit\Agent;

use App\Services\Agent\ControlPlaneAgentContract;
use App\Support\ControlPlaneAgentSignature;
use Tests\TestCase;

class ControlPlaneAgentSignatureTest extends TestCase
{
    public function test_canonical_string_is_timestamp_nonce_body(): void
    {
        $this->assertSame(
            '1700000000.abc.',
            ControlPlaneAgentSignature::canonicalString('1700000000', 'abc', ''),
        );
        $this->assertSame(
            'timestamp.nonce.body',
            ControlPlaneAgentContract::CANONICAL_FORMAT,
        );
    }

    public function test_sign_is_hmac_sha256_hex_and_does_not_echo_the_secret(): void
    {
        $secret = 'super-secret-agent-key';
        $signature = ControlPlaneAgentSignature::sign($secret, '1700000000', 'nonce1', '');

        $this->assertSame(
            hash_hmac('sha256', '1700000000.nonce1.', $secret),
            $signature,
        );
        $this->assertDoesNotMatchRegularExpression('/[^0-9a-f]/', $signature);
        $this->assertStringNotContainsString($secret, $signature);

        $this->assertTrue(ControlPlaneAgentSignature::matches($secret, '1700000000', 'nonce1', '', $signature));
        $this->assertFalse(ControlPlaneAgentSignature::matches('other', '1700000000', 'nonce1', '', $signature));
        $this->assertFalse(ControlPlaneAgentSignature::matches('', '1700000000', 'nonce1', '', $signature));
    }

    public function test_headers_use_locked_cms_deamon_names(): void
    {
        $signed = ControlPlaneAgentSignature::headers('secret', '', 1700000000, 'deadbeef');

        $this->assertSame('1700000000', $signed['headers'][ControlPlaneAgentContract::HEADER_TIMESTAMP]);
        $this->assertSame('deadbeef', $signed['headers'][ControlPlaneAgentContract::HEADER_NONCE]);
        $this->assertSame($signed['signature'], $signed['headers'][ControlPlaneAgentContract::HEADER_SIGNATURE]);
        $this->assertSame('X-Deamon-Timestamp', ControlPlaneAgentContract::HEADER_TIMESTAMP);
        $this->assertSame('X-Deamon-Nonce', ControlPlaneAgentContract::HEADER_NONCE);
        $this->assertSame('X-Deamon-Signature', ControlPlaneAgentContract::HEADER_SIGNATURE);
        $this->assertArrayNotHasKey('X-Control-Plane-Timestamp', $signed['headers']);
        $this->assertArrayNotHasKey('X-Control-Plane-Nonce', $signed['headers']);
        $this->assertArrayNotHasKey('X-Control-Plane-Signature', $signed['headers']);
    }

    public function test_generated_nonce_is_uuid_within_cms_length(): void
    {
        $signed = ControlPlaneAgentSignature::headers('secret', '');
        $nonce = $signed['nonce'];
        $timestamp = $signed['timestamp'];

        $this->assertMatchesRegularExpression('/^\d+$/', $timestamp);
        $this->assertGreaterThanOrEqual(ControlPlaneAgentContract::NONCE_MIN_LENGTH, strlen($nonce));
        $this->assertLessThanOrEqual(ControlPlaneAgentContract::NONCE_MAX_LENGTH, strlen($nonce));
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $nonce,
        );
    }

    public function test_post_body_is_compact_json_and_signed_as_raw_bytes(): void
    {
        $payload = ControlPlaneAgentContract::installBody('beyazoglu', 'deamon-themes/premium-beyazoglu', 'main', 'abc123');
        $body = ControlPlaneAgentContract::encodeJson($payload);

        $this->assertSame(
            '{"theme_id":"beyazoglu","repo":"deamon-themes/premium-beyazoglu","ref":"main","source":"git","sha":"abc123"}',
            $body,
        );
        $this->assertStringNotContainsString("\n", $body);

        $signed = ControlPlaneAgentSignature::headers('secret', $body, 1700000000, 'nonce1');
        $this->assertSame(
            ControlPlaneAgentSignature::sign('secret', '1700000000', 'nonce1', $body),
            $signed['signature'],
        );
        $this->assertSame('1.2.7', ControlPlaneAgentContract::CMS_VERSION);
        $this->assertSame('/internal/control/v1/themes', ControlPlaneAgentContract::THEME_LIST_PATH);
        $this->assertSame(
            ['action' => 'sync_all', 'mode' => 'merge', 'theme_id' => 'beyazoglu'],
            ControlPlaneAgentContract::syncBody('beyazoglu'),
        );
    }
}
