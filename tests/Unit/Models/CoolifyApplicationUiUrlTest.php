<?php

namespace Tests\Unit\Models;

use App\Enums\Channel;
use App\Enums\SiteStatus;
use App\Models\CoolifyConnection;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CoolifyApplicationUiUrlTest extends TestCase
{
    use RefreshDatabase;

    public function test_site_link_uses_environment_uuid_not_name_or_channel(): void
    {
        $connection = CoolifyConnection::factory()->create([
            'base_url' => 'https://dev.codron.cloud',
            'is_default' => true,
            'default_project_uuid' => 'z8ocg8k04ww8osssccc088c0',
            'default_environment_uuid' => 'sns276euzsz2fprqg3xgfz17',
            'default_environment_name' => 'alpha',
        ]);

        $site = Site::factory()->create([
            'status' => SiteStatus::Active,
            'channel' => Channel::Alpha,
            'coolify_app_uuid' => 'a3p6sgysfwjqhv4yntth1n85',
            'coolify_connection_id' => $connection->id,
            'coolify_project_uuid' => 'z8ocg8k04ww8osssccc088c0',
            'coolify_environment_uuid' => 'i0sw4kk0cogg4o08oscwcssk',
        ]);

        $this->assertSame(
            'https://dev.codron.cloud/project/z8ocg8k04ww8osssccc088c0/environment/i0sw4kk0cogg4o08oscwcssk/application/a3p6sgysfwjqhv4yntth1n85',
            $site->coolifyUiUrl(),
        );
        $this->assertStringNotContainsString('/environment/alpha/', (string) $site->coolifyUiUrl());
    }

    public function test_missing_site_env_falls_back_to_connection_environment_uuid(): void
    {
        $connection = CoolifyConnection::factory()->create([
            'base_url' => 'https://dev.codron.cloud',
            'is_default' => true,
            'default_project_uuid' => 'z8ocg8k04ww8osssccc088c0',
            'default_environment_uuid' => 'i0sw4kk0cogg4o08oscwcssk',
            'default_environment_name' => 'production',
        ]);

        $url = $connection->applicationUiUrl('a3p6sgysfwjqhv4yntth1n85');

        $this->assertSame(
            'https://dev.codron.cloud/project/z8ocg8k04ww8osssccc088c0/environment/i0sw4kk0cogg4o08oscwcssk/application/a3p6sgysfwjqhv4yntth1n85',
            $url,
        );
        $this->assertStringNotContainsString('/environment/production/', (string) $url);
    }

    public function test_name_only_environment_does_not_build_a_broken_deep_link(): void
    {
        $connection = CoolifyConnection::factory()->create([
            'base_url' => 'https://dev.codron.cloud',
            'is_default' => true,
            'default_project_uuid' => 'z8ocg8k04ww8osssccc088c0',
            'default_environment_uuid' => null,
            'default_environment_name' => 'alpha',
        ]);

        $this->assertSame('https://dev.codron.cloud', $connection->applicationUiUrl('a3p6sgysfwjqhv4yntth1n85'));
    }
}
