@php
    /** @var \App\Models\Site $site */
    $canEditMail = $canEdit ?? false;
    $mailServers = $mailServers ?? collect();
@endphp

<article class="site-card site-operation" id="site-mail" aria-labelledby="site-mail-heading">
    <div class="site-card-head">
        <h3 id="site-mail-heading">{{ __('sites.detail.mail') }} <button class="site-hint" type="button" aria-label="{{ __('mail.select_hint') }}"><span aria-hidden="true">i</span><span role="tooltip">{{ __('mail.select_hint') }}</span></button></h3>
        @if ($site->hasHostingerMailOrder())
            <span class="status-chip">{{ $site->mail_domain }}</span>
        @elseif ($site->mailServer)
            <span class="status-chip">{{ __('mail.sites.unmatched') }}</span>
        @endif
    </div>
    <p class="field-hint">{{ __('mail.select_hint') }}</p>

    @if ($canEditMail)
        @php($selectedMailId = (string) old('mail_server_id', $site->mail_server_id))
        <form method="POST" action="{{ route('ops.sites.mail', $site) }}" class="ops-form" data-ops-pending>
            @csrf
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
                <button type="submit" class="btn btn-secondary" data-pending-label="{{ __('ops.actions.working') }}">{{ __('mail.orders.save') }}</button>
            </div>
        </form>
        @if ($site->mailServer)
            <form method="POST" action="{{ route('ops.sites.mail-order', $site) }}" data-ops-pending>
                @csrf
                <button type="submit" class="btn btn-ghost btn-sm" data-pending-label="{{ __('ops.actions.working') }}">{{ __('mail.orders.refresh') }}</button>
            </form>
        @endif
    @else
        <p>
            @if ($site->mailServer)
                <a href="{{ route('ops.mail-servers.show', $site->mailServer) }}">{{ $site->mailServer->name }}</a>
                @if ($site->hasHostingerMailOrder())
                    — {{ $site->mail_domain }}
                @endif
            @else
                {{ __('sites.detail.mail_none') }}
            @endif
        </p>
    @endif
</article>
