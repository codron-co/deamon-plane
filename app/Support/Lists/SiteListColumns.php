<?php

namespace App\Support\Lists;

/**
 * Catalogue of the Sites table columns an operator may show, hide and sort by.
 *
 * `sort` is the SQL column behind a header click; `null` means the cell is
 * computed at render time (app health, theme resolution) and the header renders
 * as plain text rather than a link that cannot deliver.
 */
final class SiteListColumns
{
    public const LIST_KEY = 'sites';

    /** Carries the row name, slug and identity mark — hiding it leaves nothing to click. */
    public const LOCKED = ['site'];

    public const DEFAULT_SORT_KEY = 'site';

    public const DEFAULT_SORT_DIRECTION = 'asc';

    /**
     * @var array<string, array{sort: ?string, default: bool}>
     */
    private const CATALOG = [
        'site' => ['sort' => 'name', 'default' => true],
        'domain' => ['sort' => 'primary_domain', 'default' => true],
        'repo_branch' => ['sort' => 'channel', 'default' => true],
        'publish' => ['sort' => 'cms_site_status', 'default' => true],
        'status' => ['sort' => 'status', 'default' => true],
        'app' => ['sort' => null, 'default' => true],
        'live' => ['sort' => 'last_live_http_status', 'default' => true],
        'theme' => ['sort' => null, 'default' => true],
        'health' => ['sort' => 'last_health_at', 'default' => false],
        'updated' => ['sort' => 'updated_at', 'default' => false],
    ];

    /**
     * Catalogue order is render order.
     *
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::CATALOG);
    }

    /**
     * @return list<string>
     */
    public static function defaults(): array
    {
        return array_keys(array_filter(
            self::CATALOG,
            static fn (array $column): bool => $column['default'],
        ));
    }

    public static function exists(string $key): bool
    {
        return array_key_exists($key, self::CATALOG);
    }

    public static function isLocked(string $key): bool
    {
        return in_array($key, self::LOCKED, true);
    }

    public static function isSortable(string $key): bool
    {
        return self::sortColumn($key) !== null;
    }

    public static function sortColumn(string $key): ?string
    {
        return self::CATALOG[$key]['sort'] ?? null;
    }

    public static function label(string $key): string
    {
        return (string) __('sites.columns.'.$key);
    }

    /**
     * Drops unknown keys, forces the locked ones back in, and restores catalogue
     * order so a hand-edited payload cannot produce an empty or shuffled table.
     *
     * @param  iterable<mixed>  $keys
     * @return list<string>
     */
    public static function sanitize(iterable $keys): array
    {
        $requested = [];
        foreach ($keys as $key) {
            if (is_string($key) && self::exists($key)) {
                $requested[$key] = true;
            }
        }

        if ($requested === []) {
            return self::defaults();
        }

        foreach (self::LOCKED as $locked) {
            $requested[$locked] = true;
        }

        return array_values(array_filter(
            self::keys(),
            static fn (string $key): bool => isset($requested[$key]),
        ));
    }
}
