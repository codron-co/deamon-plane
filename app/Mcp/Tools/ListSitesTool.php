<?php

namespace App\Mcp\Tools;

use App\Enums\SiteStatus;
use App\Mcp\McpContext;
use App\Mcp\McpPresenter;
use App\Models\Site;

class ListSitesTool extends ReadTool
{
    public function name(): string
    {
        return 'list_sites';
    }

    public function description(): string
    {
        return 'List Deamon sites with status, channel, CMS version, active theme, health and latest deployment. Filters match the panel Sites list.';
    }

    public function inputSchema(): array
    {
        return self::schema([
            'search' => ['type' => 'string', 'description' => 'Name, slug or domain contains.'],
            'channel' => ['type' => 'string', 'enum' => config('ops.channels', [])],
            'status' => ['type' => 'string', 'enum' => SiteStatus::values()],
            'theme_id' => ['type' => 'string', 'description' => 'Sites using this catalog theme.'],
            'unhealthy' => ['type' => 'boolean', 'description' => 'Only sites whose agent health check fails.'],
            'failed_deploy' => ['type' => 'boolean', 'description' => 'Only sites whose last deployment failed.'],
            'archived' => ['type' => 'boolean', 'description' => 'List archived (soft-deleted) sites instead.'],
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 50],
        ]);
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $query = ($arguments['archived'] ?? false) ? Site::onlyTrashed() : Site::query();

        $sites = $query
            ->matchingListFilters(
                search: trim((string) ($arguments['search'] ?? '')),
                channel: (string) ($arguments['channel'] ?? ''),
                status: (string) ($arguments['status'] ?? ''),
                deploy: ($arguments['failed_deploy'] ?? false) ? 'failed' : '',
                health: ($arguments['unhealthy'] ?? false) ? 'unhealthy' : '',
                themeId: trim((string) ($arguments['theme_id'] ?? '')),
            )
            ->with('latestDeployment')
            ->orderBy('name')
            ->limit(self::limit($arguments, 50, 200))
            ->get();

        return [
            'count' => $sites->count(),
            'sites' => $sites->map(fn (Site $site): array => McpPresenter::siteSummary($site))->values()->all(),
        ];
    }
}
