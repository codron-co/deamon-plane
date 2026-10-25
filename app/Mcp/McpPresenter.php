<?php

namespace App\Mcp;

use App\Models\AuditLog;
use App\Models\CloudflareSetting;
use App\Models\DatabaseServer;
use App\Models\Deployment;
use App\Models\MailServer;
use App\Models\OpsBackgroundJob;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\SiteThemeInstallation;
use App\Models\Theme;
use App\Models\User;
use BackedEnum;
use DateTimeInterface;

/**
 * Plain arrays for agents: ids an agent can pass back, states as values, no
 * secrets (agent secret, APP_KEY, DB passwords, Coolify/Cloudflare tokens).
 */
final class McpPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function siteSummary(Site $site): array
    {
        $deployment = $site->relationLoaded('latestDeployment') ? $site->latestDeployment : $site->latestDeployment()->first();

        return [
            'id' => $site->id,
            'slug' => $site->slug,
            'name' => $site->name,
            'primary_domain' => $site->primary_domain,
            'url' => filled($site->primary_domain) ? 'https://'.$site->primary_domain : null,
            'status' => self::value($site->status),
            'channel' => self::value($site->channel),
            'desired_channel' => self::value($site->desired_channel),
            'publish_status' => self::value($site->publishStatus()),
            'deamon_version' => $site->reportedDeamonVersion(),
            'active_theme' => $site->reportedActiveThemeId(),
            'healthy' => $site->last_health_at !== null ? ! $site->health_unhealthy : null,
            'last_health_at' => self::time($site->last_health_at),
            'live_http_status' => $site->last_live_http_status,
            'coolify_app_uuid' => $site->coolify_app_uuid,
            'latest_deployment' => $deployment instanceof Deployment ? self::deployment($deployment) : null,
            'archived' => $site->trashed(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function siteDetail(Site $site): array
    {
        $site->loadMissing(['tags', 'turnstile']);
        $domains = SiteDomain::query()->where('site_id', $site->id)->orderByDesc('is_primary')->orderBy('domain')->get();
        $installations = SiteThemeInstallation::query()->with('theme')->where('site_id', $site->id)->get();

        return array_merge(self::siteSummary($site), [
            'panel_url' => route('ops.sites.show', $site),
            'notes' => $site->notes,
            'importance' => $site->importanceLevel()->key(),
            'tags' => $site->tags->pluck('name')->values()->all(),
            'hosts' => $site->operatorHosts(),
            'domains' => $domains->map(fn (SiteDomain $domain): array => self::domain($domain))->values()->all(),
            'waiting_on_dns' => $site->isWaitingOnDns(),
            'cloudflare' => [
                'account_id' => $site->cloudflare_setting_id,
                'account' => CloudflareSetting::query()->find($site->cloudflare_setting_id)?->name,
                'zone_id' => $site->cloudflare_zone_id,
                'zone_status' => $site->cloudflare_zone_status,
                'nameservers' => is_array($site->cloudflare_nameservers) ? array_values($site->cloudflare_nameservers) : [],
                'dns_applied_at' => self::time($site->dns_applied_at),
                'alias_zones_pending' => $site->aliasZonesPending(),
            ],
            'coolify' => [
                'connection_id' => $site->coolify_connection_id,
                'server_uuid' => $site->coolify_server_uuid,
                'project_uuid' => $site->coolify_project_uuid,
                'environment_uuid' => $site->coolify_environment_uuid,
                'app_uuid' => $site->coolify_app_uuid,
                'auto_deploy' => $site->coolify_auto_deploy,
                'pinned_sha' => $site->coolify_pinned_sha,
                'deploy_gate' => self::value($site->deploy_gate),
                'deploy_canary' => (bool) $site->deploy_canary,
                'ui_url' => $site->coolifyUiUrl(),
            ],
            'agent_secret' => $site->agentSecretFleetState(),
            'themes' => $installations->map(fn (SiteThemeInstallation $row): array => self::installation($row))->values()->all(),
            'theme_update_available' => $site->hasThemeUpdate(),
            'app_health' => [
                'has_issues' => (bool) $site->app_has_issues,
                'issue_count' => (int) $site->app_health_issue_count,
                'checked_at' => self::time($site->last_app_health_at),
            ],
            'mail' => [
                'server' => MailServer::query()->find($site->mail_server_id)?->name,
                'state' => $site->mailConfigureState(),
                'domain' => $site->mail_domain,
                'error' => $site->mail_configure_error,
                'platform_recipient' => $site->platform_mail_recipient,
            ],
            'database' => [
                'server' => DatabaseServer::query()->find($site->database_server_id)?->name,
                'database' => $site->db_database,
            ],
            'turnstile' => $site->turnstile === null ? null : [
                'enabled' => (bool) $site->turnstile->enabled,
                'mode' => $site->turnstile->mode,
                'synced_at' => self::time($site->turnstile->synced_at),
                'error' => $site->turnstile->sync_error ?? $site->turnstile->push_error,
            ],
            'last_failure' => $site->lastFailureMessage(),
            'recent_deployments' => $site->deployments()
                ->orderByDesc('id')
                ->limit(5)
                ->get()
                ->map(fn (Deployment $deployment): array => self::deployment($deployment))
                ->all(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function domain(SiteDomain $domain): array
    {
        return [
            'domain' => $domain->domain,
            'is_primary' => (bool) $domain->is_primary,
            'is_www' => (bool) $domain->is_www,
            'is_temporary' => (bool) $domain->is_temporary,
            'zone_pending' => $domain->zonePending(),
            'nameservers' => is_array($domain->cloudflare_nameservers) ? array_values($domain->cloudflare_nameservers) : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function installation(SiteThemeInstallation $row): array
    {
        return [
            'theme_id' => $row->theme?->theme_id,
            'installation_id' => $row->id,
            'name' => $row->theme?->displayName(),
            'ref' => $row->ref,
            'pinned_sha' => $row->pinned_sha,
            'catalog_latest_sha' => $row->theme?->latest_sha,
            'catalog_latest_tag' => $row->theme?->latest_tag,
            'is_active' => (bool) $row->is_active,
            'auto_update' => (bool) $row->auto_update,
            'status' => self::value($row->status),
            'last_error' => $row->last_error,
            'pending_sync_after_deploy' => (bool) $row->pending_sync_after_deploy,
            'customized_files' => is_array($row->customized_files) ? count($row->customized_files) : 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function theme(Theme $theme): array
    {
        return [
            'theme_id' => $theme->theme_id,
            'name' => $theme->displayName(),
            'description' => $theme->description,
            'repo' => $theme->repo_full_name,
            'default_ref' => $theme->default_ref,
            'visibility' => self::value($theme->visibility),
            'latest_tag' => $theme->latest_tag,
            'latest_sha' => $theme->latest_sha,
            'minimum_deamon_version' => $theme->minimum_deamon_version,
            'ci_gate' => (bool) $theme->ci_gate,
            'last_synced_at' => self::time($theme->last_synced_at),
            'installed_on' => $theme->installations_count ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function deployment(Deployment $deployment, bool $withLog = false): array
    {
        $row = [
            'id' => $deployment->id,
            'status' => self::value($deployment->status),
            'trigger' => self::value($deployment->trigger),
            'channel' => self::value($deployment->channel),
            'commit' => $deployment->shortSha() ?: null,
            'coolify_deployment_uuid' => $deployment->coolify_deployment_uuid,
            'started_at' => self::time($deployment->started_at),
            'finished_at' => self::time($deployment->finished_at),
            'failure' => $deployment->failureHeadline(),
        ];

        if ($withLog) {
            $diagnosis = $deployment->diagnosis();
            $row['error_message'] = $deployment->error_message;
            $row['log_excerpt'] = $deployment->log_excerpt;
            $row['diagnosis'] = $diagnosis === null ? null : [
                'title' => $diagnosis->title(),
                'cause' => $diagnosis->cause(),
                'steps' => $diagnosis->steps(),
            ];
            $row['waiting_action'] = self::value($deployment->queue_action);
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    public static function job(OpsBackgroundJob $job): array
    {
        return [
            'id' => $job->id,
            'type' => $job->type,
            'title' => $job->toWidget()['title'],
            'status' => $job->status,
            'progress' => (int) $job->progress,
            'message' => $job->message,
            'result' => $job->result,
            'actor' => self::email($job->actor_user_id),
            'created_at' => self::time($job->created_at),
            'updated_at' => self::time($job->updated_at),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function audit(AuditLog $log): array
    {
        return [
            'id' => $log->id,
            'action' => $log->action,
            'label' => $log->actionLabel(),
            'outcome' => $log->outcome(),
            'actor' => self::email($log->actor_user_id),
            'at' => self::time($log->created_at),
            'after' => $log->after,
        ];
    }

    private static function email(mixed $userId): ?string
    {
        return is_numeric($userId) ? User::query()->find((int) $userId)?->email : null;
    }

    public static function value(mixed $value): mixed
    {
        return $value instanceof BackedEnum ? $value->value : $value;
    }

    public static function time(mixed $value): ?string
    {
        return $value instanceof DateTimeInterface ? $value->format(DATE_ATOM) : null;
    }
}
