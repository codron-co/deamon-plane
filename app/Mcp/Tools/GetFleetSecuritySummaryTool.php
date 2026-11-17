<?php

namespace App\Mcp\Tools;

use App\Mcp\McpContext;
use App\Mcp\Tools\Concerns\PresentsSecurity;
use App\Models\FleetIpBlock;
use App\Services\Security\FleetSecurityOverview;

class GetFleetSecuritySummaryTool extends ReadTool
{
    use PresentsSecurity;

    public function name(): string
    {
        return 'get_fleet_security_summary';
    }

    public function description(): string
    {
        return 'Fleet security overview: events and criticals over 24 h and 7 d, the most targeted sites, the busiest IPs, IPs that hit two or more sites (cross_site, the ones to act on), active fleet blocks and how many sites report at all.';
    }

    public function inputSchema(): array
    {
        return self::schema();
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $summary = app(FleetSecurityOverview::class)->build();
        $summary['blocks'] = FleetIpBlock::query()->active()->latest('id')->limit(50)->get()
            ->map(fn (FleetIpBlock $block): array => self::blockRow($block))->values()->all();

        return $summary;
    }
}
