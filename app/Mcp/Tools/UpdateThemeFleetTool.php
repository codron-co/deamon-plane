<?php

namespace App\Mcp\Tools;

use App\Mcp\McpContext;
use App\Mcp\McpException;
use App\Mcp\McpModels;
use App\Mcp\McpTool;
use App\Mcp\OpsRouteInvoker;
use App\Models\McpToken;
use App\Models\SiteThemeInstallation;

/**
 * Update one theme on every site whose active install is behind the catalog.
 * Dry run by default: the agent shows the list, the user agrees, then it runs.
 */
class UpdateThemeFleetTool implements McpTool
{
    public function name(): string
    {
        return 'update_theme_fleet';
    }

    public function description(): string
    {
        return 'Update a theme to the catalog\'s latest version on every site whose active install is behind (or only `sites`). Dry run unless `dry_run: false`. Update the `test` site first with update_theme, check it, then run this. Each site queues its own theme update job.';
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
                'theme_id' => ['type' => 'string'],
                'sites' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Only these sites.'],
                'exclude' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Skip these sites.'],
                'dry_run' => ['type' => 'boolean', 'default' => true],
            ],
            'required' => ['theme_id'],
            'additionalProperties' => false,
        ];
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $theme = McpModels::theme($arguments['theme_id'] ?? '');
        $only = collect((array) ($arguments['sites'] ?? []))->map(fn ($ref) => McpModels::site($ref)->id)->all();
        $exclude = collect((array) ($arguments['exclude'] ?? []))->map(fn ($ref) => McpModels::site($ref)->id)->all();
        $dryRun = (bool) ($arguments['dry_run'] ?? true);

        if (! $dryRun && $only === [] && ! $context->token->can(McpToken::ABILITY_DANGER)) {
            throw new McpException('Updating every site needs a token with the danger ability. Pass `sites` to choose them.', 'forbidden');
        }

        $installations = SiteThemeInstallation::query()
            ->with(['site', 'theme'])
            ->where('site_theme_installations.theme_id', $theme->id)
            ->behindCatalog()
            ->when($only !== [], fn ($query) => $query->whereIn('site_id', $only))
            ->when($exclude !== [], fn ($query) => $query->whereNotIn('site_id', $exclude))
            ->get()
            ->filter(fn (SiteThemeInstallation $row): bool => $row->site !== null)
            ->values();

        $rows = [];
        foreach ($installations as $installation) {
            $row = [
                'site' => $installation->site->slug,
                'from' => $installation->pinned_sha ? substr((string) $installation->pinned_sha, 0, 7) : null,
                'to' => substr((string) $theme->latest_sha, 0, 7),
            ];

            if (! $dryRun) {
                $result = app(OpsRouteInvoker::class)->invoke(
                    'ops.sites.themes.update',
                    ['site' => $installation->site, 'installation' => $installation],
                    [],
                    $context->user,
                    $context->ip,
                );
                $row['ok'] = $result['ok'];
                $row['message'] = $result['message'];
            }

            $rows[] = $row;
        }

        return [
            'theme_id' => $theme->theme_id,
            'latest_tag' => $theme->latest_tag,
            'dry_run' => $dryRun,
            'count' => count($rows),
            'sites' => $rows,
            'next' => $dryRun && $rows !== []
                ? 'Show the user this list; after they agree call again with dry_run: false.'
                : null,
        ];
    }
}
