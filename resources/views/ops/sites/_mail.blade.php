@php
    /** @var \App\Models\Site $site */
    $canEditMail = $canEdit ?? false;
    $mailServers = $mailServers ?? collect();
    $mailCatalog = $mailCatalog ?? [];
    $mailboxRequests = $mailboxRequests ?? $site->mailboxRequests ?? collect();
    $boundIds = $site->mailBindings->pluck('hostinger_order_id')->all();
    if ($boundIds === [] && filled($site->hostinger_order_id)) {
        $boundIds = [(string) $site->hostinger_order_id];
    }
    $selectedOrderIds = old('hostinger_order_ids', $boundIds);
    if (! is_array($selectedOrderIds) || $selectedOrderIds === []) {
        $primary = strtolower((string) $site->primary_domain);
        foreach ($mailCatalog as $order) {
            if (is_string($order['domain'] ?? null) && strcasecmp($order['domain'], $primary) === 0) {
                $selectedOrderIds = [$order['id']];
                break;
            }
        }
    }
    if (! is_array($selectedOrderIds) || $selectedOrderIds === []) {
        $selectedOrderIds = [''];
    }
    $domains = $site->mailDomains();
@endphp

<article class="site-card site-operation" id="site-mail" aria-labelledby="site-mail-heading">
    <div class="site-card-head">
        <h3 id="site-mail-heading">{{ __('sites.detail.mail') }} @include('ops.dashboard._hint', ['text' => __('mail.select_hint')])</h3>
        @if ($domains !== [])
            <span class="status-chip">{{ implode(', ', $domains) }}</span>
        @elseif ($site->mailServer)
            <span class="status-chip">{{ __('mail.sites.unmatched') }}</span>
        @endif
    </div>

    @if ($canEditMail)
        @php($selectedMailId = (string) old('mail_server_id', $site->mail_server_id))
        <form method="POST" action="{{ route('ops.sites.mail', $site) }}" class="ops-form" data-ops-pending data-mail-bindings>
            @csrf
            <input type="hidden" name="mail_bindings_explicit" value="1">
            <div class="site-operation-line">
                <div class="field">
                    <label class="field-label" for="site_detail_mail_server">{{ __('sites.form.mail_server') }}</label>
                    <select id="site_detail_mail_server" class="field-input" name="mail_server_id">
                        <option value="">{{ __('mail.none') }}</option>
                        @foreach ($mailServers as $mailServer)
                            <option value="{{ $mailServer->id }}" @selected($selectedMailId === (string) $mailServer->id)>
                                {{ $mailServer->name }}
                            </option>
                        @endforeach
                    </select>
                    @error('mail_server_id') <p class="field-error" role="alert">{{ $message }}</p> @enderror
                </div>
            </div>

            @if ($site->mailServer)
                <div class="field site-mail-bindings">
                    <label class="field-label">{{ __('mail.boxes.title') }}</label>
                    <p class="field-hint">{{ __('mail.boxes.hint') }}</p>
                    <div data-mail-binding-list class="site-mail-binding-list">
                        @foreach ($selectedOrderIds as $index => $selectedId)
                            <div class="site-mail-binding-row" data-mail-binding-row>
                                <select id="site_detail_mail_box_{{ $index }}" class="field-input" name="hostinger_order_ids[]" aria-label="{{ __('mail.boxes.field') }}">
                                    <option value="">{{ __('mail.boxes.choose') }}</option>
                                    @foreach ($mailCatalog as $order)
                                        <option value="{{ $order['id'] }}" @selected((string) $selectedId === (string) $order['id'])>
                                            {{ $order['domain'] ?: $order['id'] }}
                                        </option>
                                    @endforeach
                                </select>
                                <button type="button" class="btn btn-ghost btn-sm" data-mail-binding-remove>{{ __('mail.boxes.remove') }}</button>
                            </div>
                        @endforeach
                    </div>
                    <template data-mail-binding-template>
                        <div class="site-mail-binding-row" data-mail-binding-row>
                            <select class="field-input" name="hostinger_order_ids[]" aria-label="{{ __('mail.boxes.field') }}">
                                <option value="">{{ __('mail.boxes.choose') }}</option>
                                @foreach ($mailCatalog as $order)
                                    <option value="{{ $order['id'] }}">{{ $order['domain'] ?: $order['id'] }}</option>
                                @endforeach
                            </select>
                            <button type="button" class="btn btn-ghost btn-sm" data-mail-binding-remove>{{ __('mail.boxes.remove') }}</button>
                        </div>
                    </template>
                    <div class="site-mail-binding-actions">
                        <button type="button" class="btn btn-ghost btn-sm" data-mail-binding-add>{{ __('mail.boxes.add') }}</button>
                    </div>
                    @if ($mailCatalog === [])
                        <p class="field-hint">{{ __('mail.boxes.empty_catalog') }}</p>
                    @endif
                    @error('hostinger_order_ids') <p class="field-error" role="alert">{{ $message }}</p> @enderror
                </div>
            @endif

            <div class="site-operation-line">
                <button type="submit" class="btn btn-secondary" data-pending-label="{{ __('ops.actions.working') }}">{{ __('mail.boxes.save') }}</button>
            </div>
        </form>
        @if ($site->mailServer)
            <form method="POST" action="{{ route('ops.sites.mail-order', $site) }}" data-ops-pending>
                @csrf
                <button type="submit" class="btn btn-ghost btn-sm" data-pending-label="{{ __('ops.actions.working') }}">{{ __('mail.boxes.refresh') }}</button>
            </form>
        @endif
    @else
        <p>
            @if ($site->mailServer)
                <a href="{{ route('ops.mail-servers.show', $site->mailServer) }}">{{ $site->mailServer->name }}</a>
                @if ($domains !== [])
                    — {{ implode(', ', $domains) }}
                @endif
            @else
                {{ __('sites.detail.mail_none') }}
            @endif
        </p>
    @endif

    @if ($mailboxRequests->isNotEmpty())
        <section class="site-mail-requests" aria-labelledby="site-mail-requests-heading">
            <h4 id="site-mail-requests-heading">{{ __('mail.requests.title') }}</h4>
            <div class="sites-table-wrap">
                <table class="ops-table">
                    <thead>
                        <tr>
                            <th>{{ __('mail.requests.email') }}</th>
                            <th>{{ __('mail.requests.status') }}</th>
                            <th>{{ __('mail.requests.note') }}</th>
                            @if ($canEditMail)
                                <th>{{ __('mail.requests.actions') }}</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($mailboxRequests as $mailboxRequest)
                            <tr>
                                <td>{{ $mailboxRequest->email() }}</td>
                                <td><span class="status-chip">{{ __('mail.requests.statuses.'.$mailboxRequest->status) }}</span></td>
                                <td>{{ $mailboxRequest->note ?: __('ops.none') }}</td>
                                @if ($canEditMail)
                                    <td>
                                        @if ($mailboxRequest->isPending())
                                            <form method="POST" action="{{ route('ops.sites.mailbox-requests.fulfill', [$site, $mailboxRequest]) }}" data-ops-pending class="site-mail-request-actions">
                                                @csrf
                                                <button type="submit" class="btn btn-secondary btn-sm">{{ __('mail.requests.fulfill') }}</button>
                                            </form>
                                            <form method="POST" action="{{ route('ops.sites.mailbox-requests.reject', [$site, $mailboxRequest]) }}" data-ops-pending data-confirm="{{ __('mail.requests.reject_confirm', ['email' => $mailboxRequest->email()]) }}" data-confirm-title="{{ __('mail.requests.reject_title') }}">
                                                @csrf
                                                <button type="submit" class="btn btn-ghost btn-sm">{{ __('mail.requests.reject') }}</button>
                                            </form>
                                        @endif
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif
</article>
