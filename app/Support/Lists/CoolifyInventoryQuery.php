<?php

namespace App\Support\Lists;

use App\Models\CoolifyConnection;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Filters the four Coolify allowlists on the connection show page.
 *
 * The inventory is not a first-class list URL: it lives on a tab. The same
 * GET query still drives a ListFragment region so search and filters swap
 * in place like Sites / mail servers, without a second path.
 */
final class CoolifyInventoryQuery
{
    public const KINDS = ['servers', 'projects', 'environments', 'git'];

    public const STATUSES = ['active', 'inactive'];

    /**
     * @param  Collection<int, mixed>  $servers
     * @param  Collection<int, mixed>  $projects
     * @param  Collection<int, mixed>  $environments
     * @param  Collection<int, mixed>  $gitSources
     */
    public function __construct(
        public readonly string $search,
        public readonly string $kind,
        public readonly string $status,
        public readonly Collection $servers,
        public readonly Collection $projects,
        public readonly Collection $environments,
        public readonly Collection $gitSources,
        public readonly int $totalInventory,
    ) {}

    public static function from(Request $request, CoolifyConnection $connection): self
    {
        $search = trim((string) $request->query('q', ''));
        $kind = (string) $request->query('kind', '');
        $kind = in_array($kind, self::KINDS, true) ? $kind : '';
        $status = (string) $request->query('status', '');
        $status = in_array($status, self::STATUSES, true) ? $status : '';

        $servers = self::filter($connection->servers, $search, $status, 'servers', $connection);
        $projects = self::filter($connection->projects, $search, $status, 'projects', $connection);
        $environments = self::filter($connection->environments, $search, $status, 'environments', $connection);
        $gitSources = self::filter($connection->gitSources, $search, $status, 'git', $connection);

        $total = $connection->servers->count()
            + $connection->projects->count()
            + $connection->environments->count()
            + $connection->gitSources->count();

        if ($kind === 'servers') {
            $projects = collect();
            $environments = collect();
            $gitSources = collect();
        } elseif ($kind === 'projects') {
            $servers = collect();
            $environments = collect();
            $gitSources = collect();
        } elseif ($kind === 'environments') {
            $servers = collect();
            $projects = collect();
            $gitSources = collect();
        } elseif ($kind === 'git') {
            $servers = collect();
            $projects = collect();
            $environments = collect();
        }

        return new self(
            $search,
            $kind,
            $status,
            $servers,
            $projects,
            $environments,
            $gitSources,
            $total,
        );
    }

    public function filtersActive(): bool
    {
        return $this->search !== '' || $this->kind !== '' || $this->status !== '';
    }

    public function isEmpty(): bool
    {
        return $this->servers->isEmpty()
            && $this->projects->isEmpty()
            && $this->environments->isEmpty()
            && $this->gitSources->isEmpty();
    }

    /**
     * @return list<array{key: string, label: string, value: string, url: string}>
     */
    public function chips(CoolifyConnection $connection): array
    {
        $applied = array_filter([
            'q' => $this->search,
            'kind' => $this->kind,
            'status' => $this->status,
        ], static fn (string $value): bool => $value !== '');

        $labels = [
            'q' => __('coolify.filter_search'),
            'kind' => __('coolify.filter_kind'),
            'status' => __('coolify.filter_status'),
        ];
        $displayed = [
            'q' => $this->search,
            'kind' => $this->kind !== '' ? (string) __('coolify.allowlist.'.$this->kindLabelKey($this->kind)) : '',
            'status' => match ($this->status) {
                'active' => __('ops.active'),
                'inactive' => __('ops.inactive'),
                default => '',
            },
        ];

        $chips = [];
        foreach ($applied as $key => $value) {
            $chips[] = [
                'key' => $key,
                'label' => $labels[$key],
                'value' => $displayed[$key],
                'url' => route('ops.coolify.show', array_merge(
                    ['connection' => $connection],
                    array_diff_key($applied, [$key => true]),
                )),
            ];
        }

        return $chips;
    }

    /**
     * @param  Collection<int, mixed>  $rows
     * @return Collection<int, mixed>
     */
    private static function filter(Collection $rows, string $search, string $status, string $kind, CoolifyConnection $connection): Collection
    {
        $needle = $search === '' ? '' : Str::lower($search);

        return $rows
            ->filter(function (object $row) use ($needle, $status, $kind, $connection): bool {
                if ($status === 'active' && ! $row->is_active) {
                    return false;
                }
                if ($status === 'inactive' && $row->is_active) {
                    return false;
                }
                if ($needle === '') {
                    return true;
                }

                return str_contains(self::haystack($row, $kind, $connection), $needle);
            })
            ->sortBy(fn (object $row): string => Str::lower($row->label()))
            ->values();
    }

    private static function haystack(object $row, string $kind, CoolifyConnection $connection): string
    {
        $parts = [(string) $row->name, (string) $row->uuid, $row->label()];

        if ($kind === 'servers') {
            $parts[] = (string) $row->ip;
        }

        if ($kind === 'environments') {
            $parts[] = (string) $row->project_uuid;
            $project = $connection->projects->firstWhere('uuid', $row->project_uuid);
            if ($project !== null) {
                $parts[] = (string) $project->name;
                $parts[] = $project->label();
            }
        }

        if ($kind === 'git') {
            $kindValue = $row->kind?->value ?? '';
            $parts[] = $kindValue;
            if ($kindValue !== '') {
                $parts[] = (string) __('coolify.kinds.'.$kindValue);
            }
        }

        return Str::lower(implode(' ', $parts));
    }

    private function kindLabelKey(string $kind): string
    {
        return $kind === 'git' ? 'git' : $kind;
    }
}
