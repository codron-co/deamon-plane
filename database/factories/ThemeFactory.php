<?php

namespace Database\Factories;

use App\Enums\ThemeVisibility;
use App\Models\Theme;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Theme>
 */
class ThemeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $id = fake()->unique()->slug(1);

        return [
            'theme_id' => $id,
            'name' => ucfirst($id),
            'repo_full_name' => 'deamon-themes/deamon-theme-'.$id,
            'default_ref' => 'main',
            'visibility' => ThemeVisibility::Private,
            'minimum_deamon_version' => null,
            'latest_sha' => null,
            'latest_tag' => null,
            'last_synced_at' => null,
            'description' => null,
        ];
    }

    public function publicCatalog(): static
    {
        return $this->state(fn (array $attributes) => [
            'visibility' => ThemeVisibility::PublicCatalog,
        ]);
    }

    public function allowlist(): static
    {
        return $this->state(fn (array $attributes) => [
            'visibility' => ThemeVisibility::Allowlist,
        ]);
    }
}
