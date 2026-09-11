<?php

namespace App\Services\Themes;

use App\Enums\ThemeGitAccountType;
use App\Enums\ThemeGitConnectionKind;
use App\Enums\ThemeGitConnectionStatus;
use App\Enums\ThemeGitSelectionMode;
use App\Models\GithubSetting;
use App\Models\ThemeGitConnection;
use App\Models\ThemeGitConnectionRepo;
use App\Services\GitHub\GitHubApiException;
use App\Services\GitHub\GitHubAppClient;
use App\Services\GitHub\GitHubCredentialsException;
use App\Support\PublicAppUrl;
use App\Support\ThemeGitOAuthState;
use Illuminate\Http\Request;
use RuntimeException;

class ThemeGitConnectionService
{
    public function githubWebBase(): string
    {
        return rtrim((string) config('ops.github.web_base', 'https://github.com'), '/');
    }

    public function appName(): string
    {
        $name = trim((string) config('ops.github.app_name', 'Deamon Plane Themes'));

        return $name !== '' ? $name : 'Deamon Plane Themes';
    }

    /**
     * @return array<string, mixed>
     */
    public function manifestPayload(): array
    {
        $url = rtrim((string) config('app.url'), '/');

        return [
            'name' => $this->appName(),
            'url' => $url,
            'hook_attributes' => [
                'url' => url('/webhooks/github'),
                'active' => true,
            ],
            'redirect_url' => route('ops.themes.git.callback'),
            'callback_urls' => [route('ops.themes.git.callback')],
            'setup_url' => route('ops.themes.git.installed'),
            // Private + user-owned App can only install on that personal account (GitHub rule).
            // Public is required for the same App to install on orgs (ChatGPT/Claude model).
            'public' => true,
            'default_permissions' => [
                'metadata' => 'read',
                'contents' => 'read',
            ],
            'default_events' => ['push', 'release'],
        ];
    }

    public function assertPublicAppUrl(): void
    {
        if (! PublicAppUrl::isPublic()) {
            throw new GitHubCredentialsException(__('themes.git.errors.public_url'));
        }
    }

    public function beginManifest(): string
    {
        $this->assertPublicAppUrl();

        return ThemeGitOAuthState::put('manifest');
    }

    public function convertManifest(Request $request): GithubSetting
    {
        try {
            ThemeGitOAuthState::assert($request, 'manifest');
        } catch (RuntimeException) {
            throw new GitHubCredentialsException(__('themes.git.errors.state'));
        }

        $code = trim((string) $request->query('code', ''));
        if ($code === '') {
            throw new GitHubCredentialsException(__('themes.git.errors.manifest_code'));
        }

        $payload = GitHubAppClient::fromSettings()->convertAppManifest($code);

        $settings = GithubSetting::current();
        $settings->app_id = $this->stringOrNull($payload['id'] ?? null);
        $settings->slug = $this->stringOrNull($payload['slug'] ?? null);
        $settings->client_id = $this->stringOrNull($payload['client_id'] ?? null);
        $settings->client_secret = $this->stringOrNull($payload['client_secret'] ?? null);
        $settings->webhook_secret = $this->stringOrNull($payload['webhook_secret'] ?? null);

        $pem = $this->stringOrNull($payload['pem'] ?? null);
        if ($pem !== null) {
            $settings->private_key = str_replace("\r\n", "\n", $pem);
        }

        $settings->save();

        return $settings;
    }

    public function installUrl(GithubSetting $settings, string $state): string
    {
        $slug = trim((string) $settings->slug);
        if ($slug === '') {
            throw new GitHubCredentialsException(__('themes.git.errors.app_missing'));
        }

        // /installations/new often skips the account picker and jumps to the already-installed
        // personal target_id. /select_target forces the personal-vs-org chooser (ChatGPT/Claude/PicX).
        return $this->githubWebBase().'/apps/'.$slug.'/installations/select_target?state='.rawurlencode($state);
    }

    public function beginInstall(): string
    {
        $this->assertPublicAppUrl();

        $settings = GithubSetting::current();
        if (! $settings->hasManifestApp()) {
            throw new GitHubCredentialsException(__('themes.git.errors.app_missing'));
        }

        return ThemeGitOAuthState::put('install');
    }

    public function recordInstallation(Request $request): ThemeGitConnection
    {
        try {
            ThemeGitOAuthState::assert($request, 'install', allowMissingQuery: true);
        } catch (RuntimeException) {
            throw new GitHubCredentialsException(__('themes.git.errors.state'));
        }

        $installationId = trim((string) $request->query('installation_id', ''));
        if ($installationId === '') {
            throw new GitHubCredentialsException(__('themes.git.errors.installation'));
        }

        $payload = GitHubAppClient::fromSettings()->getInstallation($installationId);
        $account = is_array($payload['account'] ?? null) ? $payload['account'] : [];
        $login = $this->stringOrNull($account['login'] ?? null);
        if ($login === null) {
            throw new GitHubApiException('GitHub installation omitted account login.');
        }

        $accountType = ThemeGitAccountType::fromGitHub((string) ($account['type'] ?? 'User'));

        $connection = ThemeGitConnection::query()->where('installation_id', $installationId)->first()
            ?? new ThemeGitConnection;

        $connection->fill([
            'name' => $connection->name ?: $login,
            'account_login' => $login,
            'account_type' => $accountType,
            'installation_id' => $installationId,
            'selection_mode' => $connection->selection_mode ?: ThemeGitSelectionMode::All,
            'status' => ThemeGitConnectionStatus::Connected,
            'last_error' => null,
            'kind' => ThemeGitConnectionKind::GithubApp,
            'token' => null,
        ]);
        $connection->save();

        return $connection;
    }

    /**
     * @param  array{name?: string|null, account_login: string, account_type: string, token: string, repo_name_prefix?: string|null, selection_mode?: string|null}  $input
     */
    public function connectPat(array $input): ThemeGitConnection
    {
        $login = trim($input['account_login']);
        $mode = ThemeGitSelectionMode::tryFrom((string) ($input['selection_mode'] ?? ''))
            ?? ThemeGitSelectionMode::All;

        $connection = new ThemeGitConnection;
        $connection->fill([
            'name' => $this->stringOrNull($input['name'] ?? null) ?? $login,
            'account_login' => $login,
            'account_type' => ThemeGitAccountType::from($input['account_type']),
            'installation_id' => null,
            'selection_mode' => $mode,
            'repo_name_prefix' => $this->stringOrNull($input['repo_name_prefix'] ?? null),
            'status' => ThemeGitConnectionStatus::Connected,
            'last_error' => null,
            'kind' => ThemeGitConnectionKind::Pat,
            'token' => $input['token'],
        ]);
        $connection->save();

        return $connection;
    }

    /**
     * @param  array{name?: string|null, selection_mode: string, repo_name_prefix?: string|null, included?: list<string>}  $input
     */
    public function updateConnection(ThemeGitConnection $connection, array $input): ThemeGitConnection
    {
        $mode = ThemeGitSelectionMode::from($input['selection_mode']);
        $connection->name = $this->stringOrNull($input['name'] ?? null) ?? $connection->name;
        $connection->selection_mode = $mode;
        $connection->repo_name_prefix = $this->stringOrNull($input['repo_name_prefix'] ?? null);
        $connection->save();

        if ($mode === ThemeGitSelectionMode::Selected) {
            $included = array_values(array_filter(
                array_map(static fn (mixed $value): string => trim((string) $value), $input['included'] ?? []),
                static fn (string $value): bool => $value !== '',
            ));

            $connection->repos()->update(['included' => false]);
            if ($included !== []) {
                $connection->repos()->whereIn('repo_full_name', $included)->update(['included' => true]);
            }
        }

        return $connection->fresh() ?? $connection;
    }

    public function syncRepos(ThemeGitConnection $connection): int
    {
        $client = GitHubAppClient::fromConnection($connection);

        try {
            $repos = $client->listAccessibleRepos($connection);
        } catch (GitHubCredentialsException|GitHubApiException $exception) {
            $connection->markError($exception->getMessage());

            throw $exception;
        }

        $seen = [];
        $count = 0;

        foreach ($repos as $repo) {
            $row = ThemeGitConnectionRepo::query()->firstOrNew([
                'theme_git_connection_id' => $connection->id,
                'repo_full_name' => $repo['full_name'],
            ]);
            $isNew = ! $row->exists;
            $row->github_repo_id = $repo['id'];
            $row->default_branch = $repo['default_branch'] !== '' ? $repo['default_branch'] : 'main';
            $row->is_private = $repo['private'];
            $row->html_url = $repo['html_url'] !== '' ? $repo['html_url'] : null;
            $row->last_seen_at = now();
            if ($isNew) {
                $row->included = $connection->selection_mode === ThemeGitSelectionMode::All;
            }
            $row->save();
            $seen[] = $repo['full_name'];
            $count++;
        }

        if ($seen !== []) {
            $connection->repos()->whereNotIn('repo_full_name', $seen)->delete();
        } else {
            $connection->repos()->delete();
        }

        $connection->markConnected();

        return $count;
    }

    public function disconnect(ThemeGitConnection $connection): void
    {
        $connection->delete();
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (is_int($value) || is_float($value)) {
            $value = (string) $value;
        }

        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
