@extends('layouts.ops')

@section('title', __('cloudflare.title'))

@section('content_class', 'ops-content-wide')

@section('actions')
    <a class="btn btn-ghost btn-sm" href="{{ route('ops.cloudflare.defaults') }}">{{ __('cloudflare.defaults.nav') }}</a>
    @if ($canWrite)
        <a class="btn btn-primary btn-sm" href="{{ route('ops.cloudflare.create') }}">{{ __('cloudflare.add') }}</a>
    @endif
@endsection

@section('content')
    <div class="site-section-heading">
        <div>
            <span class="site-section-kicker">{{ __('cloudflare.title') }}</span>
            <h2>{{ __('cloudflare.title') }} @include('ops.cloudflare._hint', ['text' => __('cloudflare.lede')])</h2>
        </div>
    </div>

    @if ($accounts->isEmpty())
        <div class="empty-panel">
            <h2>{{ __('cloudflare.empty') }}</h2>
            <p>{{ __('cloudflare.empty_hint') }}</p>
            @if ($canWrite)
                <a class="btn btn-primary btn-sm" href="{{ route('ops.cloudflare.create') }}">{{ __('cloudflare.empty_action') }}</a>
            @endif
        </div>
    @else
        <div class="sites-table-wrap">
            <table class="ops-table">
                <thead>
                    <tr>
                        <th>{{ __('cloudflare.columns.name') }}</th>
                        <th>{{ __('cloudflare.columns.account_id') }}</th>
                        <th>{{ __('cloudflare.columns.status') }}</th>
                        <th>{{ __('cloudflare.columns.probe') }}</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($accounts as $account)
                        <tr data-href="{{ route('ops.cloudflare.show', $account) }}" tabindex="0">
                            <td>
                                <a class="site-name" href="{{ route('ops.cloudflare.show', $account) }}">{{ $account->name }}</a>
                                @if ($account->is_default)
                                    <span class="ops-chip">{{ __('ops.default') }}</span>
                                @endif
                            </td>
                            <td class="muted">
                                @if (filled($account->account_id))
                                    <code>{{ $account->account_id }}</code>
                                    <button
                                        type="button"
                                        class="btn btn-ghost btn-sm"
                                        data-copy-value="{{ $account->account_id }}"
                                        data-copied-label="{{ __('ops.actions.copied') }}"
                                        aria-label="{{ __('cloudflare.copy_account_id') }}"
                                    >{{ __('ops.actions.copy') }}</button>
                                @else
                                    {{ __('ops.none') }}
                                @endif
                            </td>
                            <td>
                                <span class="status-chip status-{{ $account->is_enabled ? 'active' : 'error' }}">
                                    {{ $account->is_enabled ? __('ops.enabled') : __('ops.disabled') }}
                                </span>
                            </td>
                            <td>
                                <x-ops.freshness :at="$account->last_probe_at" />
                            </td>
                            <td class="ops-row-actions">
                                <a class="btn btn-ghost btn-sm" href="{{ route('ops.cloudflare.show', $account) }}">{{ __('ops.actions.open') }}</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
@endsection
