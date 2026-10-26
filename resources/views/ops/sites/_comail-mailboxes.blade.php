@php
    /** @var \App\Models\Site $site */
    $canWebmail = $canWebmail ?? false;
@endphp
{{-- Fetched into the CoMail card by public/js/ops-lazy-panels.js. --}}
<div data-comail-mailboxes>
    <p class="field-label">{{ __('comail.mailboxes.title') }}</p>
    @if ($mailboxError)
        <p class="ops-alert" role="alert">{{ __('comail.mailboxes.load_failed', ['message' => $mailboxError]) }}</p>
    @elseif ($mailboxes === [])
        <p class="muted">{{ __('comail.mailboxes.empty') }}</p>
    @else
        <div class="sites-table-wrap">
            <table class="ops-table">
                <thead>
                    <tr>
                        <th>{{ __('comail.mailboxes.columns.address') }}</th>
                        <th>{{ __('comail.mailboxes.columns.name') }}</th>
                        <th>{{ __('comail.mailboxes.columns.usage') }}</th>
                        <th>{{ __('comail.mailboxes.columns.status') }}</th>
                        @if ($canWebmail)
                            <th><span class="visually-hidden">{{ __('comail.mailboxes.columns.actions') }}</span></th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @foreach ($mailboxes as $mailbox)
                        <tr data-comail-mailbox="{{ $mailbox['address'] }}">
                            <td>{{ $mailbox['address'] }}</td>
                            <td>{{ $mailbox['name'] !== '' ? $mailbox['name'] : __('ops.none') }}</td>
                            <td>@include('ops.comail._usage')</td>
                            <td>
                                <span class="status-chip {{ $mailbox['active'] ? 'status-active' : '' }}">{{ $mailbox['active'] ? __('comail.mailboxes.active') : __('comail.mailboxes.inactive') }}</span>
                            </td>
                            @if ($canWebmail)
                                <td>
                                    @if ($mailbox['active'])
                                        @include('ops.comail._webmail-form', ['address' => $mailbox['address']])
                                    @endif
                                </td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
