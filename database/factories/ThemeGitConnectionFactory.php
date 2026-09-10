<?php

namespace Database\Factories;

use App\Enums\ThemeGitAccountType;
use App\Enums\ThemeGitConnectionKind;
use App\Enums\ThemeGitConnectionStatus;
use App\Enums\ThemeGitSelectionMode;
use App\Models\ThemeGitConnection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ThemeGitConnection>
 */
class ThemeGitConnectionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $login = fake()->unique()->userName();

        return [
            'name' => $login,
            'account_login' => $login,
            'account_type' => ThemeGitAccountType::Organization,
            'installation_id' => (string) fake()->unique()->numerify('########'),
            'selection_mode' => ThemeGitSelectionMode::All,
            'repo_name_prefix' => null,
            'status' => ThemeGitConnectionStatus::Connected,
            'last_error' => null,
            'kind' => ThemeGitConnectionKind::GithubApp,
            'token' => null,
        ];
    }

    public function githubApp(?string $installationId = null): static
    {
        return $this->state(fn (array $attributes) => [
            'kind' => ThemeGitConnectionKind::GithubApp,
            'installation_id' => $installationId ?? ($attributes['installation_id'] ?? '1001'),
            'token' => null,
        ]);
    }

    public function pat(string $token = 'github-pat-test-token'): static
    {
        return $this->state(fn (array $attributes) => [
            'kind' => ThemeGitConnectionKind::Pat,
            'installation_id' => null,
            'token' => $token,
        ]);
    }

    public function connected(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ThemeGitConnectionStatus::Connected,
            'last_error' => null,
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ThemeGitConnectionStatus::Pending,
        ]);
    }

    public function selected(): static
    {
        return $this->state(fn (array $attributes) => [
            'selection_mode' => ThemeGitSelectionMode::Selected,
        ]);
    }

    public function organization(string $login): static
    {
        return $this->state(fn (array $attributes) => [
            'account_login' => $login,
            'account_type' => ThemeGitAccountType::Organization,
            'name' => $login,
        ]);
    }
}
