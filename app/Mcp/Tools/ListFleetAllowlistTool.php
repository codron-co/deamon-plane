<?php

namespace App\Mcp\Tools;

use App\Mcp\McpContext;
use App\Mcp\Tools\Concerns\PresentsSecurity;
use App\Models\FleetAllowlistEntry;

class ListFleetAllowlistTool extends ReadTool
{
    use PresentsSecurity;

    public function name(): string
    {
        return 'list_fleet_allowlist';
    }

    public function description(): string
    {
        return 'Addresses and ranges every site must never block (Plane, monitoring, the team), with whether each site took the entry.';
    }

    public function inputSchema(): array
    {
        return self::schema();
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $entries = FleetAllowlistEntry::query()->active()->latest('id')->limit(200)->get();

        return [
            'count' => $entries->count(),
            'allowlist' => $entries->map(fn (FleetAllowlistEntry $entry): array => self::allowRow($entry))->values()->all(),
        ];
    }
}
