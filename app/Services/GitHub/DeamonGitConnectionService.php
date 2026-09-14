<?php

namespace App\Services\GitHub;

use App\Models\GithubSetting;
use App\Services\Themes\ThemeGitConnectionService;
use App\Support\ThemeGitOAuthState;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Settings → Deamon Git: CMS repo credentials on github_settings (installation / PAT).
 * Reuses the Plane GitHub App; never writes theme_git_connections.
 */
class DeamonGitConnectionService
{
    public function __construct(
        private readonly ThemeGitConnectionService $themes = new ThemeGitConnectionService,
    ) {}

    public function githubWebBase(): string
    {
        return $this->themes->githubWebBase();
    }

    public function beginManifest(): string
    {
        $this->themes->assertPublicAppUrl();

        return ThemeGitOAuthState::put('deamon_manifest');
    }

    public function convertManifest(Request $request): GithubSetting
    {
        try {
            ThemeGitOAuthState::assert($request, 'deamon_manifest');
        } catch (RuntimeException) {
            throw new GitHubCredentialsException(__('settings.deamon_git.errors.state'));
        }

        $code = trim((string) $request->query('code', ''));
        if ($code === '') {
            throw new GitHubCredentialsException(__('settings.deamon_git.errors.manifest_code'));
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

    /**
     * Manifest JSON for first-time App creation from Settings.
     * setup_url stays the Themes installed route so one App has one install callback;
     * session purpose routes the result to Deamon Git.
     *
     * @return array<string, mixed>
     */
    public function manifestPayload(): array
    {
        $payload = $this->themes->manifestPayload();
        $payload['name'] = $this->appName();
        $payload['redirect_url'] = route('ops.settings.deamon-git.callback');
        $payload['callback_urls'] = [route('ops.settings.deamon-git.callback')];
        $payload['setup_url'] = route('ops.themes.git.installed');

        return $payload;
    }

    public function appName(): string
    {
        $name = trim((string) config('ops.github.app_name', 'Deamon Plane'));

        return $name !== '' ? $name : 'Deamon Plane';
    }

    public function beginInstall(): string
    {
        $this->themes->assertPublicAppUrl();

        $settings = GithubSetting::current();
        if (! $settings->hasManifestApp()) {
            throw new GitHubCredentialsException(__('settings.deamon_git.errors.app_missing'));
        }

        return ThemeGitOAuthState::put('deamon_install');
    }

    public function installUrl(GithubSetting $settings, string $state): string
    {
        return $this->themes->installUrl($settings, $state);
    }

    public function recordInstallation(Request $request): GithubSetting
    {
        try {
            ThemeGitOAuthState::assert($request, 'deamon_install', allowMissingQuery: true);
        } catch (RuntimeException) {
            throw new GitHubCredentialsException(__('settings.deamon_git.errors.state'));
        }

        $installationId = trim((string) $request->query('installation_id', ''));
        if ($installationId === '') {
            throw new GitHubCredentialsException(__('settings.deamon_git.errors.installation'));
        }

        $payload = GitHubAppClient::fromSettings()->getInstallation($installationId);
        $account = is_array($payload['account'] ?? null) ? $payload['account'] : [];
        $login = $this->stringOrNull($account['login'] ?? null);
        if ($login === null) {
            throw new GitHubApiException('GitHub installation omitted account login.');
        }

        $settings = GithubSetting::current();
        if (! $settings->exists) {
            $settings->save();
            $settings = GithubSetting::current();
        }

        $settings->installation_id = $installationId;
        $settings->cms_account_login = $login;
        $settings->save();

        return $settings->fresh() ?? $settings;
    }

    public function disconnect(): void
    {
        $settings = GithubSetting::current();
        if (! $settings->exists) {
            return;
        }

        $settings->installation_id = null;
        $settings->cms_account_login = null;
        $settings->save();
    }

    /**
     * @param  array{token?: string|null}  $input
     */
    public function savePat(array $input): GithubSetting
    {
        $settings = GithubSetting::current();
        if (! $settings->exists) {
            $settings->save();
            $settings = GithubSetting::current();
        }

        $token = trim((string) ($input['token'] ?? ''));
        if ($token !== '') {
            $settings->token = $token;
            $settings->save();
        }

        return $settings->fresh() ?? $settings;
    }

    public function clearPat(): void
    {
        $settings = GithubSetting::current();
        if (! $settings->exists) {
            return;
        }

        $settings->token = null;
        $settings->save();
    }

    public static function pendingInstallPurpose(): ?string
    {
        $stored = session()->get(ThemeGitOAuthState::SESSION_KEY);
        if (! is_array($stored)) {
            return null;
        }

        $purpose = $stored['purpose'] ?? null;

        return is_string($purpose) ? $purpose : null;
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
