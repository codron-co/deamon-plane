@extends('layouts.ops')

@section('title', __('cloudflare.defaults.title'))

@section('breadcrumbs')
    <a href="{{ route('ops.cloudflare.index') }}">{{ __('cloudflare.title') }}</a>
    <span aria-hidden="true">/</span>
    <span>{{ __('cloudflare.defaults.title') }}</span>
@endsection

@section('actions')
    <a class="btn btn-ghost btn-sm" href="{{ route('ops.cloudflare.index') }}">{{ __('cloudflare.back') }}</a>
    @if ($canWrite)
        <form method="POST" action="{{ route('ops.cloudflare.defaults.reset') }}" data-confirm="{{ __('cloudflare.defaults.reset_confirm') }}" data-confirm-title="{{ __('cloudflare.defaults.reset_title') }}" data-confirm-label="{{ __('cloudflare.defaults.reset') }}" data-confirm-danger="false">
            @csrf
            <button type="submit" class="btn btn-secondary btn-sm">{{ __('cloudflare.defaults.reset') }}</button>
        </form>
    @endif
@endsection

@section('content')
    <p class="page-lede">{{ __('cloudflare.defaults.lede') }}</p>
    <p class="field-hint">{{ __('cloudflare.defaults.hint') }}</p>

    @if ($canWrite)
        <section class="ops-panel" aria-labelledby="cf-default-add-heading">
            <h2 id="cf-default-add-heading">{{ __('cloudflare.dns.add') }}</h2>
            <form method="POST" action="{{ route('ops.cloudflare.defaults.store') }}" class="ops-form">
                @csrf
                @include('ops.cloudflare._dns-fields', ['prefix' => 'default-add', 'canWrite' => true, 'useOld' => true])
                @error('priority') <p class="field-error" role="alert">{{ $message }}</p> @enderror
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">{{ __('cloudflare.dns.add') }}</button>
                </div>
            </form>
        </section>
    @else
        <p class="field-hint">{{ __('cloudflare.readonly') }}</p>
    @endif

    @if ($records->isEmpty())
        <div class="empty-panel">
            <h2>{{ __('cloudflare.defaults.empty') }}</h2>
        </div>
    @else
        <div class="sites-table-wrap">
            <table class="ops-table">
                <thead>
                    <tr>
                        <th>{{ __('cloudflare.dns.type') }}</th>
                        <th>{{ __('cloudflare.dns.name') }}</th>
                        <th>{{ __('cloudflare.dns.content') }}</th>
                        <th>{{ __('cloudflare.dns.ttl') }}</th>
                        <th>{{ __('cloudflare.dns.priority') }}</th>
                        @if ($canWrite)<th></th>@endif
                    </tr>
                </thead>
                <tbody>
                    @foreach ($records as $record)
                        <tr>
                            @if ($canWrite)
                                <td colspan="6">
                                    <div class="ops-dns-row">
                                        <form method="POST" action="{{ route('ops.cloudflare.defaults.update', $record) }}">
                                            @csrf
                                            @method('PUT')
                                            @include('ops.cloudflare._dns-fields', ['prefix' => 'default-'.$record->id, 'canWrite' => true, 'useOld' => false, 'type' => $record->type, 'name' => $record->name, 'content' => $record->content, 'ttl' => $record->ttl, 'priority' => $record->priority])
                                            <div class="ops-row-actions">
                                                <button type="submit" class="btn btn-secondary btn-sm">{{ __('ops.actions.save') }}</button>
                                            </div>
                                        </form>
                                        <form method="POST" action="{{ route('ops.cloudflare.defaults.destroy', $record) }}" data-confirm="{{ __('cloudflare.dns.delete_confirm', ['name' => $record->name, 'type' => $record->type]) }}" data-confirm-title="{{ __('cloudflare.dns.delete_title') }}" data-confirm-label="{{ __('ops.actions.delete') }}">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-ghost btn-sm">{{ __('ops.actions.delete') }}</button>
                                        </form>
                                    </div>
                                </td>
                            @else
                                <td>{{ $record->type }}</td>
                                <td>{{ $record->name }}</td>
                                <td class="muted">{{ $record->content }}</td>
                                <td class="muted">{{ $record->ttl === 1 ? __('cloudflare.dns.ttl_auto') : $record->ttl }}</td>
                                <td class="muted">{{ $record->priority ?? __('ops.none') }}</td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
@endsection
