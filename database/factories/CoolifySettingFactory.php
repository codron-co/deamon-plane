<?php

namespace Database\Factories;

use App\Models\CoolifySetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CoolifySetting>
 */
class CoolifySettingFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'base_url' => 'https://coolify.test',
            'api_token' => 'test-coolify-token',
            'default_project_uuid' => 'proj_test',
            'default_server_uuid' => 'srv_test',
            'github_app_uuid' => null,
            'private_key_uuid' => null,
            'webhook_secret' => null,
        ];
    }
}
