<?php

namespace App\Services\GitHub;

use App\Models\GithubSetting;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class GitHubAppClient
{
    public function __construct(
        private readonly ?GithubSetting $settings = null,
    ) {}

    public static function fromSettings(?GithubSetting $settings = null): self
    {
        return new self($settings ?? GithubSetting::current());
    }

    /**
     * @return list<array{name: string, full_name: string, default_branch: string, private: bool, html_url: string}>
     */
    public function listThemeRepos(): array
    {
        $org = $this->org();
        $prefix = (string) config('ops.themes.repo_prefix', 'deamon-theme-');
        $repos = [];
        $page = 1;

        do {
            $payload = $this->getJson('/orgs/'.$org.'/repos', [
                'per_page' => 100,
                'page' => $page,
                'type' => 'all',
                'sort' => 'full_name',
            ]);

            if (! is_array($payload)) {
                throw new GitHubApiException('GitHub org repo list was not a JSON array.');
            }

            foreach ($payload as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $name = (string) ($row['name'] ?? '');
                if ($name === '' || ! str_starts_with($name, $prefix)) {
                    continue;
                }

                $repos[] = [
                    'name' => $name,
                    'full_name' => (string) ($row['full_name'] ?? $org.'/'.$name),
                    'default_branch' => (string) ($row['default_branch'] ?? 'main'),
                    'private' => (bool) ($row['private'] ?? true),
                    'html_url' => (string) ($row['html_url'] ?? ''),
                ];
            }

            $count = count($payload);
            $page++;
        } while ($count === 100 && $page <= 20);

        return $repos;
    }

    public function fetchThemeManifest(string $repoFullName, string $ref = 'main'): ?ThemeManifest
    {
        $paths = config('ops.themes.manifest_paths', ['theme.json', 'theme/theme.json']);
        if (! is_array($paths) || $paths === []) {
            $paths = ['theme.json', 'theme/theme.json'];
        }

        $fallbackId = $this->themeIdFromRepo($repoFullName);

        foreach ($paths as $path) {
            if (! is_string($path) || $path === '') {
                continue;
            }

            $json = $this->fetchJsonFile($repoFullName, $path, $ref);
            if ($json === null) {
                continue;
            }

            return ThemeManifest::fromJson($json, $fallbackId, $path);
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function fetchJsonFile(string $repoFullName, string $path, string $ref): ?array
    {
        try {
            $payload = $this->getJson('/repos/'.$repoFullName.'/contents/'.ltrim($path, '/'), [
                'ref' => $ref,
            ]);
        } catch (GitHubApiException $exception) {
            if ($exception->status === 404) {
                return null;
            }

            throw $exception;
        }

        if (! is_array($payload)) {
            return null;
        }

        $encoding = (string) ($payload['encoding'] ?? '');
        $content = (string) ($payload['content'] ?? '');
        if ($encoding === 'base64') {
            $content = base64_decode(str_replace("\n", '', $content), true) ?: '';
        }

        if ($content === '') {
            return null;
        }

        $decoded = json_decode($content, true);

        return is_array($decoded) ? $decoded : null;
    }

    public function latestCommitSha(string $repoFullName, string $ref): ?string
    {
        $payload = $this->getJson('/repos/'.$repoFullName.'/commits', [
            'sha' => $ref,
            'per_page' => 1,
        ]);

        if (! is_array($payload) || $payload === []) {
            return null;
        }

        $first = $payload[0] ?? null;
        if (! is_array($first)) {
            return null;
        }

        $sha = trim((string) ($first['sha'] ?? ''));

        return $sha !== '' ? $sha : null;
    }

    /**
     * Short-lived installation token for a one-time CMS clone. Never log the value.
     */
    public function mintCloneToken(): ?string
    {
        $settings = $this->settings ?? GithubSetting::current();
        if (! $this->hasAppCredentials($settings)) {
            return null;
        }

        return $this->installationAccessToken($settings);
    }

    public function ping(): int
    {
        $org = $this->org();
        $payload = $this->getJson('/orgs/'.$org);

        return is_array($payload) ? 1 : 0;
    }

    public function themeIdFromRepo(string $repoFullName): string
    {
        $name = str_contains($repoFullName, '/')
            ? (string) substr($repoFullName, strrpos($repoFullName, '/') + 1)
            : $repoFullName;
        $prefix = (string) config('ops.themes.repo_prefix', 'deamon-theme-');

        if (str_starts_with($name, $prefix)) {
            return substr($name, strlen($prefix));
        }

        return $name;
    }

    /**
     * @param  array<string, scalar>  $query
     * @return array<int|string, mixed>
     */
    private function getJson(string $path, array $query = []): array
    {
        $response = $this->http()->get($this->apiUrl($path), $query);

        if ($response->failed()) {
            throw new GitHubApiException(
                'GitHub API '.$path.' returned HTTP '.$response->status().'.',
                $response->status(),
            );
        }

        $json = $response->json();
        if (! is_array($json)) {
            throw new GitHubApiException('GitHub API '.$path.' returned a non-JSON body.', $response->status());
        }

        return $json;
    }

    private function http(): PendingRequest
    {
        $timeout = max(1, (int) config('ops.github.timeout', 20));

        return Http::timeout($timeout)
            ->accept('application/vnd.github+json')
            ->withHeaders([
                'X-GitHub-Api-Version' => '2022-11-28',
                'User-Agent' => 'deamon-plane',
                'Authorization' => 'Bearer '.$this->accessToken(),
            ]);
    }

    private function accessToken(): string
    {
        $settings = $this->settings ?? GithubSetting::current();

        if ($this->hasAppCredentials($settings)) {
            return $this->installationAccessToken($settings);
        }

        $token = $this->resolvedPat($settings);
        if ($token !== null) {
            return $token;
        }

        throw new GitHubCredentialsException(
            'GitHub credentials are not configured. Add a PAT or GitHub App in Settings.',
        );
    }

    private function installationAccessToken(GithubSetting $settings): string
    {
        $appId = $this->resolvedAppId($settings);
        $installationId = $this->resolvedInstallationId($settings);
        $privateKey = $this->resolvedPrivateKey($settings);

        if ($appId === null || $installationId === null || $privateKey === null) {
            throw new GitHubCredentialsException('GitHub App id, installation id, and private key are required.');
        }

        $jwt = GitHubAppJwt::encode($appId, $privateKey);
        $timeout = max(1, (int) config('ops.github.timeout', 20));
        $response = Http::timeout($timeout)
            ->accept('application/vnd.github+json')
            ->withHeaders([
                'X-GitHub-Api-Version' => '2022-11-28',
                'User-Agent' => 'deamon-plane',
                'Authorization' => 'Bearer '.$jwt,
            ])
            ->post($this->apiUrl('/app/installations/'.$installationId.'/access_tokens'));

        if ($response->failed()) {
            throw new GitHubApiException(
                'GitHub App installation token request failed with HTTP '.$response->status().'.',
                $response->status(),
            );
        }

        $token = trim((string) $response->json('token'));
        if ($token === '') {
            throw new GitHubApiException('GitHub App installation token response omitted token.');
        }

        return $token;
    }

    private function resolvedPat(GithubSetting $settings): ?string
    {
        if ($settings->hasToken()) {
            return (string) $settings->token;
        }

        $fromEnv = trim((string) config('ops.github.token'));

        return $fromEnv !== '' ? $fromEnv : null;
    }

    private function resolvedAppId(GithubSetting $settings): ?string
    {
        $value = trim((string) ($settings->app_id ?: config('ops.github.app_id')));

        return $value !== '' ? $value : null;
    }

    private function resolvedInstallationId(GithubSetting $settings): ?string
    {
        $value = trim((string) ($settings->installation_id ?: config('ops.github.installation_id')));

        return $value !== '' ? $value : null;
    }

    private function resolvedPrivateKey(GithubSetting $settings): ?string
    {
        $value = (string) ($settings->private_key ?: config('ops.github.private_key'));
        $value = str_replace('\\n', "\n", trim($value));

        return $value !== '' ? $value : null;
    }

    private function hasAppCredentials(GithubSetting $settings): bool
    {
        return $this->resolvedAppId($settings) !== null
            && $this->resolvedInstallationId($settings) !== null
            && $this->resolvedPrivateKey($settings) !== null;
    }

    private function org(): string
    {
        return ($this->settings ?? GithubSetting::current())->resolvedOrg();
    }

    private function apiUrl(string $path): string
    {
        $base = rtrim((string) config('ops.github.api_base', 'https://api.github.com'), '/');

        return $base.'/'.ltrim($path, '/');
    }
}
