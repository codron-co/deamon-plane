<?php

namespace Database\Factories;

use App\Enums\Channel;
use App\Enums\SiteStatus;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Site>
 */
class SiteFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $slug = fake()->unique()->slug(2);

        return [
            'slug' => $slug,
            'name' => fake()->company(),
            'primary_domain' => $slug.'.example.test',
            'channel' => Channel::Main,
            'desired_channel' => null,
            'status' => SiteStatus::Draft,
            'git_repository' => config('ops.deamon.repository', 'https://github.com/codron-co/deamon.git'),
            'app_key_encrypted' => null,
            'agent_secret_encrypted' => null,
            'notes' => null,
        ];
    }

    public function draft(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SiteStatus::Draft,
        ]);
    }

    public function channel(Channel $channel): static
    {
        return $this->state(fn (array $attributes) => [
            'channel' => $channel,
        ]);
    }

    public function dockerfilePack(): static
    {
        return $this->state(fn (array $attributes) => [
            'notes' => '[import] '.Site::DOCKERFILE_BUILD_PACK_MARKER.': Coolify build_pack is dockerfile (compose preferred)',
        ]);
    }

    /**
     * Secrets are generated at provision time (Task 4), not at draft create.
     */
    public function withSecrets(): static
    {
        return $this->state(fn (array $attributes) => [
            'app_key_encrypted' => 'base64:'.base64_encode(random_bytes(32)),
            'agent_secret_encrypted' => Str::password(64, symbols: false),
        ]);
    }
}
