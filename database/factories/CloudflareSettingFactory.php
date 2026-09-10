<?php

namespace Database\Factories;

use App\Models\CloudflareSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CloudflareSetting>
 */
class CloudflareSettingFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Cloudflare',
            'account_id' => null,
            'api_token' => null,
            'origin_ipv4' => '72.62.117.147',
            'wildcard_domain' => null,
            'proxied' => false,
            'mail_template_enabled' => true,
            'is_enabled' => true,
            'is_default' => true,
            'last_probe_at' => null,
            'last_probe_payload' => null,
        ];
    }

    public function withToken(string $token = 'cf-test-token'): static
    {
        return $this->state(fn (array $attributes) => [
            'api_token' => $token,
            'account_id' => $attributes['account_id'] ?? 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4',
        ]);
    }
}
