<?php

namespace Database\Factories;

use App\Models\ThemeGitConnection;
use App\Models\ThemeGitConnectionRepo;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ThemeGitConnectionRepo>
 */
class ThemeGitConnectionRepoFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = 'deamon-theme-'.fake()->unique()->slug(1);

        return [
            'theme_git_connection_id' => ThemeGitConnection::factory(),
            'repo_full_name' => 'deamon-themes/'.$name,
            'github_repo_id' => (string) fake()->unique()->numerify('########'),
            'default_branch' => 'main',
            'is_private' => true,
            'html_url' => 'https://github.com/deamon-themes/'.$name,
            'included' => true,
            'last_seen_at' => now(),
        ];
    }

    public function excluded(): static
    {
        return $this->state(fn (array $attributes) => [
            'included' => false,
        ]);
    }
}
