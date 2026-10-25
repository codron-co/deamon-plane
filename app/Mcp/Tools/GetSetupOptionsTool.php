<?php

namespace App\Mcp\Tools;

use App\Enums\CmsPublishStatus;
use App\Enums\CoolifyGitSourceKind;
use App\Enums\DeployGate;
use App\Enums\SiteImportance;
use App\Mcp\McpContext;
use App\Models\CloudflareSetting;
use App\Models\CoolifyConnection;
use App\Models\CoolifyEnvironment;
use App\Models\CoolifyGitSource;
use App\Models\CoolifyProjectRecord;
use App\Models\CoolifyServer;
use App\Models\DatabaseServer;
use App\Models\MailServer;
use App\Models\SiteTag;
use App\Models\SiteTurnstile;

class GetSetupOptionsTool extends ReadTool
{
    public function name(): string
    {
        return 'get_setup_options';
    }

    public function description(): string
    {
        return 'Choices for create_site / update_site and other settings: channels, Coolify connections with their servers, projects, environments and git sources (defaults marked), Cloudflare accounts, mail servers, database servers, tags, importance, publish states, deploy gates, Turnstile modes.';
    }

    public function inputSchema(): array
    {
        return self::schema();
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $connections = CoolifyConnection::query()
            ->where('is_enabled', true)
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        return [
            'channels' => config('ops.channels', []),
            'coolify_connections' => $connections->map(fn (CoolifyConnection $connection): array => [
                'id' => $connection->id,
                'name' => $connection->name,
                'is_default' => (bool) $connection->is_default,
                'defaults' => [
                    'server_uuid' => $connection->default_server_uuid,
                    'project_uuid' => $connection->default_project_uuid,
                    'environment_uuid' => $connection->default_environment_uuid,
                    'git_source' => $connection->default_git_source_kind instanceof CoolifyGitSourceKind && filled($connection->default_git_source_uuid)
                        ? $connection->default_git_source_kind->value.':'.$connection->default_git_source_uuid
                        : null,
                ],
                'servers' => CoolifyServer::query()->where('coolify_connection_id', $connection->id)->where('is_active', true)->orderBy('name')->get()
                    ->map(fn (CoolifyServer $row): array => ['uuid' => $row->uuid, 'name' => $row->name, 'ip' => $row->ip])->values()->all(),
                'projects' => CoolifyProjectRecord::query()->where('coolify_connection_id', $connection->id)->where('is_active', true)->orderBy('name')->get()
                    ->map(fn (CoolifyProjectRecord $row): array => ['uuid' => $row->uuid, 'name' => $row->name])->values()->all(),
                'environments' => CoolifyEnvironment::query()->where('coolify_connection_id', $connection->id)->where('is_active', true)->orderBy('name')->get()
                    ->map(fn (CoolifyEnvironment $row): array => ['uuid' => $row->uuid, 'project_uuid' => $row->project_uuid, 'name' => $row->name])->values()->all(),
                'git_sources' => CoolifyGitSource::query()->where('coolify_connection_id', $connection->id)->where('is_active', true)->orderBy('name')->get()
                    ->map(fn (CoolifyGitSource $row): array => ['value' => $row->formValue(), 'name' => $row->name])->values()->all(),
            ])->values()->all(),
            'cloudflare_accounts' => CloudflareSetting::query()->where('is_enabled', true)->orderBy('name')->get()
                ->map(fn (CloudflareSetting $account): array => [
                    'id' => $account->id,
                    'name' => $account->name,
                    'is_default' => (bool) $account->is_default,
                    'wildcard_domain' => $account->wildcard_domain,
                    'origin_ipv4' => $account->origin_ipv4,
                ])->values()->all(),
            'mail_servers' => MailServer::query()->where('is_enabled', true)->orderBy('name')->get()
                ->map(fn (MailServer $server): array => ['id' => $server->id, 'name' => $server->name, 'provider' => $server->provider])->values()->all(),
            'database_servers' => DatabaseServer::query()->orderBy('name')->get()
                ->map(fn (DatabaseServer $server): array => ['id' => $server->id, 'name' => $server->name, 'is_default' => (bool) $server->is_default])->values()->all(),
            'tags' => SiteTag::query()->orderBy('name')->get()->map(fn (SiteTag $tag): array => ['id' => $tag->id, 'name' => $tag->name])->values()->all(),
            'importance' => SiteImportance::keys(),
            'publish_statuses' => CmsPublishStatus::values(),
            'deploy_gates' => DeployGate::values(),
            'turnstile_modes' => SiteTurnstile::MODES,
            'turnstile_forms' => SiteTurnstile::FORMS,
        ];
    }
}
