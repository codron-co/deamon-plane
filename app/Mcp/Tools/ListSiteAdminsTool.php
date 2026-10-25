<?php

namespace App\Mcp\Tools;

use App\Mcp\McpContext;
use App\Mcp\McpException;
use App\Mcp\McpModels;
use App\Services\Agent\SiteAgentClient;

class ListSiteAdminsTool extends ReadTool
{
    public function name(): string
    {
        return 'list_site_admins';
    }

    public function description(): string
    {
        return 'CMS admin users of a site, read live from the site over the signed agent. admin_id feeds the admin tools.';
    }

    public function inputSchema(): array
    {
        return self::schema(['site' => self::SITE_PROPERTY], ['site']);
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $site = McpModels::site($arguments['site'] ?? '');

        if (! $context->user->can('manageAdmins', $site)) {
            throw new McpException('Your Plane role cannot manage site admins.', 'forbidden');
        }

        $result = app(SiteAgentClient::class)->listAdmins($site);
        if (! $result->ok) {
            throw new McpException($result->safeMessage, $result->needsSecret ? 'needs_agent_secret' : 'agent_error');
        }

        return [
            'site' => $site->slug,
            'admins' => $result->admins,
        ];
    }
}
