@php
    $canWrite = (bool) auth()->user()?->canWriteOps();
@endphp
@if ($events->isEmpty())
    <div class="empty-panel{{ $filtersActive ? ' empty-panel-filtered' : '' }}">
        <h2>{{ $filtersActive ? __('security.events.empty_filtered_title') : __('security.events.empty_title') }}</h2>
        <p>{{ $filtersActive ? __('security.events.empty_filtered_hint') : __('security.events.empty_hint') }}</p>
    </div>
@else
    <div class="sites-table-wrap">
        <table class="ops-table">
            <thead>
                <tr>
                    <th scope="col">{{ __('security.cols.severity') }}</th>
                    <th scope="col">{{ __('security.cols.rule') }}</th>
                    <th scope="col">{{ __('security.cols.ip') }}</th>
                    <th scope="col">{{ __('security.cols.path') }}</th>
                    <th scope="col">{{ __('security.cols.site') }}</th>
                    <th scope="col" aria-sort="{{ $query->direction === 'desc' ? 'descending' : 'ascending' }}" class="ops-th-sortable">
                        <a class="ops-sort-link is-sorted is-{{ $query->direction }}"
                            href="{{ request()->fullUrlWithQuery(['dir' => $query->direction === 'desc' ? 'asc' : 'desc', 'page' => null]) }}"
                            data-ops-list-focus="sort:occurred"
                            aria-label="{{ __('security.events.sort') }}" title="{{ __('security.events.sort') }}">
                            <span class="ops-sort-label">{{ __('security.cols.when') }}</span>
                        </a>
                    </th>
                </tr>
            </thead>
            <tbody>
                @foreach ($events as $event)
                    <tr>
                        <td>
                            <span class="security-sev is-{{ $event->severityKey() }}" title="{{ __('security.severity.'.$event->severityKey()) }}">
                                <span class="security-sev-dot" aria-hidden="true"></span>
                                <span class="visually-hidden">{{ __('security.severity.'.$event->severityKey()) }}</span>
                            </span>
                        </td>
                        <td>
                            {{ $event->rule }}
                            @if ($event->count > 1)
                                <span class="muted">{{ __('security.events.count_note', ['count' => number_format($event->count)]) }}</span>
                            @endif
                        </td>
                        <td>
                            <a href="{{ route('ops.security.events', ['ip' => $event->ip, 'period' => '7d']) }}"><code>{{ $event->ip }}</code></a>
                            @if (isset($blockedIps[$event->ip]))
                                <span class="status-chip status-error">{{ __('security.events.blocked') }}</span>
                            @elseif ($canWrite)
                                @include('ops.security._block_form', ['ip' => $event->ip])
                            @endif
                            @if ($event->country)
                                <span class="muted">{{ $event->country }}</span>
                            @endif
                        </td>
                        <td>
                            <span class="muted">{{ $event->method }}</span>
                            <code>{{ $event->path ?? '-' }}</code>
                            @if ($event->status)
                                <span class="muted">{{ $event->status }}</span>
                            @endif
                        </td>
                        <td>
                            @if ($event->site)
                                <a href="{{ route('ops.security.site', $event->site) }}">{{ $event->site->name }}</a>
                            @else
                                <span class="muted">{{ __('ops.none') }}</span>
                            @endif
                        </td>
                        <td><x-ops.freshness :at="$event->occurred_at" :mark-stale="false" /></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    @include('ops.partials.pagination', ['paginator' => $events, 'label' => __('security.events.pagination')])
@endif
