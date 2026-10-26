<?php

namespace App\Mcp\Tools;

use App\Enums\CmsPublishStatus;
use App\Enums\DeployGate;
use App\Enums\SiteImportance;
use App\Enums\ThemeVisibility;
use App\Mcp\McpModels;
use App\Models\CoolifyConnection;
use App\Models\McpToken;
use App\Models\Site;
use App\Models\SiteTurnstile;
use App\Models\Theme;
use App\Services\Agent\ControlPlaneAgentContract;
use App\Services\Cloudflare\CloudflareAccounts;
use App\Services\Cloudflare\CloudflareDnsRecord;
use App\Services\Sites\SiteAppHealthFixer;

/**
 * Write tools, one per panel route. Each runs the route in-process
 * (OpsRouteInvoker), so validation, policies and audit match the panel.
 */
final class RouteToolCatalog
{
    private const SITE = ['type' => 'string', 'description' => 'Site slug, domain or id.'];

    private const CONFIRMED = ['type' => 'boolean', 'description' => 'Set true once the user has agreed to this change.'];

    /**
     * @return list<RouteTool>
     */
    public static function tools(): array
    {
        return [
            ...self::siteLifecycle(),
            ...self::deploys(),
            ...self::domainsAndCloudflare(),
            ...self::themes(),
            ...self::siteSettings(),
            ...self::admins(),
            ...self::comail(),
        ];
    }

    /**
     * @return list<RouteTool>
     */
    private static function siteLifecycle(): array
    {
        $hosts = ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 20, 'description' => 'Extra hostnames (www is added for each).'];
        $organisation = [
            'notes' => ['type' => 'string'],
            'importance' => ['type' => 'string', 'enum' => SiteImportance::keys()],
            'tags' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Tag ids from get_setup_options.'],
            'mail_server_id' => ['type' => 'string', 'description' => 'Mail server id from get_setup_options.'],
            'cloudflare_setting_id' => ['type' => 'integer', 'description' => 'Cloudflare account id; default account if omitted on create.'],
        ];
        $coolify = [
            'coolify_connection_id' => ['type' => 'integer'],
            'coolify_server_uuid' => ['type' => 'string'],
            'coolify_project_uuid' => ['type' => 'string'],
            'coolify_environment_uuid' => ['type' => 'string'],
            'coolify_git_source' => ['type' => 'string', 'description' => '`kind:uuid` from get_setup_options.'],
        ];

        return [
            new RouteTool(
                name: 'create_site',
                description: 'Create a new site in Plane (status draft; nothing is built yet). Coolify targets and the Cloudflare account default to the panel defaults (get_setup_options). `placement: attach` + `attach_app_uuid` adopts an existing Coolify app instead. Next: provision_site.',
                route: 'ops.sites.store',
                ability: McpToken::ABILITY_WRITE,
                properties: [
                    'slug' => ['type' => 'string', 'description' => 'lowercase-with-hyphens; also the Coolify app name.'],
                    'name' => ['type' => 'string', 'description' => 'Display name (pushed to the CMS).'],
                    'domain' => ['type' => 'string', 'description' => 'Primary hostname, e.g. shop.example.com (www is added).'],
                    'aliases' => $hosts,
                    'channel' => ['type' => 'string', 'enum' => config('ops.channels', []), 'default' => 'beta', 'description' => 'CMS release channel (git branch). Customer sites run on beta; alpha is only the test site.'],
                    ...$organisation,
                    ...$coolify,
                    'placement' => ['type' => 'string', 'enum' => ['provision', 'attach'], 'default' => 'provision'],
                    'attach_app_uuid' => ['type' => 'string'],
                ],
                required: ['slug', 'name', 'domain'],
                prepare: static fn (array $body): array => self::withCreateDefaults($body),
                siteFromArgument: 'slug',
            ),
            new RouteTool(
                name: 'update_site',
                description: 'Change a site\'s name, primary domain, aliases, notes, importance, tags, mail server or Cloudflare account. Only the fields you pass change. A host change rebinds Coolify and redeploys. Slug, channel and Coolify targets only change while the site is a draft (use switch_channel later).',
                route: 'ops.sites.update',
                ability: McpToken::ABILITY_WRITE,
                properties: [
                    'site' => self::SITE,
                    'slug' => ['type' => 'string'],
                    'name' => ['type' => 'string'],
                    'domain' => ['type' => 'string', 'description' => 'New primary hostname.'],
                    'aliases' => array_merge($hosts, ['description' => 'Replaces the alias list (omit to keep it).']),
                    'channel' => ['type' => 'string', 'enum' => config('ops.channels', [])],
                    ...$organisation,
                    ...$coolify,
                ],
                required: ['site'],
                params: ['site' => 'site'],
                prepare: static fn (array $body, array $params): array => self::withCurrentSite($body, $params['site']),
            ),
            new RouteTool(
                name: 'provision_site',
                description: 'Build a draft (or errored) site: Cloudflare zone + DNS + temporary preview host, Coolify app with env and agent secret, first deploy. Runs in the background; follow with get_site / get_deployment. The status goes provisioning -> active, or error (list_site_activity has the reason; fix it and call again).',
                route: 'ops.sites.provision',
                ability: McpToken::ABILITY_WRITE,
                properties: ['site' => self::SITE],
                required: ['site'],
                params: ['site' => 'site'],
            ),
            new RouteTool(
                name: 'activate_site',
                description: 'Start a stopped site\'s Coolify app and mark it active.',
                route: 'ops.sites.activate',
                ability: McpToken::ABILITY_WRITE,
                properties: ['site' => self::SITE],
                required: ['site'],
                params: ['site' => 'site'],
            ),
            new RouteTool(
                name: 'deactivate_site',
                description: 'Stop a site\'s Coolify app (the site goes offline) and mark it archived. Ask the user first.',
                route: 'ops.sites.deactivate',
                ability: McpToken::ABILITY_DANGER,
                properties: ['site' => self::SITE],
                required: ['site'],
                params: ['site' => 'site'],
            ),
            new RouteTool(
                name: 'archive_site',
                description: 'Soft-delete a site in Plane (Coolify is not touched; restore_site brings it back). Ask the user first.',
                route: 'ops.sites.destroy',
                ability: McpToken::ABILITY_DANGER,
                properties: ['site' => self::SITE],
                required: ['site'],
                params: ['site' => 'site'],
            ),
            new RouteTool(
                name: 'restore_site',
                description: 'Bring an archived site back (Super Admin).',
                route: 'ops.sites.restore',
                ability: McpToken::ABILITY_WRITE,
                properties: ['site' => self::SITE],
                required: ['site'],
                params: ['site' => 'site_trashed'],
            ),
            new RouteTool(
                name: 'purge_site',
                description: 'PERMANENT: delete the Coolify app with its volumes (database, uploads) and the Plane record. Cannot be undone. Only on the user\'s explicit request.',
                route: 'ops.sites.purge',
                ability: McpToken::ABILITY_DANGER,
                properties: ['site' => self::SITE],
                required: ['site'],
                params: ['site' => 'site_trashed'],
            ),
        ];
    }

    /**
     * @return list<RouteTool>
     */
    private static function deploys(): array
    {
        $site = ['site' => self::SITE];

        return [
            new RouteTool(
                name: 'deploy_site',
                description: 'Rebuild and redeploy the site\'s current commit on Coolify (pin untouched). Waits in Plane\'s deploy line when the host is at its build cap. Follow with get_deployment.',
                route: 'ops.sites.deploy',
                ability: McpToken::ABILITY_WRITE,
                properties: $site,
                required: ['site'],
                params: ['site' => 'site'],
            ),
            new RouteTool(
                name: 'update_site_to_head',
                description: 'Deploy the newest commit of the site\'s channel branch (CMS update) without changing the auto-deploy setting. Built-image channels: the newest commit the channel image builder finished.',
                route: 'ops.sites.update-head',
                ability: McpToken::ABILITY_WRITE,
                properties: $site,
                required: ['site'],
                params: ['site' => 'site'],
            ),
            new RouteTool(
                name: 'follow_branch_head',
                description: 'Unpin: track the branch HEAD, turn Coolify auto-deploy on (off on the CI gate) and deploy. Built-image channels: deploys the newest built commit, auto-deploy stays off.',
                route: 'ops.sites.follow-head',
                ability: McpToken::ABILITY_WRITE,
                properties: $site,
                required: ['site'],
                params: ['site' => 'site'],
            ),
            new RouteTool(
                name: 'pin_site_commit',
                description: 'Pin the site to a commit/tag, turn auto-deploy off and deploy it (rollback or hold a version). Built-image channels: only a commit the channel image builder finished.',
                route: 'ops.sites.pin',
                ability: McpToken::ABILITY_WRITE,
                properties: $site + ['ref' => ['type' => 'string', 'description' => 'Commit SHA or tag.']],
                required: ['site', 'ref'],
                params: ['site' => 'site'],
            ),
            new RouteTool(
                name: 'set_auto_deploy',
                description: 'Turn Coolify auto-deploy on push on or off for a site. Turning it on is refused for sites that run built images.',
                route: 'ops.sites.auto-deploy',
                ability: McpToken::ABILITY_WRITE,
                properties: $site + ['enabled' => ['type' => 'boolean']],
                required: ['site', 'enabled'],
                params: ['site' => 'site'],
            ),
            new RouteTool(
                name: 'set_deploy_gate',
                description: '`coolify`: Coolify builds on push. `ci`: Plane deploys only green CI commits (fleet rollout). Never deploys by itself.',
                route: 'ops.sites.deploy-gate',
                ability: McpToken::ABILITY_WRITE,
                properties: $site + ['gate' => ['type' => 'string', 'enum' => DeployGate::values()]],
                required: ['site', 'gate'],
                params: ['site' => 'site'],
            ),
            new RouteTool(
                name: 'set_deploy_canary',
                description: 'Canary sites build a green CI commit first; the rest of the channel waits for them.',
                route: 'ops.sites.deploy-canary',
                ability: McpToken::ABILITY_WRITE,
                properties: $site + ['canary' => ['type' => 'boolean']],
                required: ['site', 'canary'],
                params: ['site' => 'site'],
            ),
            new RouteTool(
                name: 'switch_channel',
                description: 'Move an active site to another release channel (main / beta / alpha): branch + env + redeploy. `confirmed` is needed when the switch skips a channel or downgrades.',
                route: 'ops.sites.channel',
                ability: McpToken::ABILITY_WRITE,
                properties: $site + [
                    'channel' => ['type' => 'string', 'enum' => config('ops.channels', [])],
                    'confirmed' => self::CONFIRMED,
                    'force' => ['type' => 'boolean', 'description' => 'Switch even if a deploy looks stuck.'],
                ],
                required: ['site', 'channel'],
                params: ['site' => 'site'],
            ),
            new RouteTool(
                name: 'cancel_waiting_deploy',
                description: 'Cancel a deploy still waiting in Plane\'s deploy line (status waiting). Nothing reached Coolify yet.',
                route: 'ops.sites.deployments.cancel-waiting',
                ability: McpToken::ABILITY_WRITE,
                properties: $site + ['deployment_id' => ['type' => 'integer']],
                required: ['site', 'deployment_id'],
                params: ['site' => 'site', 'deployment' => 'deployment'],
            ),
            new RouteTool(
                name: 'cancel_deployment',
                description: 'Cancel a queued or running Coolify deployment of a site.',
                route: 'ops.jobs.deployments.cancel',
                ability: McpToken::ABILITY_WRITE,
                properties: $site + ['deployment_id' => ['type' => 'integer']],
                required: ['site', 'deployment_id'],
                params: ['site' => 'site', 'deployment' => 'deployment'],
            ),
            new RouteTool(
                name: 'diagnose_deployment',
                description: 'Ask Plane to diagnose a failed deployment (container logs, known causes, suggested fix). Read the result with get_deployment.',
                route: 'ops.sites.deployments.diagnose',
                ability: McpToken::ABILITY_WRITE,
                properties: $site + ['deployment_id' => ['type' => 'integer']],
                required: ['site', 'deployment_id'],
                params: ['site' => 'site', 'deployment' => 'deployment'],
            ),
            new RouteTool(
                name: 'sync_site_from_coolify',
                description: 'Refresh Plane\'s copy of the site\'s Coolify app and last deployments (background job).',
                route: 'ops.sites.sync',
                ability: McpToken::ABILITY_WRITE,
                properties: $site,
                required: ['site'],
                params: ['site' => 'site'],
            ),
            new RouteTool(
                name: 'check_site_live',
                description: 'GET the public homepage and record the HTTP status (background job).',
                route: 'ops.sites.live-sync.one',
                ability: McpToken::ABILITY_WRITE,
                properties: $site,
                required: ['site'],
                params: ['site' => 'site'],
            ),
            new RouteTool(
                name: 'check_site_health',
                description: 'Poll the CMS over the signed agent now: version, active theme, modules. Needs the agent secret (provision injects it).',
                route: 'ops.sites.health',
                ability: McpToken::ABILITY_WRITE,
                properties: $site,
                required: ['site'],
                params: ['site' => 'site'],
            ),
            new RouteTool(
                name: 'inspect_app_health',
                description: 'Re-run the app health inspection (compose pack, env, domains, agent secret, deployments, container state) and list issues with their fixes.',
                route: 'ops.sites.app-health',
                ability: McpToken::ABILITY_WRITE,
                properties: $site,
                required: ['site'],
                params: ['site' => 'site'],
            ),
            new RouteTool(
                name: 'fix_app_health',
                description: 'Apply one app health fix (or `all` in Plane\'s safe order). Some fixes redeploy or restart the app.',
                route: 'ops.sites.app-health.fix',
                ability: McpToken::ABILITY_WRITE,
                properties: $site + ['fix' => ['type' => 'string', 'enum' => array_merge(SiteAppHealthFixer::FIX_ORDER, ['all'])]],
                required: ['site', 'fix'],
                params: ['site' => 'site'],
            ),
            new RouteTool(
                name: 'inject_agent_secret',
                description: 'Write the signed-agent secret into the site\'s Coolify env if it is missing (needs a redeploy to take effect).',
                route: 'ops.sites.agent-secret',
                ability: McpToken::ABILITY_WRITE,
                properties: $site,
                required: ['site'],
                params: ['site' => 'site'],
            ),
            new RouteTool(
                name: 'halt_fleet_rollout',
                description: 'Stop a CI fleet rollout from deploying further sites.',
                route: 'ops.rollouts.halt',
                ability: McpToken::ABILITY_WRITE,
                properties: ['rollout_id' => ['type' => 'integer']],
                required: ['rollout_id'],
                params: ['rollout' => 'rollout'],
            ),
            new RouteTool(
                name: 'resume_fleet_rollout',
                description: 'Resume a halted CI fleet rollout.',
                route: 'ops.rollouts.resume',
                ability: McpToken::ABILITY_WRITE,
                properties: ['rollout_id' => ['type' => 'integer']],
                required: ['rollout_id'],
                params: ['rollout' => 'rollout'],
            ),
        ];
    }

    /**
     * @return list<RouteTool>
     */
    private static function domainsAndCloudflare(): array
    {
        $site = ['site' => self::SITE];
        $zone = [
            'account' => ['type' => 'string', 'description' => 'Cloudflare account id or name; default account if omitted.'],
            'zone' => ['type' => 'string', 'description' => 'Zone id or apex domain.'],
        ];
        $record = [
            'type' => ['type' => 'string', 'enum' => CloudflareDnsRecord::TYPES],
            'name' => ['type' => 'string', 'description' => 'Relative (`@`, `www`, `mail`) or full name.'],
            'content' => ['type' => 'string'],
            'ttl' => ['type' => 'integer', 'description' => '1 = auto.'],
            'priority' => ['type' => 'integer', 'description' => 'Required for MX.'],
        ];

        return [
            new RouteTool(
                name: 'setup_site_cloudflare',
                description: 'Create or refresh the site\'s Cloudflare zone and its DNS records (provision does this too). Returns the nameservers the customer must set at their registrar.',
                route: 'ops.sites.cloudflare.zone',
                ability: McpToken::ABILITY_WRITE,
                properties: $site + ['cloudflare_setting_id' => ['type' => 'integer', 'description' => 'Account id; the site\'s or the default account if omitted.']],
                required: ['site'],
                params: ['site' => 'site'],
                prepare: static fn (array $body, array $params): array => $body + [
                    'cloudflare_setting_id' => $params['site']->cloudflare_setting_id ?? CloudflareAccounts::default()?->id,
                ],
            ),
            new RouteTool(
                name: 'confirm_site_dns',
                description: 'After the customer switched nameservers: ask Cloudflare whether the zone is active; when it is, Plane moves the site from the temporary host to its real domain. Not ready yet is a normal answer; try again later.',
                route: 'ops.sites.cloudflare.dns',
                ability: McpToken::ABILITY_WRITE,
                properties: $site,
                required: ['site'],
                params: ['site' => 'site'],
            ),
            new RouteTool(
                name: 'add_site_domain',
                description: 'Add a hostname (any apex; www is added) to a site: Cloudflare DNS (a new apex gets its own zone and nameservers), Coolify bind, redeploy.',
                route: 'ops.sites.domains.store',
                ability: McpToken::ABILITY_WRITE,
                properties: $site + ['domain' => ['type' => 'string']],
                required: ['site', 'domain'],
                params: ['site' => 'site'],
            ),
            new RouteTool(
                name: 'remove_site_domain',
                description: 'Remove an alias hostname from a site (DNS released, Coolify rebind, redeploy). The primary domain cannot be removed; promote another first.',
                route: 'ops.sites.domains.destroy',
                ability: McpToken::ABILITY_WRITE,
                properties: $site + ['domain' => ['type' => 'string']],
                required: ['site', 'domain'],
                params: ['site' => 'site', 'domain' => 'site_domain'],
            ),
            new RouteTool(
                name: 'set_primary_domain',
                description: 'Make one of the site\'s hostnames the primary domain (canonical URL, agent base URL).',
                route: 'ops.sites.domains.primary',
                ability: McpToken::ABILITY_WRITE,
                properties: $site + ['domain' => ['type' => 'string']],
                required: ['site', 'domain'],
                params: ['site' => 'site', 'domain' => 'site_domain'],
            ),
            new RouteTool(
                name: 'create_cloudflare_zone',
                description: 'Add a zone (apex domain) to a Cloudflare account, optionally with Plane\'s default DNS records. For a site\'s own domain prefer setup_site_cloudflare.',
                route: 'ops.cloudflare.zones.store',
                ability: McpToken::ABILITY_WRITE,
                properties: [
                    'account' => $zone['account'],
                    'name' => ['type' => 'string', 'description' => 'Apex domain, e.g. example.com.'],
                    'apply_defaults' => ['type' => 'boolean', 'default' => true],
                ],
                required: ['name'],
                params: ['account' => 'cloudflare_account'],
            ),
            new RouteTool(
                name: 'apply_cloudflare_dns_defaults',
                description: 'Write Plane\'s default DNS records (origin A, www, mail) into a zone.',
                route: 'ops.cloudflare.zones.apply',
                ability: McpToken::ABILITY_WRITE,
                properties: $zone,
                required: ['zone'],
                params: ['account' => 'cloudflare_account', 'zone' => 'zone'],
            ),
            new RouteTool(
                name: 'create_dns_record',
                description: 'Add a DNS record to a Cloudflare zone (MX, TXT for verification, CNAME...).',
                route: 'ops.cloudflare.zones.dns.store',
                ability: McpToken::ABILITY_WRITE,
                properties: $zone + $record,
                required: ['zone', 'type', 'name', 'content'],
                params: ['account' => 'cloudflare_account', 'zone' => 'zone'],
            ),
            new RouteTool(
                name: 'update_dns_record',
                description: 'Replace a DNS record (record_id from list_dns_records).',
                route: 'ops.cloudflare.zones.dns.update',
                ability: McpToken::ABILITY_WRITE,
                properties: $zone + ['record_id' => ['type' => 'string']] + $record,
                required: ['zone', 'record_id', 'type', 'name', 'content'],
                params: ['account' => 'cloudflare_account', 'zone' => 'zone', 'record' => 'record'],
            ),
            new RouteTool(
                name: 'delete_dns_record',
                description: 'Delete a DNS record. Deleting the site\'s A/CNAME records takes it offline. Ask the user first.',
                route: 'ops.cloudflare.zones.dns.destroy',
                ability: McpToken::ABILITY_DANGER,
                properties: $zone + ['record_id' => ['type' => 'string']],
                required: ['zone', 'record_id'],
                params: ['account' => 'cloudflare_account', 'zone' => 'zone', 'record' => 'record'],
            ),
        ];
    }

    /**
     * @return list<RouteTool>
     */
    private static function themes(): array
    {
        $installation = ['site' => self::SITE, 'theme_id' => ['type' => 'string', 'description' => 'Installed theme id (get_site.themes).']];

        return [
            new RouteTool(
                name: 'sync_theme_catalog',
                description: 'Re-read every theme repo (manifest, latest tag/sha). Run after a theme was pushed, before assign_theme / update_theme (background job).',
                route: 'ops.themes.sync',
                ability: McpToken::ABILITY_WRITE,
            ),
            new RouteTool(
                name: 'update_theme_settings',
                description: 'Catalog settings of a theme: visibility (who may install it), default ref, CI gate. Only passed fields change.',
                route: 'ops.themes.update',
                ability: McpToken::ABILITY_WRITE,
                properties: [
                    'theme_id' => ['type' => 'string'],
                    'visibility' => ['type' => 'string', 'enum' => ThemeVisibility::values()],
                    'default_ref' => ['type' => 'string'],
                    'ci_gate' => ['type' => 'boolean'],
                ],
                required: ['theme_id'],
                params: ['theme' => 'theme'],
                prepare: static fn (array $body, array $params): array => self::withCurrentTheme($body, $params['theme']),
            ),
            new RouteTool(
                name: 'grant_theme_access',
                description: 'Let a site install a theme whose visibility is restricted to chosen sites.',
                route: 'ops.themes.access.store',
                ability: McpToken::ABILITY_WRITE,
                properties: ['theme_id' => ['type' => 'string'], 'site' => self::SITE],
                required: ['theme_id', 'site'],
                params: ['theme' => 'theme'],
                prepare: static fn (array $body): array => ['site_id' => McpModels::site($body['site'] ?? '')->id] + array_diff_key($body, ['site' => true]),
            ),
            new RouteTool(
                name: 'revoke_theme_access',
                description: 'Remove a site from a restricted theme\'s allowed sites.',
                route: 'ops.themes.access.destroy',
                ability: McpToken::ABILITY_WRITE,
                properties: ['theme_id' => ['type' => 'string'], 'site' => self::SITE],
                required: ['theme_id', 'site'],
                params: ['theme' => 'theme', 'site' => 'site'],
            ),
            new RouteTool(
                name: 'assign_theme',
                description: 'Install a catalog theme on a site over the signed agent (files, then optional demo data sync and activation). `ref` defaults to the theme\'s default ref. `confirmed` is needed when the theme asks a newer CMS or replaces the active theme. Background; follow with get_site.themes.',
                route: 'ops.sites.themes.assign',
                ability: McpToken::ABILITY_WRITE,
                properties: [
                    'site' => self::SITE,
                    'theme_id' => ['type' => 'string'],
                    'ref' => ['type' => 'string', 'description' => 'Tag, branch or SHA.'],
                    'activate' => ['type' => 'boolean', 'description' => 'Make it the active theme when installed.'],
                    'sync' => ['type' => 'boolean', 'description' => 'Import the theme\'s demo content/settings after install.'],
                    'confirmed' => self::CONFIRMED,
                ],
                required: ['site', 'theme_id'],
                params: ['site' => 'site'],
            ),
            new RouteTool(
                name: 'update_theme',
                description: 'Update an installed theme to the catalog\'s latest version (customised files are kept on CMS that support it). Try on the `test` site first.',
                route: 'ops.sites.themes.update',
                ability: McpToken::ABILITY_WRITE,
                properties: $installation,
                required: ['site', 'theme_id'],
                params: ['site' => 'site', 'installation' => 'installation'],
            ),
            new RouteTool(
                name: 'activate_theme',
                description: 'Make an installed theme the site\'s active theme.',
                route: 'ops.sites.themes.activate',
                ability: McpToken::ABILITY_WRITE,
                properties: $installation + ['confirmed' => self::CONFIRMED],
                required: ['site', 'theme_id'],
                params: ['site' => 'site', 'installation' => 'installation'],
            ),
            new RouteTool(
                name: 'sync_theme_content',
                description: 'Push the theme\'s content/settings into the site. `merge` keeps edits the customer made; `overwrite` replaces them (needs confirmed and a recent CMS).',
                route: 'ops.sites.themes.sync',
                ability: McpToken::ABILITY_WRITE,
                properties: $installation + [
                    'mode' => ['type' => 'string', 'enum' => [ControlPlaneAgentContract::SYNC_MODE_MERGE, ControlPlaneAgentContract::SYNC_MODE_OVERWRITE], 'default' => ControlPlaneAgentContract::SYNC_MODE_MERGE],
                    'confirmed' => self::CONFIRMED,
                ],
                required: ['site', 'theme_id'],
                params: ['site' => 'site', 'installation' => 'installation'],
            ),
            new RouteTool(
                name: 'reinstall_theme',
                description: 'Reinstall an installed theme\'s files at its pinned version (repairs a broken install).',
                route: 'ops.sites.themes.reinstall',
                ability: McpToken::ABILITY_WRITE,
                properties: $installation,
                required: ['site', 'theme_id'],
                params: ['site' => 'site', 'installation' => 'installation'],
            ),
            new RouteTool(
                name: 'set_theme_auto_update',
                description: 'Let Plane update this install automatically when the theme repo gets a new version.',
                route: 'ops.sites.themes.auto-update',
                ability: McpToken::ABILITY_WRITE,
                properties: $installation + ['auto_update' => ['type' => 'boolean']],
                required: ['site', 'theme_id', 'auto_update'],
                params: ['site' => 'site', 'installation' => 'installation'],
            ),
            new RouteTool(
                name: 'rollback_theme_sync',
                description: 'Undo the last theme content sync on the site.',
                route: 'ops.sites.themes.sync-rollback',
                ability: McpToken::ABILITY_DANGER,
                properties: $installation + ['confirmed' => self::CONFIRMED],
                required: ['site', 'theme_id', 'confirmed'],
                params: ['site' => 'site', 'installation' => 'installation'],
            ),
            new RouteTool(
                name: 'rollback_theme_files',
                description: 'Put the theme files back to the previous version.',
                route: 'ops.sites.themes.files-rollback',
                ability: McpToken::ABILITY_DANGER,
                properties: $installation + ['confirmed' => self::CONFIRMED],
                required: ['site', 'theme_id', 'confirmed'],
                params: ['site' => 'site', 'installation' => 'installation'],
            ),
            new RouteTool(
                name: 'remove_theme',
                description: 'Uninstall a theme from a site (the active theme cannot be removed).',
                route: 'ops.sites.themes.remove',
                ability: McpToken::ABILITY_DANGER,
                properties: $installation,
                required: ['site', 'theme_id'],
                params: ['site' => 'site', 'installation' => 'installation'],
            ),
        ];
    }

    /**
     * @return list<RouteTool>
     */
    private static function siteSettings(): array
    {
        $site = ['site' => self::SITE];

        return [
            new RouteTool(
                name: 'set_publish_status',
                description: '`published`: the site is open to visitors. `draft`: visitors see the maintenance page (admins still log in). Pushed to the CMS over the agent.',
                route: 'ops.sites.publish-status',
                ability: McpToken::ABILITY_WRITE,
                properties: $site + ['publish_status' => ['type' => 'string', 'enum' => CmsPublishStatus::values()]],
                required: ['site', 'publish_status'],
                params: ['site' => 'site'],
            ),
            new RouteTool(
                name: 'set_site_env',
                description: 'Set one Coolify env variable of the site. Per ADR-12 site settings go over agent endpoints, not env; bootstrap keys (APP_KEY, DB_*, CONTROL_PLANE_*) are refused. Redeploy to apply.',
                route: 'ops.sites.env.set',
                ability: McpToken::ABILITY_WRITE,
                properties: $site + ['key' => ['type' => 'string'], 'value' => ['type' => 'string']],
                required: ['site', 'key'],
                params: ['site' => 'site'],
            ),
            new RouteTool(
                name: 'delete_site_env',
                description: 'Delete one (non-bootstrap) Coolify env variable of the site.',
                route: 'ops.sites.env.destroy',
                ability: McpToken::ABILITY_WRITE,
                properties: $site + ['key' => ['type' => 'string']],
                required: ['site', 'key'],
                params: ['site' => 'site'],
            ),
            new RouteTool(
                name: 'sync_site_env',
                description: 'Bring the site\'s Coolify env in line with the CMS branch catalog (adds missing keys, prunes stale ones; bootstrap keys are kept).',
                route: 'ops.sites.env.fix',
                ability: McpToken::ABILITY_WRITE,
                properties: $site + ['channel' => ['type' => 'string', 'enum' => config('ops.channels', []), 'description' => 'Catalog branch; the site\'s channel if omitted.']],
                required: ['site'],
                params: ['site' => 'site'],
            ),
            new RouteTool(
                name: 'set_turnstile',
                description: 'Cloudflare Turnstile bot protection for the site\'s forms.',
                route: 'ops.sites.turnstile.update',
                ability: McpToken::ABILITY_WRITE,
                properties: $site + [
                    'enabled' => ['type' => 'boolean'],
                    'mode' => ['type' => 'string', 'enum' => SiteTurnstile::MODES],
                    'forms' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => SiteTurnstile::FORMS]],
                ],
                required: ['site', 'mode'],
                params: ['site' => 'site'],
            ),
            new RouteTool(
                name: 'sync_turnstile',
                description: 'Re-push the site\'s Turnstile widget (hostnames, keys) to Cloudflare and the CMS.',
                route: 'ops.sites.turnstile.sync',
                ability: McpToken::ABILITY_WRITE,
                properties: $site,
                required: ['site'],
                params: ['site' => 'site'],
            ),
            new RouteTool(
                name: 'assign_mail_server',
                description: 'Attach a mail server (Hostinger) to the site and configure CMS SMTP. Omit mail_server_id to detach.',
                route: 'ops.sites.mail',
                ability: McpToken::ABILITY_WRITE,
                properties: $site + [
                    'mail_server_id' => ['type' => 'string'],
                    'hostinger_order_ids' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Pick orders explicitly (with mail_bindings_explicit).'],
                    'mail_bindings_explicit' => ['type' => 'boolean'],
                ],
                required: ['site'],
                params: ['site' => 'site'],
            ),
            new RouteTool(
                name: 'resend_mail_configure',
                description: 'Push the mail (SMTP) configuration to the CMS again.',
                route: 'ops.sites.mail-configure',
                ability: McpToken::ABILITY_WRITE,
                properties: $site,
                required: ['site'],
                params: ['site' => 'site'],
            ),
            new RouteTool(
                name: 'set_platform_mail',
                description: 'Who receives Plane\'s platform notifications for this site, and which ones (keys from get_site / panel).',
                route: 'ops.sites.platform-mail',
                ability: McpToken::ABILITY_WRITE,
                properties: $site + [
                    'platform_mail_recipient' => ['type' => 'string', 'format' => 'email'],
                    'notifications' => ['type' => 'object', 'additionalProperties' => ['type' => 'boolean']],
                ],
                required: ['site'],
                params: ['site' => 'site'],
            ),
        ];
    }

    /**
     * @return list<RouteTool>
     */
    private static function admins(): array
    {
        $admin = ['site' => self::SITE, 'admin_id' => ['type' => 'integer', 'description' => 'From list_site_admins.']];
        $password = [
            'password_mode' => ['type' => 'string', 'enum' => ['generate', 'manual'], 'default' => 'generate'],
            'password' => ['type' => 'string', 'description' => 'manual only: 12+ chars, mixed case, number, symbol.'],
        ];

        return [
            new RouteTool(
                name: 'create_site_admin',
                description: 'Create a CMS admin on the site. `invite` (preferred) mails a set-password link; `generate` returns the password once in `once.admin_password_once` — hand it to the user, never store it.',
                route: 'ops.sites.admins.store',
                ability: McpToken::ABILITY_WRITE,
                properties: [
                    'site' => self::SITE,
                    'name' => ['type' => 'string'],
                    'email' => ['type' => 'string', 'format' => 'email'],
                    'password_mode' => ['type' => 'string', 'enum' => ['invite', 'generate', 'manual'], 'default' => 'invite'],
                    'password' => $password['password'],
                ],
                required: ['site', 'name', 'email'],
                params: ['site' => 'site'],
                prepare: static fn (array $body): array => $body + ['password_mode' => 'invite'],
            ),
            new RouteTool(
                name: 'reset_site_admin_password',
                description: 'Set a new password for a CMS admin (generated one comes back once in `once`).',
                route: 'ops.sites.admins.password',
                ability: McpToken::ABILITY_WRITE,
                properties: $admin + $password + ['admin_email' => ['type' => 'string', 'format' => 'email']],
                required: ['site', 'admin_id'],
                params: ['site' => 'site', 'remoteAdmin' => 'admin'],
            ),
            new RouteTool(
                name: 'send_site_admin_invite',
                description: 'Mail a CMS admin a set-password link again.',
                route: 'ops.sites.admins.password-invite',
                ability: McpToken::ABILITY_WRITE,
                properties: $admin + ['admin_email' => ['type' => 'string', 'format' => 'email']],
                required: ['site', 'admin_id'],
                params: ['site' => 'site', 'remoteAdmin' => 'admin'],
            ),
            new RouteTool(
                name: 'delete_site_admin',
                description: 'Delete a CMS admin (Super Admin). Ask the user first.',
                route: 'ops.sites.admins.destroy',
                ability: McpToken::ABILITY_DANGER,
                properties: $admin,
                required: ['site', 'admin_id'],
                params: ['site' => 'site', 'remoteAdmin' => 'admin'],
            ),
        ];
    }

    /**
     * CoMail (shared mailcow): packages and per-site mail domains.
     *
     * @return list<RouteTool>
     */
    private static function comail(): array
    {
        $site = ['site' => self::SITE];
        $domain = ['domain' => ['type' => 'string', 'description' => 'CoMail domain of the site (get_site lists them).']];
        $limits = [
            'name' => ['type' => 'string'],
            'slug' => ['type' => 'string', 'description' => 'Lowercase letters, digits, dashes.'],
            'description' => ['type' => 'string'],
            'mailbox_limit' => ['type' => 'integer', 'minimum' => 0],
            'storage_mb' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Total for the site across its domains.'],
            'mailbox_max_quota_mb' => ['type' => 'integer', 'minimum' => 1, 'description' => '<= storage_mb.'],
            'alias_limit' => ['type' => 'integer', 'minimum' => 0],
            'domain_limit' => ['type' => ['integer', 'null'], 'description' => 'null = any of the site\'s own domains.'],
            'price_monthly' => ['type' => ['number', 'null']],
            'currency' => ['type' => 'string', 'default' => 'TRY'],
            'is_active' => ['type' => 'boolean'],
            'is_default' => ['type' => 'boolean', 'description' => 'Exactly one default; setting it moves the default here.'],
            'sort' => ['type' => 'integer'],
        ];
        $package = ['package' => ['type' => 'string', 'description' => 'Package slug or id.']];

        return [
            new RouteTool(
                name: 'set_site_mail_package',
                description: 'Set the CoMail package of a site (limits are re-applied to its mailcow domains). Omit mail_package_id for the default package.',
                route: 'ops.sites.comail.package',
                ability: McpToken::ABILITY_WRITE,
                properties: $site + ['mail_package_id' => ['type' => 'string', 'description' => 'Package id (list via the panel /mail-packages).']],
                required: ['site'],
                params: ['site' => 'site'],
            ),
            new RouteTool(
                name: 'add_comail_domain',
                description: 'Add one of the site\'s own domains to CoMail (mailcow domain + DKIM + DNS on Plane-managed zones; existing MX is never replaced).',
                route: 'ops.sites.comail.domains.store',
                ability: McpToken::ABILITY_WRITE,
                properties: $site + ['domain' => ['type' => 'string']],
                required: ['site', 'domain'],
                params: ['site' => 'site'],
            ),
            new RouteTool(
                name: 'apply_comail_dns',
                description: 'Write the missing CoMail DNS records (DKIM, CNAMEs, DMARC, SRV; MX+SPF only when the domain has no MX). Cloudflare zones on Plane only.',
                route: 'ops.sites.comail.dns.apply',
                ability: McpToken::ABILITY_WRITE,
                properties: $site + $domain,
                required: ['site', 'domain'],
                params: ['site' => 'site', 'comailDomain' => 'comail_domain'],
            ),
            new RouteTool(
                name: 'check_comail_dns',
                description: 'Resolve the expected CoMail DNS records and store ok/missing/different per record.',
                route: 'ops.sites.comail.dns.check',
                ability: McpToken::ABILITY_WRITE,
                properties: $site + $domain,
                required: ['site', 'domain'],
                params: ['site' => 'site', 'comailDomain' => 'comail_domain'],
            ),
            new RouteTool(
                name: 'activate_comail_mx',
                description: 'Switch a domain\'s mail to CoMail: replaces its MX and SPF and points autodiscover/autoconfig to CoMail. Mail at the old provider stops arriving. Only on the user\'s explicit request.',
                route: 'ops.sites.comail.activate',
                ability: McpToken::ABILITY_DANGER,
                properties: $site + $domain + ['confirmed' => self::CONFIRMED],
                required: ['site', 'domain', 'confirmed'],
                params: ['site' => 'site', 'comailDomain' => 'comail_domain'],
            ),
            new RouteTool(
                name: 'resend_comail_configure',
                description: 'Push the CoMail configure (enable the E-posta module) to the CMS again.',
                route: 'ops.sites.comail.configure',
                ability: McpToken::ABILITY_WRITE,
                properties: $site,
                required: ['site'],
                params: ['site' => 'site'],
            ),
            new RouteTool(
                name: 'create_mail_package',
                description: 'Create a CoMail package (limits per site).',
                route: 'ops.mail-packages.store',
                ability: McpToken::ABILITY_WRITE,
                properties: $limits,
                required: ['name', 'slug', 'mailbox_limit', 'storage_mb', 'mailbox_max_quota_mb', 'alias_limit'],
                prepare: static fn (array $body): array => $body + ['currency' => 'TRY'],
            ),
            new RouteTool(
                name: 'update_mail_package',
                description: 'Change a CoMail package. Only the fields you pass change; sites using it get the new limits.',
                route: 'ops.mail-packages.update',
                ability: McpToken::ABILITY_WRITE,
                properties: $package + $limits,
                required: ['package'],
                params: ['mailPackage' => 'mail_package'],
                prepare: static fn (array $body, array $resolved): array => $body + $resolved['mailPackage']->only([
                    'name', 'slug', 'description', 'mailbox_limit', 'storage_mb', 'mailbox_max_quota_mb', 'alias_limit',
                    'domain_limit', 'price_monthly', 'currency', 'is_active', 'is_default', 'sort',
                ]),
            ),
            new RouteTool(
                name: 'delete_mail_package',
                description: 'Delete a CoMail package that no site uses (never the default). Ask the user first.',
                route: 'ops.mail-packages.destroy',
                ability: McpToken::ABILITY_DANGER,
                properties: $package,
                required: ['package'],
                params: ['mailPackage' => 'mail_package'],
            ),
        ];
    }

    /**
     * The create form pre-fills these; an agent that leaves them out gets the same.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private static function withCreateDefaults(array $body): array
    {
        $connection = filled($body['coolify_connection_id'] ?? null)
            ? CoolifyConnection::query()->find((int) $body['coolify_connection_id'])
            : CoolifyConnection::default();

        $gitSource = $connection?->default_git_source_kind && $connection->default_git_source_uuid
            ? $connection->default_git_source_kind->value.':'.$connection->default_git_source_uuid
            : null;

        $defaults = array_filter([
            'channel' => 'beta',
            'placement' => 'provision',
            'coolify_connection_id' => $connection?->id,
            'coolify_server_uuid' => $connection?->default_server_uuid ?: config('ops.coolify.default_server_uuid'),
            'coolify_project_uuid' => $connection?->default_project_uuid,
            'coolify_environment_uuid' => $connection?->default_environment_uuid,
            'coolify_git_source' => $gitSource,
            'cloudflare_setting_id' => CloudflareAccounts::default()?->id,
        ], static fn ($value): bool => filled($value));

        return self::withTagsSubmitted($body + $defaults);
    }

    /**
     * The edit form posts every field; a missing one would be cleared (aliases
     * dropped from DNS, mail server detached). Fill the gaps with what the site has.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private static function withCurrentSite(array $body, Site $site): array
    {
        $current = [
            'slug' => $site->slug,
            'name' => $site->name,
            'domain' => $site->primary_domain,
            'aliases' => $site->aliasHosts(),
            'channel' => $site->channel->value,
            'notes' => $site->notes,
            'mail_server_id' => $site->mail_server_id,
            'cloudflare_setting_id' => $site->cloudflare_setting_id,
            'coolify_connection_id' => $site->coolify_connection_id,
            'coolify_server_uuid' => $site->coolify_server_uuid,
            'coolify_project_uuid' => $site->coolify_project_uuid,
            'coolify_environment_uuid' => $site->coolify_environment_uuid,
            'coolify_git_source' => $site->coolify_git_source_kind && $site->coolify_git_source_uuid
                ? $site->coolify_git_source_kind->value.':'.$site->coolify_git_source_uuid
                : null,
        ];

        return self::withTagsSubmitted($body + $current);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private static function withCurrentTheme(array $body, Theme $theme): array
    {
        return $body + [
            'visibility' => $theme->visibility->value,
            'default_ref' => $theme->default_ref,
            'ci_gate' => (bool) $theme->ci_gate,
        ];
    }

    /**
     * Tags are only replaced when the caller sends them (the form's tags_submitted).
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private static function withTagsSubmitted(array $body): array
    {
        if (array_key_exists('tags', $body)) {
            $body['tags_submitted'] = true;
        }

        return $body;
    }
}
