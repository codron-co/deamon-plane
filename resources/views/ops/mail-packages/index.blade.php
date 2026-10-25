@extends('layouts.ops')

@section('title', __('comail.packages.title'))

@section('actions')
    @if ($canWrite)
        <a class="btn btn-primary btn-sm" href="{{ route('ops.mail-packages.create') }}">{{ __('comail.packages.add') }}</a>
    @endif
@endsection

@section('content')
    <p class="page-lede">{{ __('comail.packages.lede') }}</p>

    <div class="sites-table-wrap">
        <table class="ops-table">
            <thead>
                <tr>
                    <th>{{ __('comail.packages.columns.name') }}</th>
                    <th>{{ __('comail.packages.columns.mailboxes') }}</th>
                    <th>{{ __('comail.packages.columns.storage') }}</th>
                    <th>{{ __('comail.packages.columns.max_quota') }}</th>
                    <th>{{ __('comail.packages.columns.aliases') }}</th>
                    <th>{{ __('comail.packages.columns.domains') }}</th>
                    <th>{{ __('comail.packages.columns.price') }}</th>
                    <th>{{ __('comail.packages.columns.sites') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($packages as $package)
                    <tr data-href="{{ route('ops.mail-packages.edit', $package) }}" tabindex="0" data-mail-package="{{ $package->slug }}">
                        <td>
                            <a class="site-name" href="{{ route('ops.mail-packages.edit', $package) }}">{{ $package->name }}</a>
                            @if ($package->is_default)
                                <span class="status-chip">{{ __('comail.packages.default_badge') }}</span>
                            @endif
                            @unless ($package->is_active)
                                <span class="status-chip">{{ __('comail.packages.inactive_badge') }}</span>
                            @endunless
                        </td>
                        <td>{{ $package->mailbox_limit }}</td>
                        <td>{{ number_format($package->storage_mb / 1024, 1) }} GB</td>
                        <td>{{ number_format($package->mailbox_max_quota_mb / 1024, 1) }} GB</td>
                        <td>{{ $package->alias_limit }}</td>
                        <td>{{ $package->domain_limit ?? __('comail.packages.domain_any') }}</td>
                        <td class="muted">{{ $package->price_monthly !== null ? number_format((float) $package->price_monthly, 2, ',', '.').' '.$package->currency : __('ops.none') }}</td>
                        <td class="muted">{{ $package->sites_count + ($package->is_default ? $defaultSites : 0) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endsection
