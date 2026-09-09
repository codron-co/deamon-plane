<?php

namespace Database\Factories;

use App\Enums\ThemeInstallationStatus;
use App\Models\Site;
use App\Models\SiteThemeInstallation;
use App\Models\Theme;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SiteThemeInstallation>
 */
class SiteThemeInstallationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'theme_id' => Theme::factory(),
            'ref' => 'main',
            'pinned_sha' => null,
            'is_active' => false,
            'auto_update' => false,
            'status' => ThemeInstallationStatus::Pending,
            'last_error' => null,
            'updated_from_webhook_at' => null,
        ];
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => true,
            'status' => ThemeInstallationStatus::Active,
        ]);
    }

    public function autoUpdate(): static
    {
        return $this->state(fn (array $attributes) => [
            'auto_update' => true,
        ]);
    }
}
