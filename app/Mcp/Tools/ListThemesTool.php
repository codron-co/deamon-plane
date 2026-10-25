<?php

namespace App\Mcp\Tools;

use App\Mcp\McpContext;
use App\Mcp\McpPresenter;
use App\Models\Theme;

class ListThemesTool extends ReadTool
{
    public function name(): string
    {
        return 'list_themes';
    }

    public function description(): string
    {
        return 'Theme catalog (synced from the theme Git repos): theme_id, latest tag/sha, visibility, minimum CMS version, and how many sites install it. Run sync_theme_catalog first if a theme was just pushed.';
    }

    public function inputSchema(): array
    {
        return self::schema([
            'search' => ['type' => 'string', 'description' => 'theme_id, name or repo contains.'],
        ]);
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $themes = Theme::query()
            ->matchingListFilters(search: trim((string) ($arguments['search'] ?? '')))
            ->withCount('installations')
            ->orderBy('theme_id')
            ->get();

        return [
            'count' => $themes->count(),
            'themes' => $themes->map(fn (Theme $theme): array => McpPresenter::theme($theme))->values()->all(),
        ];
    }
}
