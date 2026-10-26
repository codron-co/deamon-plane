<?php

namespace App\Mcp\Tools;

use App\Mcp\McpContext;
use App\Mcp\McpException;
use App\Mcp\McpModels;
use App\Mcp\McpPresenter;
use App\Mcp\McpTool;
use App\Mcp\OpsRouteInvoker;
use App\Models\CloudflareSetting;
use App\Models\Site;
use App\Services\Cloudflare\CloudflareApiException;
use App\Services\Cloudflare\CloudflareClient;
use Closure;

/**
 * A write tool backed by one panel route (see RouteToolCatalog). Arguments that
 * name a route parameter are resolved to models; the rest is the request body.
 */
final class RouteTool implements McpTool
{
    /**
     * Route parameter resolvers: which arguments each one reads.
     */
    private const CONSUMES = [
        'site' => ['site'],
        'site_trashed' => ['site'],
        'installation' => ['theme_id'],
        'site_domain' => ['domain'],
        'deployment' => ['deployment_id'],
        'theme' => ['theme_id'],
        'cloudflare_account' => ['account'],
        'zone' => ['zone'],
        'record' => ['record_id'],
        'admin' => ['admin_id'],
        'rollout' => ['rollout_id'],
        'comail_domain' => ['domain'],
        'mail_package' => ['package'],
    ];

    /**
     * @param  array<string, array<string, mixed>>  $properties  JSON Schema of each argument
     * @param  list<string>  $required
     * @param  array<string, string>  $params  route parameter => resolver (keys of CONSUMES)
     * @param  (Closure(array<string, mixed>, array<string, mixed>): array<string, mixed>)|null  $prepare  (body, resolved params) => body
     */
    public function __construct(
        private readonly string $name,
        private readonly string $description,
        private readonly string $route,
        private readonly string $ability,
        private readonly array $properties = [],
        private readonly array $required = [],
        private readonly array $params = [],
        private readonly ?Closure $prepare = null,
        // A route that creates a site has no site parameter; find it by this argument afterwards.
        private readonly ?string $siteFromArgument = null,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function ability(): string
    {
        return $this->ability;
    }

    public function route(): string
    {
        return $this->route;
    }

    public function inputSchema(): array
    {
        $schema = [
            'type' => 'object',
            'properties' => $this->properties === [] ? new \stdClass : $this->properties,
            'additionalProperties' => false,
        ];

        if ($this->required !== []) {
            $schema['required'] = $this->required;
        }

        return $schema;
    }

    public function handle(McpContext $context, array $arguments): array
    {
        foreach ($this->required as $key) {
            if (! array_key_exists($key, $arguments) || $arguments[$key] === null || $arguments[$key] === '') {
                throw new McpException("`{$key}` is required.", 'validation_error');
            }
        }

        $unknown = array_diff(array_keys($arguments), array_keys($this->properties));
        if ($unknown !== []) {
            throw new McpException('Unknown argument(s): '.implode(', ', $unknown).'. See the tool schema.', 'validation_error');
        }

        $resolved = [];
        $consumed = [];
        foreach ($this->params as $parameter => $resolver) {
            $resolved[$parameter] = $this->resolve($resolver, $arguments, $resolved);
            array_push($consumed, ...self::CONSUMES[$resolver]);
        }

        $body = array_diff_key($arguments, array_flip($consumed));
        if ($this->prepare !== null) {
            $body = ($this->prepare)($body, $resolved);
        }

        $result = app(OpsRouteInvoker::class)->invoke($this->route, $resolved, $body, $context->user, $context->ip);

        if (! $result['ok']) {
            throw new McpException(
                $result['message'] !== '' ? $result['message'] : 'Plane refused the request (HTTP '.$result['http_status'].').',
                $result['type'] === 'status' ? 'error' : $result['type'],
                array_filter(['errors' => $result['errors'] ?? null, 'http_status' => $result['http_status']]),
            );
        }

        $output = array_merge(
            ['ok' => true, 'type' => $result['type'], 'message' => $result['message']],
            $result['data'] ?? [],
            isset($result['flash']) ? ['once' => $result['flash']] : [],
        );

        if (isset($output['job']['id'])) {
            $output['next'] = 'Background job queued: poll list_jobs with job_id='.$output['job']['id'].' until completed or failed.';
        }

        if ($this->siteFromArgument !== null && filled($arguments[$this->siteFromArgument] ?? null)) {
            $created = Site::query()->where('slug', strtolower(trim((string) $arguments[$this->siteFromArgument])))->first();
            if ($created instanceof Site) {
                $output['site'] = McpPresenter::siteSummary($created);
            }
        }

        foreach ($resolved as $model) {
            if ($model instanceof Site) {
                $fresh = Site::withTrashed()->find($model->id);
                $output['site'] = $fresh instanceof Site ? McpPresenter::siteSummary($fresh) : ['id' => $model->id, 'slug' => $model->slug, 'deleted' => true];
                break;
            }
        }

        return $output;
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>  $resolved
     */
    private function resolve(string $resolver, array $arguments, array $resolved): mixed
    {
        $site = collect($resolved)->first(fn ($value): bool => $value instanceof Site);
        $needsSite = in_array($resolver, ['installation', 'site_domain', 'deployment', 'comail_domain'], true);
        if ($needsSite && ! $site instanceof Site) {
            throw new \LogicException("Resolver [{$resolver}] needs a site parameter first.");
        }

        return match ($resolver) {
            'site' => McpModels::site($arguments['site'] ?? ''),
            'site_trashed' => McpModels::site($arguments['site'] ?? '', withTrashed: true),
            'installation' => McpModels::installation($site, $arguments['theme_id'] ?? ''),
            'site_domain' => McpModels::siteDomain($site, $arguments['domain'] ?? ''),
            'deployment' => McpModels::deployment($site, $arguments['deployment_id'] ?? ''),
            'theme' => McpModels::theme($arguments['theme_id'] ?? ''),
            'cloudflare_account' => McpModels::cloudflareAccount($arguments['account'] ?? ''),
            'zone' => $this->zoneId((string) ($arguments['zone'] ?? ''), collect($resolved)->first(fn ($value): bool => $value instanceof CloudflareSetting)),
            'record' => trim((string) ($arguments['record_id'] ?? '')),
            'admin' => (int) ($arguments['admin_id'] ?? 0),
            'rollout' => McpModels::rollout($arguments['rollout_id'] ?? 0),
            'comail_domain' => McpModels::comailDomain($site, $arguments['domain'] ?? ''),
            'mail_package' => McpModels::mailPackage($arguments['package'] ?? ''),
            default => throw new \LogicException("Unknown resolver [{$resolver}]."),
        };
    }

    /**
     * Zone routes take the Cloudflare zone id; agents may pass the apex name.
     */
    private function zoneId(string $zone, ?CloudflareSetting $account): string
    {
        $zone = trim($zone);
        if (preg_match('/^[a-f0-9]{32}$/i', $zone) === 1 || ! $account instanceof CloudflareSetting) {
            return strtolower($zone);
        }

        try {
            $match = CloudflareClient::fromSettings($account)->listZones($account->account_id, McpModels::host($zone))[0] ?? null;
        } catch (CloudflareApiException $exception) {
            throw new McpException('Cloudflare: '.$exception->getMessage(), 'upstream_error');
        }

        if (! is_array($match) || blank($match['id'] ?? null)) {
            throw new McpException("No zone `{$zone}` on Cloudflare account {$account->name}.", 'not_found');
        }

        return strtolower((string) $match['id']);
    }
}
