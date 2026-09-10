<?php

namespace App\Services\GitHub;

use App\Enums\ThemeGitAccountType;
use App\Enums\ThemeGitConnectionKind;
use App\Models\GithubSetting;
use App\Models\ThemeGitConnection;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class GitHubAppClient
{
    public function __construct(
        private readonly ?GithubSetting $settings = null,
        private readonly ?ThemeGitConnection $connection = null,
    ) {}

    public static function fromSettings(?GithubSetting $settings = null): self
    {
        return new self($settings ?? GithubSetting::current());
    }

    public static function fromConnection(ThemeGitConnection $connection): self
    {
        return new self(GithubSetting::current(), $connection);
    }

    /**
     * @return array<string, mixed>
     */
    public function convertAppManifest(string $code): array
    {
        $response = $this->unauthenticatedHttp()
            ->post($this->apiUrl('/app-manifests/'.$code.'/conversions'));

        if ($response->failed()) {
            throw new GitHubApiException(
                'GitHub App manifest conversion failed with HTTP '.$response->status().'.',
                $response->status(),
            );
        }

        $json = $response->json();
        if (! is_array($json)) {
            throw new GitHubApiException('GitHub App manifest conversion returned a non-JSON body.', $response->status());
        }

        return $json;
    }

    /**
     * @return array<string, mixed>
     */
    public function getInstallation(string $installationId): array
    {
        return $this->getJson('/app/installations/'.$installationId, [], $this->appJwt());
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listAppInstallations(): array
    {
        $installations = [];
        $page = 1;

        do {
            $payload = $this->getJson('/app/installations', [
                'per_page' => 100,
                'page' => $page,
            ], $this->appJwt());

            if (! array_is_list($payload)) {
                throw new GitHubApiException('GitHub App installation list was not a JSON array.');
            }

            foreach ($payload as $row) {
                if (is_array($row)) {
                    $installations[] = $row;
                }
            }

            $count = count($payload);
            $page++;
        } while ($count === 100 && $page <= 20);

        return $installations;
    }

    /**
     * @return list<array{name: string, full_name: string, default_branch: string, private: bool, html_url: string, id: string|null}>
     */
    public function listInstallationRepos(string $installationId): array
    {
        $token = $this->installationAccessTokenFor($installationId);

        return $this->paginateRepos(function (int $page) use ($token): array {
            $payload = $this->getJson('/installation/repositories', [
                'per_page' => 100,
                'page' => $page,
            ], $token);

            $rows = $payload['repositories'] ?? null;

            return is_array($rows) ? $rows : [];
        });
    }

    /**
     * @return list<array{name: string, full_name: string, default_branch: string, private: bool, html_url: string, id: string|null}>
     */
    public function listPatRepos(ThemeGitConnection $connection): array
    {
        $login = $connection->account_login;
        $accountType = $connection->account_type instanceof ThemeGitAccountType
            ? $connection->account_type
            : ThemeGitAccountType::fromGitHub((string) $connection->account_type);

        return $this->paginateRepos(function (int $page) use ($login, $accountType): array {
            if ($accountType === ThemeGitAccountType::Organization) {
                return $this->getJson('/orgs/'.$login.'/repos', [
                    'per_page' => 100,
                    'page' => $page,
                    'type' => 'all',
                    'sort' => 'full_name',
                ]);
            }

            return $this->getJson('/user/repos', [
                'per_page' => 100,
                'page' => $page,
                'affiliation' => 'owner,collaborator,organization_member',
                'sort' => 'full_name',
            ]);
        }, $login);
    }

    /**
     * @return list<array{name: string, full_name: string, default_branch: string, private: bool, html_url: string, id: string|null}>
     */
    public function listAccessibleRepos(?ThemeGitConnection $connection = null): array
    {
        $connection ??= $this->connection;
        if ($connection === null) {
            throw new GitHubCredentialsException(
                'GitHub theme connections are not configured. Connect GitHub under Themes.',
            );
        }

        if ($connection->isGithubApp()) {
            $installationId = trim((string) $connection->installation_id);
            if ($installationId === '') {
                throw new GitHubCredentialsException('This GitHub App connection has no installation.');
            }

            return $this->listInstallationRepos($installationId);
        }

        return $this->listPatRepos($connection);
    }

    /**
     * @return list<array{name: string, full_name: string, default_branch: string, private: bool, html_url: string}>
     */
    public function listThemeRepos(): array
    {
        if ($this->connection !== null) {
            return array_map(static fn (array $repo): array => [
                'name' => $repo['name'],
                'full_name' => $repo['full_name'],
                'default_branch' => $repo['default_branch'],
                'private' => $repo['private'],
                'html_url' => $repo['html_url'],
            ], $this->listAccessibleRepos($this->connection));
        }

        throw new GitHubCredentialsException(
            'GitHub theme connections are not configured. Connect GitHub under Themes.',
        );
    }

    public function fetchThemeManifest(string $repoFullName, string $ref = 'main'): ?ThemeManifest
    {
        $paths = config('ops.themes.manifest_paths', ['theme.json', 'theme/theme.json']);
        if (! is_array($paths) || $paths === []) {
            $paths = ['theme.json', 'theme/theme.json'];
        }

        $fallbackId = $this->themeIdFromRepo($repoFullName, $this->connection?->normalizedPrefix());

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
     * Short-lived installation token for a one-time CMS clone. Never a PAT. Never log the value.
     */
    public function mintCloneToken(?ThemeGitConnection $connection = null): ?string
    {
        $connection ??= $this->connection;
        if ($connection === null || ! $connection->isGithubApp()) {
            return null;
        }

        $installationId = trim((string) $connection->installation_id);
        if ($installationId === '') {
            return null;
        }

        return $this->installationAccessTokenFor($installationId);
    }

    public function installationAccessTokenFor(string $installationId): string
    {
        $jwt = $this->appJwt();
        $response = $this->http($jwt)
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

    public function themeIdFromRepo(string $repoFullName, ?string $prefix = null): string
    {
        $name = str_contains($repoFullName, '/')
            ? (string) substr($repoFullName, strrpos($repoFullName, '/') + 1)
            : $repoFullName;
        $prefix = trim((string) $prefix);

        if ($prefix !== '' && str_starts_with($name, $prefix)) {
            return substr($name, strlen($prefix));
        }

        return $name;
    }

    /**
     * @param  callable(int): mixed  $pageFetcher
     * @return list<array{name: string, full_name: string, default_branch: string, private: bool, html_url: string, id: string|null}>
     */
    private function paginateRepos(callable $pageFetcher, ?string $ownerLogin = null): array
    {
        $repos = [];
        $page = 1;

        do {
            $payload = $pageFetcher($page);
            if (! is_array($payload)) {
                throw new GitHubApiException('GitHub repo list was not a JSON array.');
            }

            foreach ($payload as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $mapped = $this->mapRepo($row, $ownerLogin);
                if ($mapped !== null) {
                    $repos[] = $mapped;
                }
            }

            $count = count($payload);
            $page++;
        } while ($count === 100 && $page <= 20);

        return $repos;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{name: string, full_name: string, default_branch: string, private: bool, html_url: string, id: string|null}|null
     */
    private function mapRepo(array $row, ?string $ownerLogin = null): ?array
    {
        $name = trim((string) ($row['name'] ?? ''));
        if ($name === '') {
            return null;
        }

        $fullName = trim((string) ($row['full_name'] ?? ''));
        if ($fullName === '') {
            $fullName = $ownerLogin !== null ? $ownerLogin.'/'.$name : $name;
        }

        if ($ownerLogin !== null && ! str_starts_with(strtolower($fullName), strtolower($ownerLogin).'/')) {
            return null;
        }

        $id = $row['id'] ?? null;

        return [
            'name' => $name,
            'full_name' => $fullName,
            'default_branch' => (string) ($row['default_branch'] ?? 'main'),
            'private' => (bool) ($row['private'] ?? true),
            'html_url' => (string) ($row['html_url'] ?? ''),
            'id' => $id !== null && $id !== '' ? (string) $id : null,
        ];
    }

    /**
     * @param  array<string, scalar>  $query
     * @return array<int|string, mixed>
     */
    private function getJson(string $path, array $query = [], ?string $token = null): array
    {
        $response = $this->http($token)->get($this->apiUrl($path), $query);

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

    private function http(?string $token = null): PendingRequest
    {
        $timeout = max(1, (int) config('ops.github.timeout', 20));

        return Http::timeout($timeout)
            ->accept('application/vnd.github+json')
            ->withHeaders([
                'X-GitHub-Api-Version' => '2022-11-28',
                'User-Agent' => 'deamon-plane',
                'Authorization' => 'Bearer '.($token ?? $this->accessToken()),
            ]);
    }

    private function unauthenticatedHttp(): PendingRequest
    {
        $timeout = max(1, (int) config('ops.github.timeout', 20));

        return Http::timeout($timeout)
            ->accept('application/vnd.github+json')
            ->withHeaders([
                'X-GitHub-Api-Version' => '2022-11-28',
                'User-Agent' => 'deamon-plane',
            ]);
    }

    private function accessToken(): string
    {
        if ($this->connection !== null) {
            if ($this->connection->kind === ThemeGitConnectionKind::Pat) {
                $token = trim((string) $this->connection->token);
                if ($token === '') {
                    throw new GitHubCredentialsException('This PAT connection has no token.');
                }

                return $token;
            }

            $installationId = trim((string) $this->connection->installation_id);
            if ($installationId === '') {
                throw new GitHubCredentialsException('This GitHub App connection has no installation.');
            }

            return $this->installationAccessTokenFor($installationId);
        }

        throw new GitHubCredentialsException(
            'GitHub theme connections are not configured. Connect GitHub under Themes.',
        );
    }

    private function appJwt(): string
    {
        $settings = $this->settings ?? GithubSetting::current();
        $appId = $this->resolvedAppId($settings);
        $privateKey = $this->resolvedPrivateKey($settings);

        if ($appId === null || $privateKey === null) {
            throw new GitHubCredentialsException(
                'GitHub App is not configured. Connect GitHub under Themes.',
            );
        }

        return GitHubAppJwt::encode($appId, $privateKey);
    }

    private function resolvedAppId(GithubSetting $settings): ?string
    {
        $value = trim((string) ($settings->app_id ?: config('ops.github.app_id')));

        return $value !== '' ? $value : null;
    }

    private function resolvedPrivateKey(GithubSetting $settings): ?string
    {
        $value = (string) ($settings->private_key ?: config('ops.github.private_key'));
        $value = str_replace('\\n', "\n", trim($value));

        return $value !== '' ? $value : null;
    }

    private function apiUrl(string $path): string
    {
        $base = rtrim((string) config('ops.github.api_base', 'https://api.github.com'), '/');

        return $base.'/'.ltrim($path, '/');
    }
}
