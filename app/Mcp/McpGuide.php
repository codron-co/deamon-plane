<?php

namespace App\Mcp;

/**
 * What the agent reads on connect (server instructions) and the prompts it can
 * pull. Kept short: the tool descriptions carry the details.
 */
final class McpGuide
{
    public static function instructions(McpContext $context): string
    {
        $abilities = implode(', ', (array) $context->token->abilities);
        $role = $context->user->opsRole()->value ?? 'none';

        return <<<TEXT
            Deamon Plane: the control plane for CodRon's Deamon CMS sites (Coolify hosting, Cloudflare DNS, theme catalog, signed site agent).
            You act as {$context->user->email} (role {$role}; token abilities {$abilities}). Every write is audited under that user; the user's role still decides what is allowed.

            Rules:
            - Try every new change on the test site first (slug `test`, https://deamon.testdomain.tr): theme updates, settings pushes, bulk actions. Move on to customer sites only when it works there.
            - The sites share one host whose CPU is capped. Batch deploys (bulk_sites) instead of firing many single deploys; never start a new deploy while list_jobs shows the host's builds still running.
            - Ask the user before anything that takes a site offline or deletes (deactivate, archive, purge, DNS delete, admin delete, theme overwrite/rollback). Never pass `confirmed: true` the user did not agree to.
            - Most writes start background work. Read the result: `job.id` -> list_jobs job_id; deploys -> get_deployment; then get_site.
            - Never write secrets you receive (generated admin passwords) anywhere except the reply to the user.

            New site: get_setup_options -> create_site -> provision_site -> wait for get_site status active (get_deployment for the build) -> give the user the Cloudflare nameservers -> assign_theme (activate, sync) -> create_site_admin (invite) -> set_publish_status published when the user says so -> confirm_site_dns once the customer switched nameservers. The prompt `launch_site` has the full checklist.
            TEXT;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function prompts(): array
    {
        return [[
            'name' => 'launch_site',
            'title' => 'Launch a new Deamon site',
            'description' => 'Step-by-step checklist to open a new customer site from Plane.',
            'arguments' => [
                ['name' => 'domain', 'description' => 'Primary domain, e.g. example.com', 'required' => true],
                ['name' => 'name', 'description' => 'Site display name', 'required' => true],
                ['name' => 'theme_id', 'description' => 'Catalog theme to install', 'required' => false],
            ],
        ]];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{description: string, messages: list<array<string, mixed>>}
     */
    public static function prompt(string $name, array $arguments): array
    {
        if ($name !== 'launch_site') {
            throw new McpException("Unknown prompt `{$name}`.", 'not_found');
        }

        $domain = trim((string) ($arguments['domain'] ?? '')) ?: '<domain>';
        $siteName = trim((string) ($arguments['name'] ?? '')) ?: '<name>';
        $theme = trim((string) ($arguments['theme_id'] ?? '')) ?: '<theme_id from list_themes>';

        $text = <<<TEXT
            Open the Deamon site "{$siteName}" on {$domain} through Plane. Report each step's result to me before the next.

            1. whoami, get_setup_options. Check list_sites that {$domain} is not used yet.
            2. list_themes (sync_theme_catalog first if the theme was pushed recently). Confirm {$theme} exists and its minimum CMS version.
            3. create_site: slug from the name, name "{$siteName}", domain {$domain}, channel beta (every customer site runs on beta) unless I say otherwise. Defaults for Coolify and Cloudflare.
            4. provision_site. Poll get_site until status is active (or error: list_site_activity, fix, provision again). get_deployment shows the build.
            5. Tell me the Cloudflare nameservers from get_site.cloudflare.nameservers; the customer sets them at the registrar. Until then the site answers on its temporary host.
            6. check_site_health, then assign_theme {$theme} with activate and sync true. Poll get_site.themes until installed and active.
            7. create_site_admin for the customer (password_mode invite) when I give the e-mail.
            8. set_turnstile (interaction-only, all forms) and assign_mail_server if I ask for mail.
            9. Keep the site as draft (maintenance page) until I say publish; then set_publish_status published.
            10. When I say the nameservers were changed: confirm_site_dns (repeat later if not active yet), then check_site_live.
            TEXT;

        return [
            'description' => "Launch {$siteName} ({$domain})",
            'messages' => [[
                'role' => 'user',
                'content' => ['type' => 'text', 'text' => $text],
            ]],
        ];
    }
}
