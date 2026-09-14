<?php

namespace Database\Factories;

use App\Enums\Channel;
use App\Enums\CoolifyEnvKind;
use App\Models\CoolifyEnvDefault;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CoolifyEnvDefault>
 */
class CoolifyEnvDefaultFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'channel' => Channel::Main,
            'key' => 'APP_'.strtoupper(fake()->unique()->lexify('????')),
            'kind' => CoolifyEnvKind::Static,
            'value' => 'example',
            'is_secret' => false,
            'sort' => 100,
            'description' => null,
        ];
    }
}
