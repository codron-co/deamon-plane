<?php

namespace Database\Factories;

use App\Models\CloudflareDnsDefault;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CloudflareDnsDefault>
 */
class CloudflareDnsDefaultFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => 'A',
            'name' => '@',
            'content' => '72.62.117.147',
            'ttl' => 1,
            'priority' => null,
            'sort_order' => 0,
        ];
    }
}
