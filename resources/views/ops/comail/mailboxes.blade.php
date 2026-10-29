@extends('layouts.ops')

@section('title', __('comail.mailboxes.page_title'))

@section('content')
    <p class="page-lede">{{ __('comail.mailboxes.page_lede') }}</p>

    @unless ($hasServer)
        <p class="muted" data-comail-no-server>{{ __('comail.site.no_server') }}</p>
    @else
        @if ($revealed !== [])
            @php
                $lines = array_map(static fn (array $row): string => $row['address']."\t".$row['password'], $revealed);
                $csv = "address,password\n".implode("\n", array_map(static fn (array $row): string => $row['address'].','.$row['password'], $revealed))."\n";
            @endphp
            <section class="ops-panel comail-revealed" data-comail-revealed aria-labelledby="comail-revealed-title">
                <h2 id="comail-revealed-title">{{ __('comail.mailboxes.revealed.title') }}</h2>
                <p>{{ __('comail.mailboxes.revealed.lede') }}</p>
                <div class="sites-table-wrap">
                    <table class="ops-table">
                        <thead>
                            <tr>
                                <th>{{ __('comail.mailboxes.columns.address') }}</th>
                                <th>{{ __('comail.mailboxes.revealed.password') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($revealed as $row)
                                <tr>
                                    <td>{{ $row['address'] }}</td>
                                    <td><code data-comail-revealed-password>{{ $row['password'] }}</code></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary btn-sm" data-copy-value="{{ implode("\n", $lines) }}" data-copied-label="{{ __('comail.mailboxes.revealed.copied') }}">{{ __('comail.mailboxes.revealed.copy_all') }}</button>
                    <a class="btn btn-ghost btn-sm" download="comail-sifreler.csv" href="data:text/csv;charset=utf-8,{{ rawurlencode($csv) }}">{{ __('comail.mailboxes.revealed.download') }}</a>
                </div>
            </section>
        @endif

        <form method="GET" action="{{ route('ops.comail.mailboxes') }}" class="ops-list-toolbar comail-mailbox-filters" role="search">
            <label class="ops-search">
                <span class="visually-hidden">{{ __('comail.mailboxes.search_label') }}</span>
                <input type="search" name="q" value="{{ $query }}" placeholder="{{ __('comail.mailboxes.search_placeholder') }}" autocomplete="off" maxlength="320">
            </label>
            <label>
                <span class="visually-hidden">{{ __('comail.mailboxes.filters.domain') }}</span>
                <select name="domain" class="field-input">
                    <option value="">{{ __('comail.mailboxes.filters.all_domains') }}</option>
                    @foreach ($domains as $option)
                        <option value="{{ $option }}" @selected($option === $domain)>{{ $option }}</option>
                    @endforeach
                </select>
            </label>
            <label>
                <span class="visually-hidden">{{ __('comail.mailboxes.filters.scope') }}</span>
                <select name="site" class="field-input">
                    <option value="">{{ __('comail.mailboxes.filters.scope_all') }}</option>
                    <option value="linked" @selected($scope === 'linked')>{{ __('comail.mailboxes.filters.scope_linked') }}</option>
                    <option value="none" @selected($scope === 'none')>{{ __('comail.mailboxes.filters.scope_none') }}</option>
                </select>
            </label>
            <button type="submit" class="btn btn-secondary btn-sm">{{ __('comail.mailboxes.filters.apply') }}</button>
        </form>

        @if ($mailboxError)
            <p class="ops-alert" role="alert">{{ __('comail.mailboxes.load_failed', ['message' => $mailboxError]) }}</p>
        @endif

        @if ($mailboxes === [])
            <p class="muted" data-comail-search-empty>{{ __('comail.mailboxes.no_results') }}</p>
        @else
            <p class="muted" data-comail-summary>{{ __('comail.mailboxes.summary', ['count' => count($mailboxes)]) }}</p>

            {{-- Row "Webmail'e gir" buttons post here (forms cannot nest inside the bulk form). --}}
            <form method="POST" id="comail-webmail-form" target="_blank" hidden>@csrf</form>

            {{-- A real page load (data-ops-native): the one-time passwords come back as a session flash. --}}
            <form method="POST" action="{{ route('ops.comail.mailboxes.bulk') }}" class="sites-bulk" id="comail-mailboxes-bulk" data-ops-bulk data-ops-native>
                @csrf
                <div class="sites-table-wrap">
                    <table class="ops-table" data-comail-mailboxes-table>
                        <thead>
                            <tr>
                                <th class="ops-check-col">
                                    <label class="ops-check-all">
                                        <input type="checkbox" value="1" data-ops-bulk-all>
                                        <span class="visually-hidden">{{ __('comail.mailboxes.bulk.select_all') }}</span>
                                    </label>
                                </th>
                                <th>{{ __('comail.mailboxes.columns.address') }}</th>
                                <th>{{ __('comail.mailboxes.columns.site') }}</th>
                                <th>{{ __('comail.mailboxes.columns.usage') }}</th>
                                <th>{{ __('comail.mailboxes.columns.status') }}</th>
                                <th class="ops-actions-col"><span class="visually-hidden">{{ __('comail.mailboxes.columns.actions') }}</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($mailboxes as $mailbox)
                                <tr data-comail-mailbox="{{ $mailbox['address'] }}">
                                    <td>
                                        <label>
                                            <span class="visually-hidden">{{ $mailbox['address'] }}</span>
                                            <input type="checkbox" name="mailbox_ids[]" value="{{ $mailbox['address'] }}">
                                        </label>
                                    </td>
                                    <td>
                                        {{ $mailbox['address'] }}
                                        @if ($mailbox['name'] !== '' && $mailbox['name'] !== strstr($mailbox['address'], '@', true))
                                            <br><span class="muted">{{ $mailbox['name'] }}</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if ($mailbox['site'])
                                            <a href="{{ route('ops.sites.show', $mailbox['site']) }}#site-comail">{{ $mailbox['site']->name }}</a>
                                        @else
                                            <span class="muted" data-comail-no-site>{{ __('comail.mailboxes.no_site') }}</span>
                                        @endif
                                    </td>
                                    <td>@include('ops.comail._usage')</td>
                                    <td>
                                        <span class="status-chip {{ $mailbox['active'] ? 'status-active' : '' }}">{{ $mailbox['active'] ? __('comail.mailboxes.active') : __('comail.mailboxes.inactive') }}</span>
                                        @if ($mailbox['must_change_password'])
                                            <span class="status-chip" title="{{ __('comail.mailboxes.must_change_hint') }}" data-comail-must-change>{{ __('comail.mailboxes.must_change') }}</span>
                                        @endif
                                    </td>
                                    <td class="ops-row-actions">
                                        @if ($mailbox['active'])
                                            <button
                                                type="submit"
                                                class="btn btn-ghost btn-sm"
                                                form="comail-webmail-form"
                                                formaction="{{ route('ops.comail.mailboxes.webmail', $mailbox['address']) }}"
                                                formtarget="_blank"
                                                title="{{ __('comail.mailboxes.webmail_hint') }}"
                                                data-comail-webmail="{{ $mailbox['address'] }}"
                                            >{{ __('comail.mailboxes.webmail') }}</button>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div
                    class="sites-bulk-bar"
                    data-ops-bulk-actions
                    data-bulk-total="{{ count($mailboxes) }}"
                    data-copy-selected="{{ __('comail.mailboxes.bulk.selected', ['count' => '__COUNT__']) }}"
                    data-copy-all="{{ __('comail.mailboxes.bulk.selected', ['count' => count($mailboxes)]) }}"
                >
                    <p class="sites-bulk-summary">
                        <strong data-ops-bulk-summary-text aria-live="polite"></strong>
                    </p>
                    <div class="form-actions sites-bulk-actions" role="group" aria-label="{{ __('comail.mailboxes.bulk.label') }}">
                        <details class="ops-action-menu" data-ops-action-menu data-comail-password-menu>
                            <summary class="btn btn-secondary btn-sm">{{ __('comail.mailboxes.bulk.password') }}</summary>
                            <div class="ops-action-popover comail-password-popover">
                                <fieldset class="comail-password-mode">
                                    <legend class="visually-hidden">{{ __('comail.mailboxes.bulk.password') }}</legend>
                                    <label class="field-check"><input type="radio" name="password_mode" value="each" checked> <span>{{ __('comail.mailboxes.bulk.password_each') }}</span></label>
                                    <label class="field-check"><input type="radio" name="password_mode" value="same"> <span>{{ __('comail.mailboxes.bulk.password_same') }}</span></label>
                                </fieldset>
                                <label>
                                    <span class="visually-hidden">{{ __('comail.mailboxes.revealed.password') }}</span>
                                    <input type="text" name="password" class="field-input" maxlength="128" autocomplete="off" spellcheck="false" placeholder="{{ __('comail.mailboxes.bulk.password_placeholder') }}">
                                </label>
                                <input type="hidden" name="force_password_change" value="0">
                                <label class="field-check">
                                    <input type="checkbox" name="force_password_change" value="1" checked>
                                    <span>{{ __('comail.mailboxes.bulk.force_change') }}</span>
                                </label>
                                <p class="field-hint">{{ __('comail.mailboxes.bulk.force_change_hint') }}</p>
                                <button
                                    type="submit"
                                    class="btn btn-primary btn-sm"
                                    name="action"
                                    value="password"
                                    data-confirm="{{ __('comail.mailboxes.bulk.password_confirm', ['count' => count($mailboxes)]) }}"
                                    data-confirm-template="{{ __('comail.mailboxes.bulk.password_confirm', ['count' => '__COUNT__']) }}"
                                    data-confirm-title="{{ __('comail.mailboxes.bulk.password_confirm_title') }}"
                                    data-confirm-label="{{ __('comail.mailboxes.bulk.password_submit') }}"
                                    data-confirm-danger="false"
                                >{{ __('comail.mailboxes.bulk.password_submit') }}</button>
                            </div>
                        </details>
                        <button
                            type="submit"
                            class="btn btn-ghost btn-sm"
                            name="action"
                            value="activate"
                            data-confirm="{{ __('comail.mailboxes.bulk.activate_confirm', ['count' => count($mailboxes)]) }}"
                            data-confirm-template="{{ __('comail.mailboxes.bulk.activate_confirm', ['count' => '__COUNT__']) }}"
                            data-confirm-title="{{ __('comail.mailboxes.bulk.activate_confirm_title') }}"
                            data-confirm-label="{{ __('comail.mailboxes.bulk.activate') }}"
                            data-confirm-danger="false"
                        >{{ __('comail.mailboxes.bulk.activate') }}</button>
                        <button
                            type="submit"
                            class="btn btn-ghost btn-sm"
                            name="action"
                            value="deactivate"
                            data-confirm="{{ __('comail.mailboxes.bulk.deactivate_confirm', ['count' => count($mailboxes)]) }}"
                            data-confirm-template="{{ __('comail.mailboxes.bulk.deactivate_confirm', ['count' => '__COUNT__']) }}"
                            data-confirm-title="{{ __('comail.mailboxes.bulk.deactivate_confirm_title') }}"
                            data-confirm-label="{{ __('comail.mailboxes.bulk.deactivate') }}"
                            data-confirm-danger="true"
                        >{{ __('comail.mailboxes.bulk.deactivate') }}</button>
                        <button
                            type="submit"
                            class="btn btn-ghost btn-sm btn-danger-text sites-bulk-danger"
                            name="action"
                            value="delete"
                            data-confirm="{{ __('comail.mailboxes.bulk.delete_confirm', ['count' => count($mailboxes)]) }}"
                            data-confirm-template="{{ __('comail.mailboxes.bulk.delete_confirm', ['count' => '__COUNT__']) }}"
                            data-confirm-title="{{ __('comail.mailboxes.bulk.delete_confirm_title') }}"
                            data-confirm-label="{{ __('comail.mailboxes.bulk.delete') }}"
                            data-confirm-danger="true"
                        >{{ __('comail.mailboxes.bulk.delete') }}</button>
                    </div>
                </div>
            </form>
        @endif
    @endunless
@endsection
