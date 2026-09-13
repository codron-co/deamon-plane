@if ($entries->isEmpty() && ! $filtersActive)
    <div class="empty-panel">
        <h2>{{ __('ops.activity.empty.title') }}</h2>
        <p>{{ __('ops.activity.empty.hint') }}</p>
    </div>
@elseif ($entries->isEmpty())
    <div class="empty-panel empty-panel-filtered">
        <h2>{{ __('ops.activity.empty.filtered_title') }}</h2>
        <p>{{ __('ops.activity.empty.filtered_hint', ['total' => $totalActivity ?? 0]) }}</p>
        @include('ops.partials.filter-chips', [
            'chips' => $activeFilters ?? [],
            'label' => __('ops.activity.empty.filters_label'),
        ])
        <div class="empty-panel-actions">
            <a class="btn btn-primary" href="{{ route('ops.activity') }}">{{ __('ops.actions.clear_filters') }}</a>
        </div>
    </div>
@else
    <div class="sites-table-wrap">
        <table class="ops-table">
            <thead>
                <tr>
                    <th scope="col">{{ __('ops.activity.columns.kind') }}</th>
                    <th scope="col">{{ __('ops.activity.columns.what') }}</th>
                    <th scope="col">{{ __('ops.activity.columns.outcome') }}</th>
                    <th scope="col">{{ __('ops.activity.columns.actor') }}</th>
                    <th scope="col">{{ __('ops.activity.columns.site') }}</th>
                    <th scope="col" aria-sort="{{ ($sortDirection ?? 'desc') === 'desc' ? 'descending' : 'ascending' }}" class="ops-th-sortable">
                        <a
                            class="ops-sort-link is-sorted is-{{ $sortDirection ?? 'desc' }}"
                            href="{{ request()->fullUrlWithQuery(['dir' => ($sortDirection ?? 'desc') === 'desc' ? 'asc' : 'desc', 'page' => null]) }}"
                            data-ops-list-focus="sort:occurred"
                            aria-label="{{ __('ops.activity.sort_when') }}"
                        >
                            <span class="ops-sort-label">{{ __('ops.activity.columns.when') }}</span>
                        </a>
                    </th>
                </tr>
            </thead>
            <tbody>
                @foreach ($entries as $row)
                    <tr @if ($row->url !== '') data-href="{{ $row->url }}" tabindex="0" @endif>
                        <td>
                            <span class="status-chip status-{{ $row->kind === 'audit' ? 'unknown' : ($row->kind === 'job' ? 'active' : 'in_progress') }}">{{ $row->kindLabel() }}</span>
                        </td>
                        <td>
                            @if ($row->url !== '')
                                <a class="site-name" href="{{ $row->url }}">{{ $row->title }}</a>
                            @else
                                <span class="site-name">{{ $row->title }}</span>
                            @endif
                            @if ($row->detail !== '')
                                <div class="muted">{{ $row->detail }}</div>
                            @endif
                        </td>
                        <td>
                            <span class="status-chip status-{{ $row->outcomeTone() }}">{{ $row->outcomeLabel() }}</span>
                        </td>
                        <td class="muted">{{ $row->actorName() }}</td>
                        <td>
                            @if ($row->site)
                                <a href="{{ route('ops.sites.show', $row->site) }}">{{ $row->site->name }}</a>
                            @else
                                <span class="muted">{{ __('ops.none') }}</span>
                            @endif
                        </td>
                        <td>
                            <x-ops.freshness :at="$row->occurredAt" :mark-stale="false" />
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    @include('ops.partials.pagination', ['paginator' => $entries, 'label' => __('ops.activity.pagination')])
@endif
