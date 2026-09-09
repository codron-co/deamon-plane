<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthAndConfigTest extends TestCase
{
    public function test_health_endpoint_returns_ok(): void
    {
        $this->get('/up')->assertOk();
    }

    public function test_ops_channels_are_the_allowlist(): void
    {
        $this->assertSame(['main', 'beta', 'alpha'], config('ops.channels'));
        $this->assertSame(['beta', 'alpha'], config('ops.channel_switch.downgrade_requires_confirm.main'));
        $this->assertSame(['main'], config('ops.channel_switch.upgrade_requires_version_gate.beta'));
        $this->assertSame(['main'], config('ops.channel_switch.upgrade_requires_version_gate.alpha'));
        $this->assertSame('/internal/control/v1/health', config('ops.agent.health_path'));
        $this->assertSame('/internal/control/v1/themes', config('ops.agent.theme_list_path'));
        $this->assertSame('/internal/control/v1/themes/install', config('ops.agent.theme_install_path'));
        $this->assertSame('/internal/control/v1/themes/update', config('ops.agent.theme_update_path'));
        $this->assertSame('/internal/control/v1/themes/activate', config('ops.agent.theme_activate_path'));
        $this->assertSame('/internal/control/v1/themes/sync', config('ops.agent.theme_sync_path'));
        $this->assertGreaterThanOrEqual(5, (int) config('ops.agent.poll_minutes'));
        $this->assertLessThanOrEqual(15, (int) config('ops.agent.poll_minutes'));
    }

    public function test_deamon_compose_file_is_coolify_compose(): void
    {
        $this->assertSame('/docker-compose.coolify.yml', config('ops.deamon.compose_file'));
    }

    public function test_app_url_falls_back_to_service_url_app(): void
    {
        $this->assertNotFalse(
            str_contains(
                file_get_contents(config_path('app.php')),
                "env('SERVICE_URL_APP')"
            )
        );
    }
}
