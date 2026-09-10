<?php

namespace Tests\Unit\Sites;

use App\Enums\Channel;
use App\Services\Sites\ChannelEnvironmentMap;
use Tests\TestCase;

class ChannelEnvironmentMapTest extends TestCase
{
    public function test_coolify_environment_name_matches_channel_one_to_one(): void
    {
        $this->assertSame('main', ChannelEnvironmentMap::coolifyName(Channel::Main));
        $this->assertSame('beta', ChannelEnvironmentMap::coolifyName(Channel::Beta));
        $this->assertSame('alpha', ChannelEnvironmentMap::coolifyName(Channel::Alpha));
    }

    public function test_main_prefers_main_and_keeps_production_as_legacy_alias(): void
    {
        $this->assertSame(['main', 'production', 'prod'], ChannelEnvironmentMap::environmentNames(Channel::Main));
    }

    public function test_legacy_production_environment_name_canonicalizes_to_main(): void
    {
        $this->assertSame('main', ChannelEnvironmentMap::canonicalizeEnvironmentName('production'));
        $this->assertSame('main', ChannelEnvironmentMap::canonicalizeEnvironmentName('prod'));
        $this->assertSame('main', ChannelEnvironmentMap::canonicalizeEnvironmentName(''));
        $this->assertSame('beta', ChannelEnvironmentMap::canonicalizeEnvironmentName('beta'));
        $this->assertSame('alpha', ChannelEnvironmentMap::canonicalizeEnvironmentName('alpha'));
    }

    public function test_laravel_app_env_stays_on_framework_names(): void
    {
        $this->assertSame('production', ChannelEnvironmentMap::appEnv(Channel::Main));
        $this->assertSame('staging', ChannelEnvironmentMap::appEnv(Channel::Beta));
        $this->assertSame('local', ChannelEnvironmentMap::appEnv(Channel::Alpha));
    }
}
