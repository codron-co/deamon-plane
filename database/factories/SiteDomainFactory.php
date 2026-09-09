<?php

namespace Database\Factories;

use App\Models\Site;
use App\Models\SiteDomain;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SiteDomain>
 */
class SiteDomainFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'domain' => fake()->unique()->domainName(),
            'is_primary' => false,
            'coolify_domain_id' => null,
            'verified_at' => null,
        ];
    }

    public function primary(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_primary' => true,
        ]);
    }
}
