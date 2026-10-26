<?php

namespace App\Mcp;

use App\Models\CloudflareSetting;
use App\Models\Deployment;
use App\Models\FleetRollout;
use App\Models\MailPackage;
use App\Models\Site;
use App\Models\SiteComailDomain;
use App\Models\SiteDomain;
use App\Models\SiteThemeInstallation;
use App\Models\Theme;
use App\Services\Cloudflare\CloudflareAccounts;
use Illuminate\Support\Str;

/**
 * Agents name things the way people do: a site by slug, domain or id, a theme
 * install by its theme id. Each lookup fails with a message the agent can act on.
 */
final class McpModels
{
    public static function site(mixed $ref, bool $withTrashed = false): Site
    {
        $ref = strtolower(trim((string) $ref));
        if ($ref === '') {
            throw new McpException('`site` is required: the site slug, domain or id.', 'validation_error');
        }

        $query = fn () => $withTrashed ? Site::withTrashed() : Site::query();
        $host = self::host($ref);

        $site = (Str::isUlid($ref) ? $query()->whereKey(strtoupper($ref))->first() : null)
            ?? $query()->where('slug', $ref)->first()
            ?? $query()->where('primary_domain', $host)->first()
            ?? $query()->whereHas('domains', fn ($domains) => $domains->where('domain', $host))->first();

        if (! $site instanceof Site) {
            throw new McpException("No site matches `{$ref}`. Use list_sites to find it.", 'not_found');
        }

        return $site;
    }

    public static function installation(Site $site, mixed $themeId): SiteThemeInstallation
    {
        // installations.theme_id is the theme row's ULID; agents pass the catalog code.
        $ref = trim((string) $themeId);
        $installation = $site->themeInstallations()
            ->where(fn ($query) => $query
                ->whereHas('theme', fn ($themes) => $themes->where('themes.theme_id', $ref))
                ->orWhere('site_theme_installations.id', $ref))
            ->first();

        if (! $installation instanceof SiteThemeInstallation) {
            $installed = $site->themeInstallations()->with('theme')->get()->pluck('theme.theme_id')->filter()->implode(', ');

            throw new McpException(
                "Theme `{$ref}` is not installed on {$site->slug}.".($installed !== '' ? " Installed: {$installed}." : ' Use assign_theme first.'),
                'not_found',
            );
        }

        return $installation;
    }

    public static function siteDomain(Site $site, mixed $host): SiteDomain
    {
        $host = self::host((string) $host);
        $domain = $site->domains()->where('domain', $host)->first();

        if (! $domain instanceof SiteDomain) {
            throw new McpException(
                "`{$host}` is not a domain of {$site->slug}. Domains: ".implode(', ', $site->operatorHosts()).'.',
                'not_found',
            );
        }

        return $domain;
    }

    public static function deployment(Site $site, mixed $id): Deployment
    {
        $deployment = $site->deployments()
            ->where(fn ($query) => $query->whereKey($id)->orWhere('coolify_deployment_uuid', (string) $id))
            ->first();

        if (! $deployment instanceof Deployment) {
            throw new McpException("Deployment `{$id}` not found on {$site->slug}. get_site lists recent deployments.", 'not_found');
        }

        return $deployment;
    }

    public static function theme(mixed $themeId): Theme
    {
        $theme = Theme::query()->where('theme_id', trim((string) $themeId))->first();

        if (! $theme instanceof Theme) {
            throw new McpException("Theme `{$themeId}` is not in the catalog. Use list_themes (or sync_theme_catalog).", 'not_found');
        }

        return $theme;
    }

    /**
     * An account id or name; empty means the default Cloudflare account.
     */
    public static function cloudflareAccount(mixed $ref): CloudflareSetting
    {
        $ref = trim((string) $ref);
        $account = $ref === ''
            ? CloudflareAccounts::default()
            : CloudflareSetting::query()
                ->where(fn ($query) => ctype_digit($ref) ? $query->whereKey((int) $ref) : $query->where('name', $ref))
                ->first();

        if (! $account instanceof CloudflareSetting) {
            throw new McpException(
                $ref === '' ? 'No default Cloudflare account. Pass `account` (see get_setup_options).' : "No Cloudflare account matches `{$ref}`.",
                'not_found',
            );
        }

        return $account;
    }

    public static function comailDomain(Site $site, mixed $host): SiteComailDomain
    {
        $host = self::host((string) $host);
        $row = $site->comailDomains()->where('domain', $host)->first();

        if (! $row instanceof SiteComailDomain) {
            $known = $site->comailDomains()->pluck('domain')->implode(', ');

            throw new McpException(
                "`{$host}` is not a CoMail domain of {$site->slug}.".($known !== '' ? " CoMail domains: {$known}." : ' Add it with add_comail_domain.'),
                'not_found',
            );
        }

        return $row;
    }

    /**
     * A package id or slug.
     */
    public static function mailPackage(mixed $ref): MailPackage
    {
        $ref = trim((string) $ref);
        $package = MailPackage::query()->where('slug', $ref)->orWhere('id', $ref)->first();

        if (! $package instanceof MailPackage) {
            throw new McpException("No mail package `{$ref}`. Packages: ".MailPackage::query()->orderBy('sort')->pluck('slug')->implode(', ').'.', 'not_found');
        }

        return $package;
    }

    public static function rollout(mixed $id): FleetRollout
    {
        $rollout = FleetRollout::query()->find((int) $id);

        if (! $rollout instanceof FleetRollout) {
            throw new McpException("Fleet rollout `{$id}` not found.", 'not_found');
        }

        return $rollout;
    }

    public static function host(string $value): string
    {
        $value = strtolower(trim($value));
        $value = (string) preg_replace('#^https?://#', '', $value);

        return rtrim(explode('/', $value, 2)[0], '.');
    }
}
