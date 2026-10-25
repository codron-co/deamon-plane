<?php

namespace App\Mcp\Tools;

use App\Mcp\McpContext;
use App\Mcp\McpModels;
use App\Mcp\McpPresenter;

class GetSiteTool extends ReadTool
{
    public function name(): string
    {
        return 'get_site';
    }

    public function description(): string
    {
        return 'Everything about one site: domains, Cloudflare zone and nameservers, Coolify app and deploy settings, installed themes vs catalog, health, mail, Turnstile, recent deployments. Re-read after each step.';
    }

    public function inputSchema(): array
    {
        return self::schema(['site' => self::SITE_PROPERTY], ['site']);
    }

    public function handle(McpContext $context, array $arguments): array
    {
        return McpPresenter::siteDetail(McpModels::site($arguments['site'] ?? '', withTrashed: true));
    }
}
