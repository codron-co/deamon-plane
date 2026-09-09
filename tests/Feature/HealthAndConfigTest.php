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
    }

    public function test_deamon_compose_file_is_coolify_compose(): void
    {
        $this->assertSame('docker-compose.coolify.yml', config('ops.deamon.compose_file'));
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
