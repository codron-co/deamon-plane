<?php

namespace App\Mcp\Tools;

use App\Enums\CmsPublishStatus;
use App\Enums\DeployGate;
use App\Mcp\McpContext;
use App\Mcp\McpException;
use App\Mcp\McpModels;
use App\Mcp\McpTool;
use App\Mcp\OpsRouteInvoker;
use App\Models\McpToken;
use App\Models\Site;
use App\Services\Sites\SiteAppHealthFixer;

/**
 * One action on many sites through the panel's bulk routes (one background
 * job, Plane paces the Coolify calls). Sites are always sent as explicit ids,
 * so the agent sees exactly which sites it touched.
 */
class BulkSitesTool implements McpTool
{
    /**
     * action => [route, extra body fields]
     */
    private const ACTIONS = [
        'deploy' => ['ops.sites.bulk.deploy', []],
        'update_to_head' => ['ops.sites.bulk.update-head', []],
        'follow_head' => ['ops.sites.bulk.follow-head', []],
        'pin' => ['ops.sites.bulk.pin', ['ref']],
        'switch_channel' => ['ops.sites.bulk.channel', ['channel', 'confirmed', 'force']],
        'sync_from_coolify' => ['ops.sites.bulk.sync', []],
        'check_live' => ['ops.sites.live-sync', []],
        'set_publish_status' => ['ops.sites.bulk.publish-status', ['publish_status', 'confirmed']],
        'set_auto_deploy' => ['ops.sites.bulk.auto-deploy', ['enabled']],
        'set_deploy_gate' => ['ops.sites.bulk.deploy-gate', ['gate']],
        'fix_app_health' => ['ops.sites.bulk.app-health-fix', ['fix']],
        'inject_agent_secret' => ['ops.sites.bulk.agent-secret', []],
    ];

    public function name(): string
    {
        return 'bulk_sites';
    }

    public function description(): string
    {
        return 'Run one action on several sites as one background job: deploy, update_to_head (CMS update), follow_head, pin, switch_channel, sync_from_coolify, check_live, set_publish_status, set_auto_deploy, set_deploy_gate, fix_app_health, inject_agent_secret. Pass `sites`, or `all: true` with filters (needs a danger token). Do the `test` site alone first, and deploy in one batch rather than many single deploys (shared host CPU).';
    }

    public function ability(): string
    {
        return McpToken::ABILITY_WRITE;
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => ['type' => 'string', 'enum' => array_keys(self::ACTIONS)],
                'sites' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Site slugs, domains or ids.'],
                'all' => ['type' => 'boolean', 'description' => 'Every site matching the filters below.'],
                'filter_channel' => ['type' => 'string', 'enum' => config('ops.channels', [])],
                'filter_theme_id' => ['type' => 'string'],
                'filter_search' => ['type' => 'string'],
                'ref' => ['type' => 'string', 'description' => 'pin: commit SHA or tag.'],
                'channel' => ['type' => 'string', 'enum' => config('ops.channels', []), 'description' => 'switch_channel target.'],
                'publish_status' => ['type' => 'string', 'enum' => CmsPublishStatus::values()],
                'enabled' => ['type' => 'boolean', 'description' => 'set_auto_deploy.'],
                'gate' => ['type' => 'string', 'enum' => DeployGate::values()],
                'fix' => ['type' => 'string', 'enum' => array_merge(SiteAppHealthFixer::FIX_ORDER, ['all'])],
                'confirmed' => ['type' => 'boolean'],
                'force' => ['type' => 'boolean'],
            ],
            'required' => ['action'],
            'additionalProperties' => false,
        ];
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $action = (string) ($arguments['action'] ?? '');
        if (! isset(self::ACTIONS[$action])) {
            throw new McpException("Unknown action `{$action}`.", 'validation_error');
        }
        [$route, $fields] = self::ACTIONS[$action];

        $sites = $this->sites($context, $arguments);
        if ($sites === []) {
            throw new McpException('No site matched. Pass `sites` or `all: true` with filters.', 'validation_error');
        }

        $body = ['site_ids' => array_map(fn (Site $site): string => (string) $site->id, $sites)];
        foreach ($fields as $field) {
            if (array_key_exists($field, $arguments)) {
                $body[$field] = $arguments[$field];
            }
        }

        $result = app(OpsRouteInvoker::class)->invoke($route, [], $body, $context->user, $context->ip);
        if (! $result['ok']) {
            throw new McpException($result['message'] ?: 'Plane refused the bulk request.', $result['type'], array_filter(['errors' => $result['errors'] ?? null]));
        }

        $output = array_merge(
            ['ok' => true, 'action' => $action, 'message' => $result['message']],
            $result['data'] ?? [],
            ['sites' => array_map(fn (Site $site): string => $site->slug, $sites)],
        );
        if (isset($output['job']['id'])) {
            $output['next'] = 'Poll list_jobs with job_id='.$output['job']['id'].'. Deploys then run as their own Coolify deployments (list_jobs.active_deployments).';
        }

        return $output;
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return list<Site>
     */
    private function sites(McpContext $context, array $arguments): array
    {
        if ($arguments['all'] ?? false) {
            if (! $context->token->can(McpToken::ABILITY_DANGER)) {
                throw new McpException('`all: true` acts on the whole fleet and needs a token with the danger ability. Pass explicit `sites` instead.', 'forbidden');
            }

            return Site::query()
                ->matchingListFilters(
                    search: trim((string) ($arguments['filter_search'] ?? '')),
                    channel: (string) ($arguments['filter_channel'] ?? ''),
                    themeId: trim((string) ($arguments['filter_theme_id'] ?? '')),
                )
                ->orderBy('name')
                ->get()
                ->all();
        }

        $refs = array_values(array_filter((array) ($arguments['sites'] ?? []), 'is_string'));

        return collect($refs)
            ->map(fn (string $ref): Site => McpModels::site($ref))
            ->unique('id')
            ->values()
            ->all();
    }
}
