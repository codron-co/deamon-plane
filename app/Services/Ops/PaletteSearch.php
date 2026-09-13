<?php

namespace App\Services\Ops;

use App\Models\CloudflareSetting;
use App\Models\CoolifyConnection;
use App\Models\MailServer;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\Theme;
use App\Models\User;
use App\Support\Ops\PaletteFilters;
use App\Support\Ops\SettingsJump;

/**
 * Quick-jump results for Ctrl/⌘+K. Resource hits reuse the list scopes so a
 * paste that finds a site on /sites finds the same site here, and a Viewer
 * only receives URLs those lists already authorize.
 */
class PaletteSearch
{
    public const LIMIT = 8;

    public function __construct(private User $user) {}

    /**
     * @return list<array{key: string, label: string, items: list<array{id: string, label: string, hint: ?string, url: string}>}>
     */
    public function search(string $q): array
    {
        $q = trim($q);
        $groups = [];

        $pages = $this->pages($q);
        if ($pages !== []) {
            $groups[] = $this->group('pages', $pages);
        }

        if ($q === '') {
            return $groups;
        }

        $filters = $this->filters($q);
        if ($filters !== []) {
            $groups[] = $this->group('filters', $filters);
        }

        $settings = $this->settings($q);
        if ($settings !== []) {
            $groups[] = $this->group('settings', $settings);
        }

        if ($this->user->can('viewAny', Site::class)) {
            $sites = $this->sites($q);
            if ($sites !== []) {
                $groups[] = $this->group('sites', $sites);
            }

            $domains = $this->domains($q);
            if ($domains !== []) {
                $groups[] = $this->group('domains', $domains);
            }
        }

        if ($this->user->can('viewAny', Theme::class)) {
            $themes = $this->themes($q);
            if ($themes !== []) {
                $groups[] = $this->group('themes', $themes);
            }
        }

        $cloudflare = $this->cloudflareAccounts($q);
        if ($cloudflare !== []) {
            $groups[] = $this->group('cloudflare', $cloudflare);
        }

        if ($this->user->can('viewAny', CoolifyConnection::class)) {
            $coolify = $this->coolifyConnections($q);
            if ($coolify !== []) {
                $groups[] = $this->group('coolify', $coolify);
            }
        }

        $mail = $this->mailServers($q);
        if ($mail !== []) {
            $groups[] = $this->group('mail_servers', $mail);
        }

        return $groups;
    }

    /**
     * @param  list<array{id: string, label: string, hint: ?string, url: string}>  $items
     * @return array{key: string, label: string, items: list<array{id: string, label: string, hint: ?string, url: string}>}
     */
    private function group(string $key, array $items): array
    {
        return [
            'key' => $key,
            'label' => __('ops.palette.groups.'.$key),
            'items' => $items,
        ];
    }

    /**
     * @return list<array{id: string, label: string, hint: ?string, url: string}>
     */
    private function pages(string $q): array
    {
        $catalog = [
            ['key' => 'fleet', 'route' => 'ops.fleet', 'needles' => ['fleet', 'filo', 'özet', 'ozet', 'overview', 'dashboard', 'kpi']],
            ['key' => 'activity', 'route' => 'ops.activity', 'needles' => ['activity', 'geçmiş', 'gecmis', 'history', 'audit', 'jobs', 'log']],
            ['key' => 'sites', 'route' => 'ops.sites', 'needles' => ['sites', 'siteler']],
            ['key' => 'domains', 'route' => 'ops.domains', 'needles' => ['domains', 'domainler']],
            ['key' => 'coolify', 'route' => 'ops.coolify.index', 'needles' => ['coolify']],
            ['key' => 'cloudflare', 'route' => 'ops.cloudflare.index', 'needles' => ['cloudflare', 'cf', 'dns']],
            ['key' => 'mail_servers', 'route' => 'ops.mail-servers.index', 'needles' => ['mail', 'posta', 'smtp', 'hostinger']],
            ['key' => 'platform_mail', 'route' => 'ops.platform-mail.edit', 'needles' => ['mail', 'posta', 'smtp', 'yazılım', 'yazilim', 'bildirim']],
            ['key' => 'themes', 'route' => 'ops.themes', 'needles' => ['themes', 'temalar', 'tema']],
            ['key' => 'settings', 'route' => 'ops.settings', 'needles' => ['settings', 'ayarlar', 'env', 'ortam']],
        ];

        $items = [];
        foreach ($catalog as $page) {
            $label = (string) __('ops.nav.'.$page['key']);
            if ($q !== '' && ! $this->pageMatches($q, $label, $page['key'], $page['needles'])) {
                continue;
            }

            $items[] = [
                'id' => 'page-'.$page['key'],
                'label' => $label,
                'hint' => null,
                'url' => route($page['route']),
            ];
        }

        return $items;
    }

    /**
     * @param  list<string>  $needles
     */
    private function pageMatches(string $q, string $label, string $key, array $needles): bool
    {
        foreach (array_merge([$label, $key], $needles) as $haystack) {
            if (mb_stripos((string) $haystack, $q) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{id: string, label: string, hint: ?string, url: string}>
     */
    private function filters(string $q): array
    {
        if (! $this->user->can('viewAny', Site::class)) {
            return [];
        }

        $items = [];
        foreach (PaletteFilters::matching($q) as $filter) {
            $items[] = [
                'id' => 'filter-'.$filter['id'],
                'label' => $filter['label'],
                'hint' => $filter['hint'],
                'url' => $filter['url'],
            ];
        }

        return $items;
    }

    /**
     * @return list<array{id: string, label: string, hint: ?string, url: string}>
     */
    private function settings(string $q): array
    {
        $items = [];
        foreach (SettingsJump::matching($q) as $section) {
            $items[] = [
                'id' => 'settings-'.$section['id'],
                'label' => $section['label'],
                'hint' => (string) __('settings.title'),
                'url' => route('ops.settings').'#'.$section['hash'],
            ];
        }

        return $items;
    }

    /**
     * @return list<array{id: string, label: string, hint: ?string, url: string}>
     */
    private function sites(string $q): array
    {
        $sites = Site::query()
            ->with('domains')
            ->matchingListFilters($q)
            ->orderBy('name')
            ->limit(self::LIMIT)
            ->get();

        return $sites->map(function (Site $site) use ($q): array {
            $reason = $site->searchMatchReason($q);

            return [
                'id' => 'site-'.$site->id,
                'label' => (string) $site->name,
                'hint' => $reason['value'] ?? (filled($site->primary_domain) ? (string) $site->primary_domain : null),
                'url' => route('ops.sites.show', $site),
            ];
        })->all();
    }

    /**
     * @return list<array{id: string, label: string, hint: ?string, url: string}>
     */
    private function domains(string $q): array
    {
        $domains = SiteDomain::query()
            ->with('site:id,name,slug')
            ->matchingListFilters($q, false)
            ->orderBy('domain')
            ->limit(self::LIMIT)
            ->get();

        return $domains->map(function (SiteDomain $domain): array {
            $site = $domain->site;

            return [
                'id' => 'domain-'.$domain->id,
                'label' => (string) $domain->domain,
                'hint' => $site?->name ?? (string) __('ops.palette.unbound'),
                'url' => $site instanceof Site
                    ? route('ops.sites.show', $site)
                    : route('ops.domains', ['q' => $domain->domain]),
            ];
        })->all();
    }

    /**
     * @return list<array{id: string, label: string, hint: ?string, url: string}>
     */
    private function themes(string $q): array
    {
        $themes = Theme::query()
            ->matchingListFilters($q)
            ->orderBy('theme_id')
            ->limit(self::LIMIT)
            ->get();

        return $themes->map(static function (Theme $theme): array {
            return [
                'id' => 'theme-'.$theme->theme_id,
                'label' => $theme->displayName(),
                'hint' => filled($theme->repo_full_name) ? (string) $theme->repo_full_name : null,
                'url' => route('ops.themes.show', $theme),
            ];
        })->all();
    }

    /**
     * @return list<array{id: string, label: string, hint: ?string, url: string}>
     */
    private function cloudflareAccounts(string $q): array
    {
        $term = addcslashes($q, '%_\\');
        $accounts = CloudflareSetting::query()
            ->where(function ($builder) use ($term, $q): void {
                $builder->where('name', 'like', "%{$term}%")
                    ->orWhere('account_id', $q)
                    ->orWhere('wildcard_domain', 'like', "%{$term}%");
            })
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->limit(self::LIMIT)
            ->get();

        return $accounts->map(static function (CloudflareSetting $account): array {
            return [
                'id' => 'cloudflare-'.$account->id,
                'label' => (string) $account->name,
                'hint' => filled($account->account_id) ? (string) $account->account_id : null,
                'url' => route('ops.cloudflare.show', $account),
            ];
        })->all();
    }

    /**
     * @return list<array{id: string, label: string, hint: ?string, url: string}>
     */
    private function coolifyConnections(string $q): array
    {
        $term = addcslashes($q, '%_\\');
        $connections = CoolifyConnection::query()
            ->where(function ($builder) use ($term, $q): void {
                $builder->where('name', 'like', "%{$term}%")
                    ->orWhere('base_url', 'like', "%{$term}%")
                    ->orWhere('default_server_uuid', $q)
                    ->orWhere('default_project_uuid', $q)
                    ->orWhere('default_environment_uuid', $q);
            })
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->limit(self::LIMIT)
            ->get();

        return $connections->map(static function (CoolifyConnection $connection): array {
            return [
                'id' => 'coolify-'.$connection->id,
                'label' => (string) $connection->name,
                'hint' => filled($connection->base_url) ? (string) $connection->base_url : null,
                'url' => route('ops.coolify.show', $connection),
            ];
        })->all();
    }

    /**
     * @return list<array{id: string, label: string, hint: ?string, url: string}>
     */
    private function mailServers(string $q): array
    {
        $term = addcslashes($q, '%_\\');
        $servers = MailServer::query()
            ->where(function ($builder) use ($term): void {
                $builder->where('name', 'like', "%{$term}%")
                    ->orWhere('mail_domain', 'like', "%{$term}%");
            })
            ->orderBy('name')
            ->limit(self::LIMIT)
            ->get();

        return $servers->map(static function (MailServer $server): array {
            return [
                'id' => 'mail-'.$server->id,
                'label' => (string) $server->name,
                'hint' => filled($server->mail_domain) ? (string) $server->mail_domain : null,
                'url' => route('ops.mail-servers.show', $server),
            ];
        })->all();
    }
}
