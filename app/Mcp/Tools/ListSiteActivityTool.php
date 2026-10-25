<?php

namespace App\Mcp\Tools;

use App\Mcp\McpContext;
use App\Mcp\McpModels;
use App\Mcp\McpPresenter;
use App\Models\AuditLog;

class ListSiteActivityTool extends ReadTool
{
    public function name(): string
    {
        return 'list_site_activity';
    }

    public function description(): string
    {
        return 'Audit log of a site, newest first: who did what (panel or MCP), with the recorded result. Use it to see why provisioning, a theme install or a domain change failed.';
    }

    public function inputSchema(): array
    {
        return self::schema([
            'site' => self::SITE_PROPERTY,
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20],
        ], ['site']);
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $site = McpModels::site($arguments['site'] ?? '', withTrashed: true);

        $logs = AuditLog::query()
            ->forSite((string) $site->id)
            ->latest('id')
            ->limit(self::limit($arguments, 20))
            ->get();

        return [
            'site' => $site->slug,
            'activity' => $logs->map(fn (AuditLog $log): array => McpPresenter::audit($log))->values()->all(),
        ];
    }
}
