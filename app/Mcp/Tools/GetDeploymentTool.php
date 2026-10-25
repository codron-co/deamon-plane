<?php

namespace App\Mcp\Tools;

use App\Mcp\McpContext;
use App\Mcp\McpModels;
use App\Mcp\McpPresenter;

class GetDeploymentTool extends ReadTool
{
    public function name(): string
    {
        return 'get_deployment';
    }

    public function description(): string
    {
        return 'One deployment of a site: status, commit, timing, error, log excerpt and Plane\'s diagnosis. Omit deployment_id for the latest. Builds take minutes; poll with a pause, use sync_site_from_coolify if the status looks stale.';
    }

    public function inputSchema(): array
    {
        return self::schema([
            'site' => self::SITE_PROPERTY,
            'deployment_id' => ['type' => ['integer', 'string'], 'description' => 'Plane deployment id or Coolify deployment uuid.'],
        ], ['site']);
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $site = McpModels::site($arguments['site'] ?? '');
        $id = $arguments['deployment_id'] ?? null;

        $deployment = filled($id)
            ? McpModels::deployment($site, $id)
            : $site->deployments()->orderByDesc('id')->first();

        return [
            'site' => $site->slug,
            'deployment' => $deployment === null ? null : McpPresenter::deployment($deployment, withLog: true),
        ];
    }
}
