<?php

namespace App\Mcp\Tools;

use App\Mcp\McpContext;
use App\Mcp\McpModels;
use App\Mcp\Tools\Concerns\PresentsSecurity;
use App\Services\Security\SecurityEventQuery;

class ListSecurityEventsTool extends ReadTool
{
    use PresentsSecurity;

    public function name(): string
    {
        return 'list_security_events';
    }

    public function description(): string
    {
        return 'Security events the sites reported (probes, scanners, brute force, ...), newest first. Filter by site, minimum severity, rule, IP, period or free text. Events hold no request bodies or secrets.';
    }

    public function inputSchema(): array
    {
        return self::schema([
            'site' => self::SITE_PROPERTY,
            'severity' => ['type' => 'string', 'enum' => array_keys(SecurityEventQuery::SEVERITIES), 'description' => 'Minimum severity.'],
            'rule' => ['type' => 'string'],
            'ip' => ['type' => 'string'],
            'period' => ['type' => 'string', 'enum' => array_keys(SecurityEventQuery::PERIODS), 'default' => '24h'],
            'search' => ['type' => 'string', 'description' => 'IP, path, rule or user agent contains.'],
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 25],
        ]);
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $site = isset($arguments['site']) && $arguments['site'] !== '' ? McpModels::site($arguments['site']) : null;

        $query = SecurityEventQuery::fromArray([
            'q' => $arguments['search'] ?? '',
            'severity' => $arguments['severity'] ?? '',
            'site' => $site?->id ?? '',
            'rule' => $arguments['rule'] ?? '',
            'ip' => $arguments['ip'] ?? '',
            'period' => $arguments['period'] ?? '24h',
        ]);

        $events = $query->builder()->limit(self::limit($arguments, 25))->get();

        return [
            'period' => $query->period,
            'count' => $events->count(),
            'events' => $events->map(fn ($event): array => self::eventRow($event))->values()->all(),
        ];
    }
}
