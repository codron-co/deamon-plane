@extends('layouts.ops')

@section('title', $title)

@section('content_class', 'ops-content-wide')

@section('breadcrumbs')
    <a href="{{ route('ops.coolify.index') }}">{{ __('coolify.title') }}</a>
    <span aria-hidden="true">/</span>
    <a href="{{ route('ops.coolify.show', $connection) }}">{{ $connection->name }}</a>
    <span aria-hidden="true">/</span>
    <span>{{ $title }}</span>
@endsection

@section('actions')
    <a class="btn btn-ghost btn-sm" href="{{ route('ops.coolify.show', $connection) }}">{{ __('coolify.detail.back_to_connection') }}</a>
    @if ($canWrite)
        <form method="POST" action="{{ route($toggleRoute, ['connection' => $connection, $toggleParam => $record]) }}">
            @csrf
            <button type="submit" class="btn btn-secondary btn-sm">
                {{ $statusActive ? __('coolify.allowlist.deactivate') : __('coolify.allowlist.activate') }}
            </button>
        </form>
    @endif
@endsection

@section('content')
    <p class="page-lede">{{ __('coolify.detail.lede', ['kind' => $kindLabel, 'name' => $connection->name]) }}</p>

    <div class="ops-detail-grid">
        <section class="ops-detail-card">
            <span class="ops-detail-label">{{ __('coolify.allowlist.status') }}</span>
            <div class="ops-detail-value">
                <span class="status-chip status-{{ $statusActive ? 'active' : 'error' }}">
                    {{ $statusActive ? __('ops.active') : __('ops.inactive') }}
                </span>
                @if ($isDefault)
                    <span class="ops-chip">{{ __('ops.default') }}</span>
                @endif
            </div>
        </section>
        <section class="ops-detail-card">
            <span class="ops-detail-label">{{ __('coolify.detail.type') }}</span>
            <div class="ops-detail-value">{{ $kindLabel }}</div>
        </section>
        @foreach ($facts as $fact)
            <section class="ops-detail-card">
                <span class="ops-detail-label">{{ $fact['label'] }}</span>
                <div class="ops-detail-value">
                    @if (! empty($fact['href']))
                        <a href="{{ $fact['href'] }}">{{ $fact['value'] }}</a>
                    @elseif (! empty($fact['code']))
                        <code>{{ $fact['value'] }}</code>
                    @else
                        {{ $fact['value'] }}
                    @endif
                </div>
            </section>
        @endforeach
    </div>

    @if ($environments->isNotEmpty())
        <section class="ops-detail-section" aria-labelledby="inventory-environments-heading">
            <h2 id="inventory-environments-heading">{{ __('coolify.allowlist.environments') }}</h2>
            <div class="sites-table-wrap">
                <table class="ops-table">
                    <thead>
                        <tr>
                            <th>{{ __('coolify.allowlist.name') }}</th>
                            <th>{{ __('coolify.allowlist.status') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($environments as $environment)
                            <tr data-href="{{ route('ops.coolify.environments.show', [$connection, $environment]) }}" tabindex="0">
                                <td>
                                    <a class="site-name" href="{{ route('ops.coolify.environments.show', [$connection, $environment]) }}">{{ $environment->label() }}</a>
                                    <div class="site-slug">{{ $environment->uuid }}</div>
                                </td>
                                <td>
                                    <span class="status-chip status-{{ $environment->is_active ? 'active' : 'error' }}">
                                        {{ $environment->is_active ? __('ops.active') : __('ops.inactive') }}
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    <section class="ops-detail-section" aria-labelledby="inventory-sites-heading">
        <h2 id="inventory-sites-heading">{{ __('coolify.detail.linked_sites') }}</h2>
        @if ($sites->isEmpty())
            <p class="muted">{{ __('coolify.detail.no_sites') }}</p>
        @else
            <div class="sites-table-wrap">
                <table class="ops-table">
                    <thead>
                        <tr>
                            <th>{{ __('sites.columns.site') }}</th>
                            <th>{{ __('sites.columns.domain') }}</th>
                            <th>{{ __('sites.columns.status') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($sites as $site)
                            <tr data-href="{{ route('ops.sites.show', $site) }}" tabindex="0">
                                <td>
                                    <a class="site-name" href="{{ route('ops.sites.show', $site) }}">{{ $site->name }}</a>
                                    <div class="site-slug">{{ $site->slug }}</div>
                                </td>
                                <td class="muted">{{ $site->primary_domain ?: __('ops.none') }}</td>
                                <td>
                                    <span class="status-chip status-{{ $site->status?->value }}">{{ $site->status?->label() ?? __('ops.unknown') }}</span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
@endsection
