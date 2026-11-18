<?php

namespace App\Mcp\Tools;

use App\Mcp\McpContext;
use App\Mcp\McpModels;
use App\Mcp\Tools\Concerns\PresentsSecurity;
use App\Services\Security\FleetSecurityOverview;
use App\Services\Security\SecurityEventQuery;

class GetSiteSecurityTool extends ReadTool
{
    use PresentsSecurity;

    public function name(): string
    {
        return 'get_site_security';
    }

    public function description(): string
    {
        return 'One site\'s security picture: whether it reports (module on, CMS supports it, last poll and errors), its counters from the CMS (24 h / 7 d, top IPs) and its latest events.';
    }

    public function inputSchema(): array
    {
        return self::schema([
            'site' => self::SITE_PROPERTY,
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 10, 'description' => 'Latest events to include.'],
        ], ['site']);
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $site = McpModels::site($arguments['site'] ?? '');

        $recent = SecurityEventQuery::fromArray(['site' => $site->id, 'period' => '7d'])
            ->builder()
            ->limit(self::limit($arguments, 10, 50))
            ->get();

        return app(FleetSecurityOverview::class)->site($site) + [
            'recent_events' => $recent->map(fn ($event): array => self::eventRow($event))->values()->all(),
        ];
    }
}
