<?php

namespace Database\Factories;

use App\Enums\MailProvider;
use App\Models\MailServer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MailServer>
 */
class MailServerFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Hostinger mail',
            'provider' => MailProvider::Hostinger,
            'api_token' => null,
            'hostinger_order_id' => null,
            'mail_domain' => null,
            'is_enabled' => true,
            'last_probe_at' => null,
            'last_probe_payload' => null,
        ];
    }

    public function hostingerReady(string $token = 'hapi-test-token-never-show'): static
    {
        return $this->state(fn (array $attributes) => [
            'provider' => MailProvider::Hostinger,
            'api_token' => $token,
            'hostinger_order_id' => 'OR1a2b3c4d5e6f7g',
            'mail_domain' => 'example.com',
            'is_enabled' => true,
        ]);
    }
}
