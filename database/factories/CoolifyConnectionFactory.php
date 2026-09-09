<?php

namespace Database\Factories;

use App\Models\CoolifyConnection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CoolifyConnection>
 */
class CoolifyConnectionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Coolify',
            'base_url' => 'https://coolify.test',
            'api_token' => 'test-coolify-token',
            'webhook_secret' => null,
            'is_enabled' => true,
            'is_default' => true,
            'default_project_uuid' => 'proj_test',
            'default_server_uuid' => 'srv_test',
            'default_environment_uuid' => null,
            'default_environment_name' => 'production',
            'default_git_source_uuid' => null,
            'default_git_source_kind' => null,
        ];
    }
}
