<?php

namespace App\Services\Coolify;

use App\Services\Coolify\Dto\CoolifyApplication;
use App\Services\Coolify\Dto\CoolifyDeployment;
use App\Services\Coolify\Dto\CoolifyDeployResult;
use App\Services\Coolify\Dto\CoolifyEnvironmentVariable;
use App\Services\Coolify\Dto\CoolifyGitSource;
use App\Services\Coolify\Dto\CoolifyProject;
use App\Services\Coolify\Dto\CoolifyProjectEnvironment;
use App\Services\Coolify\Dto\CoolifyServer;
use App\Services\Coolify\Dto\CoolifyStorages;
use App\Services\Coolify\Dto\CreateComposeAppRequest;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

class CoolifyClient
{
    public function __construct(
        private readonly ?CoolifyCredentials $credentials = null,
    ) {}

    public function credentials(): CoolifyCredentials
    {
        return $this->credentials ?? CoolifyCredentials::resolve();
    }

    /**
     * @return Collection<int, CoolifyApplication>
     */
    public function listApps(?string $tag = null): Collection
    {
        $query = [];
        if (filled($tag)) {
            $query['tag'] = $tag;
        }

        $json = $this->request('GET', '/applications', $query);

        return $this->mapList($json, CoolifyApplication::fromArray(...));
    }

    public function getApp(string $uuid): CoolifyApplication
    {
        $json = $this->request('GET', '/applications/'.$this->assertUuid($uuid));

        return CoolifyApplication::fromArray($this->unwrapResource($json));
    }

    /**
     * @return Collection<int, CoolifyServer>
     */
    public function listServers(): Collection
    {
        return $this->mapList($this->request('GET', '/servers'), CoolifyServer::fromArray(...));
    }

    /**
     * @return Collection<int, CoolifyProject>
     */
    public function listProjects(): Collection
    {
        return $this->mapList($this->request('GET', '/projects'), CoolifyProject::fromArray(...));
    }

    /**
     * GET /projects/{uuid}/environments. Falls back to nested environments on GET /projects/{uuid}.
     *
     * @return Collection<int, CoolifyProjectEnvironment>
     */
    public function listEnvironments(string $projectUuid): Collection
    {
        $projectUuid = $this->assertUuid($projectUuid);

        try {
            $json = $this->request('GET', '/projects/'.$projectUuid.'/environments');
        } catch (CoolifyApiException $exception) {
            if ($exception->status !== 404) {
                throw $exception;
            }

            $json = $this->request('GET', '/projects/'.$projectUuid);
            if (is_array($json) && isset($json['environments']) && is_array($json['environments'])) {
                $json = $json['environments'];
            }
        }

        return $this->mapList($json, static fn (array $row): CoolifyProjectEnvironment => CoolifyProjectEnvironment::fromArray($row, $projectUuid));
    }

    /**
     * GET /github-apps. Returns null when the instance has no such route (hybrid).
     *
     * @return Collection<int, CoolifyGitSource>|null
     */
    public function listGithubApps(): ?Collection
    {
        try {
            $json = $this->request('GET', '/github-apps');
        } catch (CoolifyApiException $exception) {
            if (in_array($exception->status, [404, 405], true)) {
                return null;
            }

            throw $exception;
        }

        return $this->mapList($json, CoolifyGitSource::fromGithubApp(...));
    }

    /**
     * GET /security/keys — Coolify deploy / private keys.
     *
     * @return Collection<int, CoolifyGitSource>
     */
    public function listPrivateKeys(): Collection
    {
        return $this->mapList($this->request('GET', '/security/keys'), CoolifyGitSource::fromPrivateKey(...));
    }

    /**
     * Git + build_pack=dockercompose. Never POST /applications/dockercompose.
     */
    public function createComposeApp(CreateComposeAppRequest $request): CoolifyApplication
    {
        $json = $this->request('POST', $request->endpoint(), [], $request->toPayload());

        return CoolifyApplication::fromArray($this->unwrapResource($json));
    }

    /**
     * @param  array<string, string>|list<array{key: string, value: string}>  $pairs
     * @return Collection<int, CoolifyEnvironmentVariable>
     */
    public function updateEnvs(string $uuid, array $pairs): Collection
    {
        $json = $this->request(
            'PATCH',
            '/applications/'.$this->assertUuid($uuid).'/envs/bulk',
            [],
            ['data' => $this->normalizeEnvPairs($pairs)],
        );

        if (! is_array($json)) {
            return collect();
        }

        return $this->mapList($json, CoolifyEnvironmentVariable::fromArray(...));
    }

    /**
     * @return Collection<int, CoolifyEnvironmentVariable>
     */
    public function listEnvs(string $uuid): Collection
    {
        return $this->mapList(
            $this->request('GET', '/applications/'.$this->assertUuid($uuid).'/envs'),
            CoolifyEnvironmentVariable::fromArray(...),
        );
    }

    /**
     * PATCH existing Coolify application. Never sends `fqdn`. Never DELETE.
     *
     * @param  array<string, mixed>  $body
     */
    public function patchApplication(string $uuid, array $body): CoolifyApplication
    {
        unset($body['fqdn'], $body['delete_volumes']);

        if (array_key_exists('is_auto_deploy', $body)) {
            $body['is_auto_deploy_enabled'] = (bool) $body['is_auto_deploy'];
            unset($body['is_auto_deploy']);
        }

        if (array_key_exists('git_commit_sha', $body)) {
            $sha = trim((string) ($body['git_commit_sha'] ?? ''));
            $body['git_commit_sha'] = $sha === '' ? CoolifyApplication::HEAD_REF : $sha;
        }

        if ($body === []) {
            throw new InvalidArgumentException('Coolify application PATCH body is empty.');
        }

        $json = $this->request('PATCH', '/applications/'.$this->assertUuid($uuid), [], $body);

        return CoolifyApplication::fromArray($this->unwrapResource($json));
    }

    /**
     * Write one application env (compose services inherit these). Coolify 4.3
     * `/envs` accepts only key/value/is_literal/is_preview/is_multiline/is_shown_once.
     * Does not log values.
     */
    public function upsertEnvOnService(string $uuid, string $key, string $value, string $service = CoolifyDomainParser::COMPOSE_SERVICE): CoolifyEnvironmentVariable
    {
        $key = trim($key);
        if ($key === '') {
            throw new InvalidArgumentException('Environment variable key is required.');
        }

        // Coolify 4.3 has no per-service env field; $service is call-site intent only.
        unset($service);

        $existing = $this->listEnvs($uuid)->first(
            static fn (CoolifyEnvironmentVariable $env): bool => $env->key === $key,
        );

        $payload = [
            'key' => $key,
            'value' => $value,
            'is_literal' => true,
        ];

        if ($existing instanceof CoolifyEnvironmentVariable && filled($existing->uuid)) {
            $json = $this->request('PATCH', '/applications/'.$this->assertUuid($uuid).'/envs', [], $payload);
        } else {
            $json = $this->request('POST', '/applications/'.$this->assertUuid($uuid).'/envs', [], $payload);
        }

        if (! is_array($json)) {
            return CoolifyEnvironmentVariable::fromArray(['key' => $key]);
        }

        return CoolifyEnvironmentVariable::fromArray($this->unwrapResource($json));
    }

    /**
     * @param  string|array<int|string, mixed>  $fqdn
     */
    public function setDomains(string $uuid, string|array $fqdn, bool $forceDomainOverride = false): CoolifyApplication
    {
        $composeDomains = CoolifyDomainParser::forPatch($fqdn);
        $body = [
            'docker_compose_domains' => $composeDomains,
            'force_domain_override' => $forceDomainOverride,
        ];

        $json = $this->request('PATCH', '/applications/'.$this->assertUuid($uuid), [], $body);

        return CoolifyApplication::fromArray($this->unwrapResource($json));
    }

    public function updateBranch(string $uuid, string $branch): CoolifyApplication
    {
        $json = $this->request(
            'PATCH',
            '/applications/'.$this->assertUuid($uuid),
            [],
            ['git_branch' => $branch],
        );

        return CoolifyApplication::fromArray($this->unwrapResource($json));
    }

    public function startApplication(string $uuid): void
    {
        $this->request('POST', '/applications/'.$this->assertUuid($uuid).'/start');
    }

    public function stopApplication(string $uuid): void
    {
        $this->request('POST', '/applications/'.$this->assertUuid($uuid).'/stop');
    }

    /**
     * Hard-delete only. Channel switch, retry, and pack migrate must never call this.
     * Coolify defaults delete_volumes to true — always send the query explicitly.
     */
    public function deleteApplication(string $uuid, bool $deleteVolumes): void
    {
        $this->request('DELETE', '/applications/'.$this->assertUuid($uuid), [
            'delete_volumes' => $deleteVolumes ? 'true' : 'false',
        ]);
    }

    public function deploy(string $uuid, bool $force = false): CoolifyDeployResult
    {
        $query = ['uuid' => $this->assertUuid($uuid)];
        if ($force) {
            $query['force'] = 'true';
        }

        $json = $this->request('POST', '/deploy', $query);

        return CoolifyDeployResult::fromArray(is_array($json) ? $json : []);
    }

    public function getDeployment(string $deploymentUuid): CoolifyDeployment
    {
        $json = $this->request('GET', '/deployments/'.$this->assertUuid($deploymentUuid));

        return CoolifyDeployment::fromArray($this->unwrapResource($json));
    }

    /**
     * @return Collection<int, CoolifyDeployment>
     */
    public function listAppDeployments(string $appUuid, ?int $skip = null, ?int $take = null): Collection
    {
        $query = [];
        if ($skip !== null) {
            $query['skip'] = $skip;
        }
        if ($take !== null) {
            $query['take'] = $take;
        }

        $json = $this->request('GET', '/deployments/applications/'.$this->assertUuid($appUuid), $query);

        return $this->mapList($json, CoolifyDeployment::fromArray(...));
    }

    /**
     * @return Collection<int, CoolifyDeployment>
     */
    public function listRunningDeployments(): Collection
    {
        return $this->mapList($this->request('GET', '/deployments'), CoolifyDeployment::fromArray(...));
    }

    public function listStorages(string $uuid): CoolifyStorages
    {
        $json = $this->request('GET', '/applications/'.$this->assertUuid($uuid).'/storages');

        return CoolifyStorages::fromArray(is_array($json) ? $json : []);
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>|null  $body
     */
    private function request(string $method, string $path, array $query = [], ?array $body = null): mixed
    {
        $credentials = $this->credentials();

        if ($credentials->baseUrl === '') {
            throw new CoolifyApiException('Coolify base URL is not configured.', 400);
        }

        if (! $credentials->hasToken()) {
            throw new CoolifyApiException('Coolify API token is not configured.', 401);
        }

        $pending = $this->http($credentials);

        $response = match (strtoupper($method)) {
            'GET' => $pending->get($path, $query),
            'POST' => $query === []
                ? $pending->post($path, $body ?? [])
                : $pending->withQueryParameters($query)->post($path, $body ?? []),
            'PATCH' => $pending->patch($path, $body ?? []),
            'DELETE' => $query === []
                ? $pending->delete($path)
                : $pending->withQueryParameters($query)->delete($path),
            default => throw new InvalidArgumentException("Unsupported Coolify HTTP method [{$method}]."),
        };

        return $this->decode($response, $credentials->token());
    }

    private function http(CoolifyCredentials $credentials): PendingRequest
    {
        return Http::withToken($credentials->token())
            ->baseUrl($credentials->apiRoot())
            ->acceptJson()
            ->asJson()
            ->timeout((int) config('ops.coolify.timeout', 30));
    }

    private function decode(Response $response, string $token): mixed
    {
        if ($response->failed()) {
            throw CoolifyApiException::fromResponse($response, $token);
        }

        if ($response->body() === '') {
            return null;
        }

        return $response->json();
    }

    /**
     * @template T
     *
     * @param  callable(array<string, mixed>): T  $mapper
     * @return Collection<int, T>
     */
    private function mapList(mixed $json, callable $mapper): Collection
    {
        $rows = $this->decodeList($json);

        return collect($rows)
            ->filter(static fn (mixed $row): bool => is_array($row))
            ->values()
            ->map(static fn (array $row) => $mapper($row));
    }

    /**
     * @return list<mixed>
     */
    private function decodeList(mixed $json): array
    {
        if (! is_array($json)) {
            return [];
        }

        if (array_is_list($json)) {
            return $json;
        }

        foreach (['data', 'deployments', 'github_apps', 'sources', 'keys', 'servers', 'projects', 'environments'] as $key) {
            if (isset($json[$key]) && is_array($json[$key])) {
                return array_is_list($json[$key]) ? $json[$key] : array_values($json[$key]);
            }
        }

        if (isset($json['uuid']) || isset($json['id'])) {
            return [$json];
        }

        return [];
    }

    /**
     * @return array<string, mixed>
     */
    private function unwrapResource(mixed $json): array
    {
        if (! is_array($json)) {
            throw new CoolifyApiException('Coolify API returned an unexpected payload.', 502);
        }

        if (isset($json['uuid']) || isset($json['deployment_uuid'])) {
            return $json;
        }

        if (isset($json['data']) && is_array($json['data'])) {
            return $json['data'];
        }

        return $json;
    }

    /**
     * @param  array<string, string>|list<array{key: string, value: string}>  $pairs
     * @return list<array{key: string, value: string}>
     */
    private function normalizeEnvPairs(array $pairs): array
    {
        if ($pairs === []) {
            return [];
        }

        if (array_is_list($pairs)) {
            $out = [];
            foreach ($pairs as $pair) {
                if (! is_array($pair) || ! isset($pair['key'])) {
                    continue;
                }

                $out[] = [
                    'key' => (string) $pair['key'],
                    'value' => (string) ($pair['value'] ?? ''),
                ];
            }

            return $out;
        }

        $out = [];
        foreach ($pairs as $key => $value) {
            $out[] = [
                'key' => (string) $key,
                'value' => (string) $value,
            ];
        }

        return $out;
    }

    private function assertUuid(string $uuid): string
    {
        $uuid = trim($uuid);
        if ($uuid === '' || str_contains($uuid, '/') || str_contains($uuid, '..')) {
            throw new InvalidArgumentException('Coolify resource uuid is invalid.');
        }

        return $uuid;
    }
}
