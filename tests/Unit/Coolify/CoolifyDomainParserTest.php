<?php

namespace Tests\Unit\Coolify;

use App\Services\Coolify\CoolifyApiException;
use App\Services\Coolify\CoolifyDomainParser;
use PHPUnit\Framework\TestCase;

class CoolifyDomainParserTest extends TestCase
{
    public function test_parses_live_json_string_object(): void
    {
        $live = '{"app":{"domain":"https://susa.demo.codron.co,https://www.susa.demo.codron.co/"}}';

        $normalized = CoolifyDomainParser::normalize($live);

        $this->assertSame([
            [
                'name' => 'app',
                'domain' => 'https://susa.demo.codron.co,https://www.susa.demo.codron.co/',
            ],
        ], $normalized);
        $this->assertSame('https://susa.demo.codron.co', CoolifyDomainParser::firstDomain($live));
    }

    public function test_parses_openapi_array_and_keeps_it_for_patch(): void
    {
        $array = [
            ['name' => 'app', 'domain' => 'https://www.example.com'],
        ];

        $this->assertSame($array, CoolifyDomainParser::normalize($array));
        $this->assertSame($array, CoolifyDomainParser::forPatch($array));
    }

    public function test_string_fqdn_attaches_to_app_service_with_https(): void
    {
        $this->assertSame([
            ['name' => 'app', 'domain' => 'https://izyem.example'],
        ], CoolifyDomainParser::forPatch('izyem.example'));
    }

    public function test_for_patch_rejects_empty_domain(): void
    {
        $this->expectException(CoolifyApiException::class);
        $this->expectExceptionCode(422);

        CoolifyDomainParser::forPatch('');
    }
}
