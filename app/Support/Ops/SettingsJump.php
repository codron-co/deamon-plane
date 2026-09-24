<?php

namespace App\Support\Ops;

/**
 * In-page Settings sections and the palette hashes that open them.
 * Needles stay ASCII plus the operator words already on the page so
 * Ctrl/⌘+K "webhook" / "env" land on Sistem, not a second Settings route.
 */
final class SettingsJump
{
    /**
     * @return list<array{id: string, hash: string, label: string, needles: list<string>}>
     */
    public static function sections(): array
    {
        return [
            [
                'id' => 'env',
                'hash' => 'env-defaults-heading',
                'label' => __('settings.env.title'),
                'needles' => ['env', 'ortam', 'defaults', 'katalog', 'catalog', 'branch', 'dal', 'main', 'beta', 'alpha', 'app_key'],
            ],
            [
                'id' => 'deamon_git',
                'hash' => 'deamon-git-heading',
                'label' => __('settings.deamon_git.title'),
                'needles' => ['deamon', 'git', 'cms', 'github', 'pat', 'installation', 'katalog', 'catalog'],
            ],
            [
                'id' => 'github',
                'hash' => 'github-connection-heading',
                'label' => __('settings.github.title'),
                'needles' => ['github', 'webhook', 'tema', 'theme', 'katalog', 'catalog'],
            ],
            [
                'id' => 'defaults',
                'hash' => 'customer-defaults-heading',
                'label' => __('settings.defaults'),
                'needles' => ['git', 'repository', 'repo', 'compose', 'müşteri', 'musteri', 'customer'],
            ],
            [
                'id' => 'automation',
                'hash' => 'automation-heading',
                'label' => __('settings.automation.title'),
                'needles' => ['otomasyon', 'automation', 'auto', 'kill', 'switch', 'bütçe', 'budget', 'fix', 'restart', 'rebind'],
            ],
            [
                'id' => 'coolify',
                'hash' => 'system-coolify-heading',
                'label' => __('settings.coolify.title'),
                'needles' => ['coolify', 'token', 'api', 'sunucu', 'inventory'],
            ],
        ];
    }

    /**
     * Empty query is the full jump nav. A typed query keeps only hits.
     *
     * @return list<array{id: string, hash: string, label: string, needles: list<string>}>
     */
    public static function matching(string $q): array
    {
        $q = trim($q);
        if ($q === '') {
            return self::sections();
        }

        return array_values(array_filter(
            self::sections(),
            static fn (array $section): bool => self::matches($q, $section),
        ));
    }

    /**
     * @param  array{id: string, hash: string, label: string, needles: list<string>}  $section
     */
    public static function matches(string $q, array $section): bool
    {
        foreach (array_merge([$section['label'], $section['id']], $section['needles']) as $haystack) {
            if (mb_stripos((string) $haystack, $q) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array{id: string, hash: string, label: string, needles: list<string>}  $section
     */
    public static function haystack(array $section, string $extra = ''): string
    {
        $parts = array_merge([$section['label'], $section['id']], $section['needles']);
        if ($extra !== '') {
            $parts[] = $extra;
        }

        return trim(implode(' ', $parts));
    }

    public static function haystackFor(string $id, string $extra = ''): string
    {
        foreach (self::sections() as $section) {
            if ($section['id'] === $id) {
                return self::haystack($section, $extra);
            }
        }

        return $extra;
    }
}
