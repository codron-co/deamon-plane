<?php

namespace App\Mcp;

use App\Mcp\Tools\BulkSitesTool;
use App\Mcp\Tools\GetDeploymentTool;
use App\Mcp\Tools\GetSetupOptionsTool;
use App\Mcp\Tools\GetSiteTool;
use App\Mcp\Tools\ListDnsRecordsTool;
use App\Mcp\Tools\ListJobsTool;
use App\Mcp\Tools\ListSiteActivityTool;
use App\Mcp\Tools\ListSiteAdminsTool;
use App\Mcp\Tools\ListSitesTool;
use App\Mcp\Tools\ListThemesTool;
use App\Mcp\Tools\RouteToolCatalog;
use App\Mcp\Tools\UpdateThemeFleetTool;
use App\Mcp\Tools\WhoamiTool;
use App\Models\McpToken;

class McpToolRegistry
{
    /** @var array<string, McpTool>|null */
    private ?array $tools = null;

    /**
     * @return array<string, McpTool>
     */
    public function all(): array
    {
        if ($this->tools !== null) {
            return $this->tools;
        }

        $tools = [
            new WhoamiTool,
            new ListSitesTool,
            new GetSiteTool,
            new GetSetupOptionsTool,
            new ListThemesTool,
            new ListJobsTool,
            new GetDeploymentTool,
            new ListSiteActivityTool,
            new ListDnsRecordsTool,
            new ListSiteAdminsTool,
            ...RouteToolCatalog::tools(),
            new BulkSitesTool,
            new UpdateThemeFleetTool,
        ];

        $this->tools = [];
        foreach ($tools as $tool) {
            $this->tools[$tool->name()] = $tool;
        }

        return $this->tools;
    }

    public function find(string $name): ?McpTool
    {
        return $this->all()[$name] ?? null;
    }

    /**
     * Tools the token may call, in MCP tools/list shape.
     *
     * @return list<array<string, mixed>>
     */
    public function catalog(McpToken $token): array
    {
        $rows = [];
        foreach ($this->all() as $tool) {
            if (! $token->can($tool->ability())) {
                continue;
            }

            $rows[] = [
                'name' => $tool->name(),
                'description' => $tool->description(),
                'inputSchema' => $tool->inputSchema(),
                'annotations' => [
                    'readOnlyHint' => $tool->ability() === McpToken::ABILITY_READ,
                    'destructiveHint' => $tool->ability() === McpToken::ABILITY_DANGER,
                    'openWorldHint' => true,
                ],
            ];
        }

        return $rows;
    }
}
