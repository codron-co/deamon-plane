@extends('layouts.ops')

@section('title', __('sites.archive.title'))

@section('content_class', 'ops-content-wide')

@section('actions')
    <a class="btn btn-secondary btn-sm" href="{{ route('ops.sites') }}">{{ __('sites.archive.back') }}</a>
@endsection

@section('content')
    <p class="site-note">{{ __('sites.archive.lede') }}</p>

    @if ($sites->isEmpty())
        <div class="empty-panel">
            <h2>{{ __('sites.archive.empty_title') }}</h2>
            <p>{{ __('sites.archive.empty_hint') }}</p>
        </div>
    @else
        <div class="sites-table-wrap">
            <table class="ops-table">
                <thead>
                    <tr>
                        <th>{{ __('sites.archive.columns.site') }}</th>
                        <th>{{ __('sites.archive.columns.domain') }}</th>
                        <th>{{ __('sites.archive.columns.coolify') }}</th>
                        <th>{{ __('sites.archive.columns.archived_at') }}</th>
                        <th class="ops-actions-col"><span class="visually-hidden">{{ __('sites.archive.columns.actions') }}</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($sites as $site)
                        <tr data-archived-site="{{ $site->id }}">
                            <td>
                                <strong>{{ $site->name }}</strong>
                                <div class="muted"><code>{{ $site->slug }}</code></div>
                            </td>
                            <td><code>{{ $site->primary_domain }}</code></td>
                            <td>
                                @if (filled($site->coolify_app_uuid))
                                    <span class="status-chip">{{ __('sites.archive.coolify_still_running') }}</span>
                                @else
                                    <span class="muted">{{ __('ops.none') }}</span>
                                @endif
                            </td>
                            <td>
                                <time datetime="{{ $site->deleted_at?->toIso8601String() }}" title="{{ $site->deleted_at?->toDateTimeString() }}">{{ $site->deleted_at?->diffForHumans() }}</time>
                            </td>
                            <td class="ops-table-actions">
                                @if ($canRestore)
                                    <form method="POST" action="{{ route('ops.sites.restore', $site) }}" class="ops-inline-form" data-ops-native data-restore-site>
                                        @csrf
                                        <button type="submit" class="btn btn-secondary btn-sm">{{ __('sites.archive.restore') }}</button>
                                    </form>
                                @endif
                                @if ($canPurge)
                                    <form
                                        method="POST"
                                        action="{{ route('ops.sites.purge', $site) }}"
                                        class="ops-inline-form"
                                        data-ops-native
                                        data-confirm="{{ __('sites.danger.hard_confirm', ['name' => $site->name]) }}"
                                        data-confirm-title="{{ __('sites.danger.hard_confirm_title') }}"
                                        data-confirm-label="{{ __('sites.menu.hard_delete') }}"
                                        data-confirm-danger="true"
                                    >
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-danger btn-sm">{{ __('sites.menu.hard_delete') }}</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @include('ops.partials.pagination', ['paginator' => $sites, 'label' => __('sites.pagination')])
    @endif
@endsection
