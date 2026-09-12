<?php

namespace App\Support\Lists;

use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Resolves "which columns and which sort" for the Sites table from the request
 * query and the operator's stored preferences, in that order of precedence.
 */
final class SiteListView
{
    public const DIRECTIONS = ['asc', 'desc'];

    /**
     * @param  list<string>  $columns
     */
    private function __construct(
        public readonly array $columns,
        public readonly string $sortKey,
        public readonly string $sortDirection,
        private readonly bool $sortFromQuery,
    ) {}

    public static function resolve(Request $request, ?User $user): self
    {
        $stored = $user?->listPreference(SiteListColumns::LIST_KEY) ?? [];
        $columns = SiteListColumns::sanitize($stored['columns'] ?? SiteListColumns::defaults());

        $queryKey = (string) $request->query('sort', '');
        $queryDirection = (string) $request->query('dir', '');
        $fromQuery = self::isUsable($queryKey, $columns);

        if ($fromQuery) {
            $key = $queryKey;
            $direction = self::direction($queryDirection);
        } else {
            $storedSort = is_array($stored['sort'] ?? null) ? $stored['sort'] : [];
            $storedKey = (string) ($storedSort['key'] ?? '');
            $storedIsUsable = self::isUsable($storedKey, $columns);
            $key = $storedIsUsable ? $storedKey : SiteListColumns::DEFAULT_SORT_KEY;
            $direction = $storedIsUsable
                ? self::direction((string) ($storedSort['dir'] ?? ''))
                : SiteListColumns::DEFAULT_SORT_DIRECTION;
        }

        // Falling back to the default column must also drop the direction that
        // belonged to the discarded one, or a hidden "publish desc" would silently
        // become "site desc".
        if (! self::isUsable($key, $columns)) {
            $key = SiteListColumns::DEFAULT_SORT_KEY;
            $direction = SiteListColumns::DEFAULT_SORT_DIRECTION;
        }

        return new self($columns, $key, $direction, $fromQuery);
    }

    public function shows(string $key): bool
    {
        return in_array($key, $this->columns, true);
    }

    public function isSortedBy(string $key): bool
    {
        return $this->sortKey === $key;
    }

    /**
     * @return 'ascending'|'descending'|'none'
     */
    public function ariaSort(string $key): string
    {
        if (! $this->isSortedBy($key)) {
            return 'none';
        }

        return $this->sortDirection === 'desc' ? 'descending' : 'ascending';
    }

    /**
     * Clicking the active header flips it; clicking any other starts ascending.
     */
    public function nextDirectionFor(string $key): string
    {
        return $this->isSortedBy($key) && $this->sortDirection === 'asc' ? 'desc' : 'asc';
    }

    /**
     * @param  Builder<Site>  $query
     * @return Builder<Site>
     */
    public function applySort(Builder $query): Builder
    {
        $column = SiteListColumns::sortColumn($this->sortKey) ?? 'name';
        $query->orderBy($column, $this->sortDirection);

        // Stable tiebreak, so paginating a sort with ties cannot repeat or drop rows.
        if ($column !== 'name') {
            $query->orderBy('name');
        }

        return $query;
    }

    /**
     * Persists a sort the operator just chose from the headers, so landing on
     * /sites with no query string restores it next time.
     */
    public function rememberSort(?User $user): void
    {
        if ($user === null || ! $this->sortFromQuery) {
            return;
        }

        $stored = is_array($user->listPreference(SiteListColumns::LIST_KEY)['sort'] ?? null)
            ? $user->listPreference(SiteListColumns::LIST_KEY)['sort']
            : [];

        if (($stored['key'] ?? null) === $this->sortKey && ($stored['dir'] ?? null) === $this->sortDirection) {
            return;
        }

        $user->saveListPreference(SiteListColumns::LIST_KEY, [
            'sort' => ['key' => $this->sortKey, 'dir' => $this->sortDirection],
        ]);
    }

    /**
     * @param  list<string>  $columns
     */
    private static function isUsable(string $key, array $columns): bool
    {
        return $key !== ''
            && SiteListColumns::isSortable($key)
            && in_array($key, $columns, true);
    }

    private static function direction(string $direction): string
    {
        return in_array($direction, self::DIRECTIONS, true)
            ? $direction
            : SiteListColumns::DEFAULT_SORT_DIRECTION;
    }
}
