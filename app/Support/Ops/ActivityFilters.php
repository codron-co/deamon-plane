<?php

namespace App\Support\Ops;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Allowlisted query for the activity feed. Unknown values are dropped so a typo
 * widens the list instead of emptying it — the same contract the Sites filters use.
 */
final class ActivityFilters
{
    /**
     * @param  list<array{key: string, label: string, value: string, url: string}>  $chips
     */
    private function __construct(
        public readonly string $search,
        public readonly string $kind,
        public readonly string $outcome,
        public readonly string $actorId,
        public readonly string $siteId,
        public readonly string $sortDirection,
        public readonly array $chips,
    ) {}

    public static function from(Request $request): self
    {
        $search = trim((string) $request->query('q', ''));
        $kind = (string) $request->query('kind', '');
        $kind = in_array($kind, ActivityFeed::KINDS, true) ? $kind : '';
        $outcome = (string) $request->query('outcome', '');
        $outcome = in_array($outcome, ActivityFeed::OUTCOMES, true) ? $outcome : '';
        $actorId = (string) $request->query('actor', '');
        $actorId = ctype_digit($actorId) ? $actorId : '';
        $siteId = (string) $request->query('site', '');
        $siteId = Str::isUlid($siteId) ? $siteId : '';
        $sortDirection = $request->query('dir') === 'asc' ? 'asc' : 'desc';

        $applied = array_filter([
            'q' => $search,
            'kind' => $kind,
            'outcome' => $outcome,
            'actor' => $actorId,
            'site' => $siteId,
        ], static fn (string $value): bool => $value !== '');

        return new self(
            $search,
            $kind,
            $outcome,
            $actorId,
            $siteId,
            $sortDirection,
            self::chips($applied, $kind, $outcome, $actorId, $siteId, $search),
        );
    }

    public function active(): bool
    {
        return $this->chips !== [];
    }

    /**
     * Query string for the current filters. Used by the CSV link so a download
     * is the same set the page is showing. Default `dir=desc` is omitted.
     *
     * @return array<string, string>
     */
    public function query(): array
    {
        return array_filter([
            'q' => $this->search,
            'kind' => $this->kind,
            'outcome' => $this->outcome,
            'actor' => $this->actorId,
            'site' => $this->siteId,
            'dir' => $this->sortDirection === 'asc' ? 'asc' : '',
        ], static fn (string $value): bool => $value !== '');
    }

    /**
     * @param  array<string, string>  $applied
     * @return list<array{key: string, label: string, value: string, url: string}>
     */
    private static function chips(
        array $applied,
        string $kind,
        string $outcome,
        string $actorId,
        string $siteId,
        string $search,
    ): array {
        $displayed = [
            'q' => $search,
            'kind' => $kind === '' ? '' : (string) __('ops.activity.kinds.'.$kind),
            'outcome' => $outcome === '' ? '' : (string) __('ops.activity.outcomes.'.$outcome),
            'actor' => $actorId,
            'site' => $siteId,
        ];
        $labels = [
            'q' => __('ops.activity.filter_search'),
            'kind' => __('ops.activity.filter_kind'),
            'outcome' => __('ops.activity.filter_outcome'),
            'actor' => __('ops.activity.filter_actor'),
            'site' => __('ops.activity.filter_site'),
        ];

        $chips = [];
        foreach ($applied as $key => $value) {
            $chips[] = [
                'key' => $key,
                'label' => $labels[$key],
                'value' => $displayed[$key],
                'url' => route('ops.activity', array_diff_key($applied, [$key => true])),
            ];
        }

        return $chips;
    }
}
