<?php

namespace Database\Factories;

use App\Models\DatabaseServer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DatabaseServer>
 */
class DatabaseServerFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Deamon MySQL',
            'host' => 'deamon-db-mysql',
            'port' => 3306,
            'admin_username' => 'plane_admin',
            'admin_password' => 'admin-secret-never-show',
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
