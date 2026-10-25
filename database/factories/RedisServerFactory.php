<?php

namespace Database\Factories;

use App\Models\RedisServer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RedisServer>
 */
class RedisServerFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Deamon Redis',
            'host' => 'deamon-redis',
            'port' => 6379,
            'admin_username' => 'plane_admin',
            'admin_password' => 'redis-admin-secret-never-show',
            'is_default' => false,
            'notes' => null,
            'last_probe_at' => null,
            'last_probe_payload' => null,
        ];
    }

    public function default(): static
    {
        return $this->state(fn (array $attributes) => ['is_default' => true]);
    }
}
