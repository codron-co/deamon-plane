<?php

namespace Database\Factories;

use App\Models\GithubSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GithubSetting>
 */
class GithubSettingFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'org' => 'deamon-themes',
            'token' => null,
            'app_id' => null,
            'installation_id' => null,
            'private_key' => null,
            'webhook_secret' => null,
        ];
    }

    public function withToken(string $token = 'github-test-token'): static
    {
        return $this->state(fn (array $attributes) => [
            'token' => $token,
        ]);
    }
}
