<?php

namespace App\Support\Lists;

use App\Enums\CmsPublishStatus;
use App\Enums\SiteStatus;
use App\Models\Site;
use App\Models\User;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Built-in and operator-named Sites list presets. Filters, columns and sort
 * live in `users.list_preferences` under the same `sites` key as the column
 * picker — database, not localStorage.
 */
final class SiteSavedViews
{
    public const MAX = 5;

    public const ALL = 'all';

    public const FILTER_KEYS = ['q', 'channel', 'status', 'publish', 'deploy', 'agent', 'pack', 'health', 'app', 'theme', 'theme_id', 'cms', 'auto_deploy', 'server', 'stale'];

    /**
     * Filter key => Site::scopeMatchingListFilters() argument name, where they differ.
     *
     * @var array<string, string>
     */
    private const SCOPE_ARGUMENTS = [
        'q' => 'search',
        'theme_id' => 'themeId',
        'auto_deploy' => 'autoDeploy',
    ];

    /** @var list<string> */
    public const BUILTIN_IDS = ['all', 'error', 'unpublished', 'dockerfile'];

    /**
     * @param  array<string, string>  $filters
     * @param  list<array{id: string, name: string, filters: array<string, string>, columns: list<string>, sort: array{key: string, dir: string}}>  $saved
     * @param  array{key: string, dir: string}|null  $sort
     */
    public function __construct(
        public readonly array $filters,
        public readonly array $saved,
        public readonly ?string $activeId,
        public readonly ?string $defaultId,
        public readonly bool $applyDefaultRedirect,
        public readonly ?array $sort = null,
    ) {}

    public static function resolve(Request $request, ?User $user): self
    {
        $stored = $user?->listPreference(SiteListColumns::LIST_KEY) ?? [];
        $saved = self::sanitizeSaved($stored['views'] ?? []);
        $defaultId = self::usableDefault(isset($stored['default_view']) ? (string) $stored['default_view'] : null, $saved);

        $queryView = trim((string) $request->query('view', ''));
        $queryFilters = self::sanitizeFilters([
            'q' => trim((string) $request->query('q', '')),
            'channel' => (string) $request->query('channel', ''),
            'status' => (string) $request->query('status', ''),
            'publish' => (string) $request->query('publish', ''),
            'deploy' => (string) $request->query('deploy', ''),
            'agent' => (string) $request->query('agent', ''),
            'pack' => (string) $request->query('pack', ''),
            'health' => (string) $request->query('health', ''),
            'app' => (string) $request->query('app', ''),
            'theme' => (string) $request->query('theme', ''),
            'theme_id' => (string) $request->query('theme_id', ''),
            'cms' => (string) $request->query('cms', ''),
            'auto_deploy' => (string) $request->query('auto_deploy', ''),
            'server' => (string) $request->query('server', ''),
            'stale' => (string) $request->query('stale', ''),
        ]);

        if ($queryView === self::ALL) {
            return new self($queryFilters, $saved, self::ALL, $defaultId, false);
        }

        $savedMatch = self::findSaved($saved, $queryView);
        if ($savedMatch !== null) {
            $filters = self::requestHasFilterKeys($request) ? $queryFilters : $savedMatch['filters'];

            return new self(
                $filters,
                $saved,
                $savedMatch['id'],
                $defaultId,
                false,
                $savedMatch['sort'],
            );
        }

        if (in_array($queryView, ['error', 'unpublished', 'dockerfile'], true)) {
            $filters = self::requestHasFilterKeys($request)
                ? $queryFilters
                : self::builtinFilters($queryView);

            return new self($filters, $saved, $queryView, $defaultId, false);
        }

        if (! self::hasListQuery($request) && $defaultId !== null) {
            $match = self::findSaved($saved, $defaultId);
            if ($match !== null) {
                return new self(
                    $match['filters'],
                    $saved,
                    $match['id'],
                    $defaultId,
                    ! ListFragment::wanted($request),
                    $match['sort'],
                );
            }
        }

        return new self(
            $queryFilters,
            $saved,
            self::matchingBuiltinId($queryFilters),
            $defaultId,
            false,
        );
    }

    /**
     * First apply of a named view writes its snapshot into the live layout so
     * the column picker (outside the region) matches the table. Later picker
     * saves win until a different view is applied.
     */
    public function rememberColumns(?User $user): void
    {
        if ($user === null) {
            return;
        }

        $active = self::findSaved($this->saved, (string) $this->activeId);
        $stored = $user->listPreference(SiteListColumns::LIST_KEY);
        $applied = isset($stored['applied_view']) ? (string) $stored['applied_view'] : '';

        if ($active === null) {
            if ($applied !== '') {
                $user->saveListPreference(SiteListColumns::LIST_KEY, [
                    'applied_view' => null,
                ]);
            }

            return;
        }

        if ($applied === $active['id']) {
            return;
        }

        $user->saveListPreference(SiteListColumns::LIST_KEY, [
            'columns' => $active['columns'],
            'sort' => $active['sort'],
            'applied_view' => $active['id'],
        ]);
    }

    public function redirectUrl(): string
    {
        return route('ops.sites', $this->queryParameters());
    }

    /**
     * @return array<string, string>
     */
    public function queryParameters(): array
    {
        $query = $this->filters;
        if ($this->activeId !== null && $this->activeId !== '') {
            $query['view'] = $this->activeId;
        }
        if ($this->sort !== null) {
            $query['sort'] = $this->sort['key'];
            $query['dir'] = $this->sort['dir'];
        }

        return array_filter($query, static fn (string $value): bool => $value !== '');
    }

    /**
     * @return list<array{id: string, name: string, url: string, active: bool, default: bool, saved: bool}>
     */
    public function chips(): array
    {
        $chips = [];
        foreach (self::BUILTIN_IDS as $id) {
            $chips[] = [
                'id' => $id,
                'name' => (string) __('sites.saved_views.'.$id),
                'url' => route('ops.sites', self::builtinQuery($id)),
                'active' => $this->activeId === $id,
                'default' => $this->defaultId === $id,
                'saved' => false,
            ];
        }

        foreach ($this->saved as $view) {
            $chips[] = [
                'id' => $view['id'],
                'name' => $view['name'],
                'url' => route('ops.sites', self::savedQuery($view)),
                'active' => $this->activeId === $view['id'],
                'default' => $this->defaultId === $view['id'],
                'saved' => true,
            ];
        }

        return $chips;
    }

    public function atLimit(): bool
    {
        return count($this->saved) >= self::MAX;
    }

    public static function clearUrl(): string
    {
        return route('ops.sites', ['view' => self::ALL]);
    }

    /**
     * @return array<string, string>
     */
    public static function builtinQuery(string $id): array
    {
        return match ($id) {
            'error' => ['view' => 'error', 'status' => SiteStatus::Error->value],
            'unpublished' => ['view' => 'unpublished', 'publish' => CmsPublishStatus::Draft->value],
            'dockerfile' => ['view' => 'dockerfile', 'pack' => 'dockerfile'],
            default => ['view' => self::ALL],
        };
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, string>
     */
    public static function sanitizeFilters(array $raw): array
    {
        $allowedChannels = config('ops.channels', []);
        $publishFilters = [...CmsPublishStatus::values(), 'unknown'];

        $q = trim((string) ($raw['q'] ?? ''));
        $channel = (string) ($raw['channel'] ?? '');
        $status = (string) ($raw['status'] ?? '');
        $publish = (string) ($raw['publish'] ?? '');
        $deploy = (string) ($raw['deploy'] ?? '');
        $agent = (string) ($raw['agent'] ?? '');
        $pack = (string) ($raw['pack'] ?? '');
        $health = (string) ($raw['health'] ?? '');
        $app = (string) ($raw['app'] ?? '');
        $theme = (string) ($raw['theme'] ?? '');
        $themeId = trim((string) ($raw['theme_id'] ?? ''));
        $cms = (string) ($raw['cms'] ?? '');
        $autoDeploy = (string) ($raw['auto_deploy'] ?? '');
        $server = trim((string) ($raw['server'] ?? ''));
        $stale = (string) ($raw['stale'] ?? '');

        $filters = [
            'q' => $q,
            'channel' => in_array($channel, $allowedChannels, true) ? $channel : '',
            'status' => in_array($status, SiteStatus::values(), true) ? $status : '',
            'publish' => in_array($publish, $publishFilters, true) ? $publish : '',
            'deploy' => in_array($deploy, Site::DEPLOY_FILTERS, true) ? $deploy : '',
            'agent' => in_array($agent, Site::AGENT_FILTERS, true) ? $agent : '',
            'pack' => in_array($pack, Site::PACK_FILTERS, true) ? $pack : '',
            'health' => in_array($health, Site::HEALTH_FILTERS, true) ? $health : '',
            'app' => in_array($app, Site::APP_FILTERS, true) ? $app : '',
            'theme' => in_array($theme, Site::THEME_FILTERS, true) ? $theme : '',
            'theme_id' => preg_match(Site::THEME_ID_FILTER_PATTERN, $themeId) === 1 ? $themeId : '',
            'cms' => in_array($cms, Site::CMS_FILTERS, true) ? $cms : '',
            'auto_deploy' => in_array($autoDeploy, Site::AUTO_DEPLOY_FILTERS, true) ? $autoDeploy : '',
            'server' => preg_match(Site::SERVER_FILTER_PATTERN, $server) === 1 ? $server : '',
            'stale' => in_array($stale, Site::STALE_FILTERS, true) ? $stale : '',
        ];

        return array_filter($filters, static fn (string $value): bool => $value !== '');
    }

    /**
     * Named arguments for Site::scopeMatchingListFilters() from a filter map
     * keyed like FILTER_KEYS. Unknown keys are dropped; the scope validates values.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, string>
     */
    public static function scopeArguments(array $filters): array
    {
        $arguments = [];
        foreach (self::FILTER_KEYS as $key) {
            $value = $filters[$key] ?? '';
            $arguments[self::SCOPE_ARGUMENTS[$key] ?? $key] = is_string($value) ? trim($value) : '';
        }

        return $arguments;
    }

    /**
     * Bulk "all matching" requests carry the list filter as `filter_*` inputs.
     *
     * @return array<string, string>
     */
    public static function bulkScopeArguments(Request $request): array
    {
        $filters = [];
        foreach (self::FILTER_KEYS as $key) {
            $filters[$key] = (string) $request->input('filter_'.$key, '');
        }

        return self::scopeArguments($filters);
    }

    /**
     * @param  array<string, mixed>  $rawFilters
     * @param  iterable<mixed>  $columns
     * @return array{id: string, name: string, filters: array<string, string>, columns: list<string>, sort: array{key: string, dir: string}}
     */
    public static function store(
        User $user,
        string $name,
        array $rawFilters,
        iterable $columns,
        string $sortKey,
        string $sortDir,
        bool $asDefault,
    ): array {
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('name');
        }

        $name = mb_substr($name, 0, 40);
        $filters = self::sanitizeFilters($rawFilters);
        $safeColumns = SiteListColumns::sanitize($columns);
        $sort = self::sanitizeSort(['key' => $sortKey, 'dir' => $sortDir], $safeColumns);

        $stored = $user->listPreference(SiteListColumns::LIST_KEY);
        $saved = self::sanitizeSaved($stored['views'] ?? []);

        $index = null;
        foreach ($saved as $i => $row) {
            if (mb_strtolower($row['name']) === mb_strtolower($name)) {
                $index = $i;
                break;
            }
        }

        if ($index === null && count($saved) >= self::MAX) {
            throw new InvalidArgumentException('limit');
        }

        $row = [
            'id' => $index === null ? bin2hex(random_bytes(6)) : $saved[$index]['id'],
            'name' => $name,
            'filters' => $filters,
            'columns' => $safeColumns,
            'sort' => $sort,
        ];

        if ($index === null) {
            $saved[] = $row;
        } else {
            $saved[$index] = $row;
        }

        $payload = [
            'views' => $saved,
            'applied_view' => $row['id'],
            'columns' => $safeColumns,
            'sort' => $sort,
        ];
        if ($asDefault) {
            $payload['default_view'] = $row['id'];
        }

        $user->saveListPreference(SiteListColumns::LIST_KEY, $payload);

        return $row;
    }

    public static function forget(User $user, string $id): bool
    {
        $stored = $user->listPreference(SiteListColumns::LIST_KEY);
        $saved = self::sanitizeSaved($stored['views'] ?? []);
        $kept = array_values(array_filter(
            $saved,
            static fn (array $row): bool => $row['id'] !== $id,
        ));

        if (count($kept) === count($saved)) {
            return false;
        }

        $payload = ['views' => $kept];
        if (($stored['default_view'] ?? null) === $id) {
            $payload['default_view'] = null;
        }
        if (($stored['applied_view'] ?? null) === $id) {
            $payload['applied_view'] = null;
        }

        $user->saveListPreference(SiteListColumns::LIST_KEY, $payload);

        return true;
    }

    /**
     * Drop-one-filter URL. An empty remainder must not land on bare `/sites`,
     * or a default view would immediately put the filter back.
     *
     * @param  array<string, string>  $applied
     */
    public static function withoutFilter(array $applied, string $key): string
    {
        $remaining = array_diff_key($applied, [$key => true]);

        return $remaining === []
            ? self::clearUrl()
            : route('ops.sites', $remaining);
    }

    /**
     * @param  array<string, mixed>  $view
     * @return array<string, string>
     */
    public static function savedQuery(array $view): array
    {
        $query = is_array($view['filters'] ?? null) ? $view['filters'] : [];
        $query['view'] = (string) ($view['id'] ?? '');
        $sort = is_array($view['sort'] ?? null) ? $view['sort'] : [];
        if (($sort['key'] ?? '') !== '') {
            $query['sort'] = (string) $sort['key'];
            $query['dir'] = (string) ($sort['dir'] ?? SiteListColumns::DEFAULT_SORT_DIRECTION);
        }

        return array_filter($query, static fn (mixed $value): bool => is_string($value) && $value !== '');
    }

    /**
     * @return array<string, string>
     */
    private static function builtinFilters(string $id): array
    {
        $query = self::builtinQuery($id);
        unset($query['view']);

        return self::sanitizeFilters($query);
    }

    /**
     * @param  array<string, string>  $filters
     */
    private static function matchingBuiltinId(array $filters): ?string
    {
        if ($filters === []) {
            return self::ALL;
        }

        foreach (['error', 'unpublished', 'dockerfile'] as $id) {
            if ($filters === self::builtinFilters($id)) {
                return $id;
            }
        }

        return null;
    }

    private static function hasListQuery(Request $request): bool
    {
        foreach ([...self::FILTER_KEYS, 'view', 'sort', 'dir', 'page'] as $key) {
            $value = $request->query($key);
            if ($value !== null && $value !== '') {
                return true;
            }
        }

        return false;
    }

    private static function requestHasFilterKeys(Request $request): bool
    {
        foreach (self::FILTER_KEYS as $key) {
            $value = $request->query($key);
            if ($value !== null && $value !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array{id: string, name: string, filters: array<string, string>, columns: list<string>, sort: array{key: string, dir: string}}>  $saved
     * @return array{id: string, name: string, filters: array<string, string>, columns: list<string>, sort: array{key: string, dir: string}}|null
     */
    private static function findSaved(array $saved, string $id): ?array
    {
        if ($id === '') {
            return null;
        }

        foreach ($saved as $row) {
            if ($row['id'] === $id) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @param  list<array{id: string, name: string, filters: array<string, string>, columns: list<string>, sort: array{key: string, dir: string}}>  $saved
     */
    private static function usableDefault(?string $id, array $saved): ?string
    {
        if ($id === null || $id === '' || $id === self::ALL) {
            return null;
        }

        return self::findSaved($saved, $id) !== null ? $id : null;
    }

    /**
     * @return list<array{id: string, name: string, filters: array<string, string>, columns: list<string>, sort: array{key: string, dir: string}}>
     */
    private static function sanitizeSaved(mixed $rows): array
    {
        if (! is_array($rows)) {
            return [];
        }

        $clean = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $id = (string) ($row['id'] ?? '');
            $name = trim((string) ($row['name'] ?? ''));
            if ($id === '' || $name === '' || ! preg_match('/^[a-z0-9]{8,26}$/', $id)) {
                continue;
            }
            $columns = SiteListColumns::sanitize($row['columns'] ?? []);
            $clean[] = [
                'id' => $id,
                'name' => mb_substr($name, 0, 40),
                'filters' => self::sanitizeFilters(is_array($row['filters'] ?? null) ? $row['filters'] : []),
                'columns' => $columns,
                'sort' => self::sanitizeSort(is_array($row['sort'] ?? null) ? $row['sort'] : [], $columns),
            ];
            if (count($clean) >= self::MAX) {
                break;
            }
        }

        return $clean;
    }

    /**
     * @param  array<string, mixed>  $sort
     * @param  list<string>  $columns
     * @return array{key: string, dir: string}
     */
    private static function sanitizeSort(array $sort, array $columns): array
    {
        $key = (string) ($sort['key'] ?? '');
        $dir = (string) ($sort['dir'] ?? '');
        $usable = $key !== ''
            && SiteListColumns::isSortable($key)
            && in_array($key, $columns, true);

        return [
            'key' => $usable ? $key : SiteListColumns::DEFAULT_SORT_KEY,
            'dir' => $usable && in_array($dir, SiteListView::DIRECTIONS, true)
                ? $dir
                : SiteListColumns::DEFAULT_SORT_DIRECTION,
        ];
    }
}
