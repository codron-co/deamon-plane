@extends('layouts.ops')

@section('title', __('comail.mailboxes.search_title'))

@section('content')
    <p class="page-lede">{{ __('comail.mailboxes.lede') }}</p>

    @unless ($hasServer)
        <p class="muted" data-comail-no-server>{{ __('comail.site.no_server') }}</p>
    @else
        <form method="GET" action="{{ route('ops.comail.mailboxes') }}" class="ops-list-toolbar" role="search">
            <label class="ops-search">
                <span class="visually-hidden">{{ __('comail.mailboxes.search_label') }}</span>
                <input type="search" name="q" value="{{ $query }}" placeholder="{{ __('comail.mailboxes.search_placeholder') }}" autocomplete="off" maxlength="320">
            </label>
            <button type="submit" class="btn btn-secondary btn-sm">{{ __('comail.mailboxes.search_button') }}</button>
        </form>

        @if ($mailboxError)
            <p class="ops-alert" role="alert">{{ __('comail.mailboxes.load_failed', ['message' => $mailboxError]) }}</p>
        @endif

        @if ($query !== '')
            @if ($results === [])
                <p class="muted" data-comail-search-empty>{{ __('comail.mailboxes.no_results') }}</p>
            @else
                <div class="sites-table-wrap">
                    <table class="ops-table" data-comail-search-results>
                        <thead>
                            <tr>
                                <th>{{ __('comail.mailboxes.columns.address') }}</th>
                                <th>{{ __('comail.mailboxes.columns.site') }}</th>
                                <th>{{ __('comail.mailboxes.columns.usage') }}</th>
                                <th>{{ __('comail.mailboxes.columns.status') }}</th>
                                <th><span class="visually-hidden">{{ __('comail.mailboxes.columns.actions') }}</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($results as $mailbox)
                                <tr data-comail-mailbox="{{ $mailbox['address'] }}">
                                    <td>
                                        {{ $mailbox['address'] }}
                                        @if ($mailbox['name'] !== '')
                                            <br><span class="muted">{{ $mailbox['name'] }}</span>
                                        @endif
                                    </td>
                                    <td><a href="{{ route('ops.sites.show', $mailbox['site']) }}#site-comail">{{ $mailbox['site']->name }}</a></td>
                                    <td>@include('ops.comail._usage')</td>
                                    <td>
                                        <span class="status-chip {{ $mailbox['active'] ? 'status-active' : '' }}">{{ $mailbox['active'] ? __('comail.mailboxes.active') : __('comail.mailboxes.inactive') }}</span>
                                    </td>
                                    <td>
                                        @if ($mailbox['active'])
                                            @include('ops.comail._webmail-form', ['site' => $mailbox['site'], 'address' => $mailbox['address']])
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        @endif
    @endunless
@endsection
