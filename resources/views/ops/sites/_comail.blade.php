@php
    /** @var \App\Models\Site $site */
    $canEditComail = $canEdit ?? false;
    $comailServer = $comailServer ?? \App\Models\MailServer::comail();
    $mailPackages = $mailPackages ?? \App\Models\MailPackage::query()->orderBy('sort')->get();
    $comailRows = $site->relationLoaded('comailDomains') ? $site->comailDomains : $site->comailDomains()->get();
    $currentPackage = \App\Models\MailPackage::forSite($site);
    $availableComail = $comailServer
        ? array_values(array_diff(\App\Services\Comail\ComailDomainProvisioner::eligibleHosts($site), $comailRows->pluck('domain')->all()))
        : [];
    $comailState = $site->comailConfigureState();
    $comailReason = (string) ($site->comail_configure_error ?? '');
    $comailReasonKey = str_starts_with($comailReason, 'http_') ? 'http_error' : $comailReason;
    $comailReasonLabel = $comailReason === ''
        ? ''
        : (\Illuminate\Support\Facades\Lang::has("mail.configure_state.reasons.$comailReasonKey")
            ? __("mail.configure_state.reasons.$comailReasonKey").(str_starts_with($comailReason, 'http_') ? ' '.substr($comailReason, 5) : '')
            : $comailReason);
@endphp

<article class="site-card site-operation" id="site-comail" aria-labelledby="site-comail-heading">
    <div class="site-card-head">
        <h3 id="site-comail-heading">{{ __('comail.site.title') }} @include('ops.dashboard._hint', ['text' => __('comail.site.hint')])</h3>
        <span class="status-chip" data-comail-package="{{ $currentPackage->slug }}">{{ $currentPackage->name }}</span>
        @if ($comailState !== 'none')
            <span class="status-chip" data-comail-configure-state="{{ $comailState }}">{{ __('mail.configure_state.'.$comailState) }}</span>
        @endif
    </div>

    @if (! $comailServer)
        <p class="muted" data-comail-no-server>
            {{ __('comail.site.no_server') }}
            <a href="{{ route('ops.mail-servers.index') }}">{{ __('ops.nav.mail_servers') }}</a>
        </p>
    @else
        @if ($comailState === 'failed')
            <p class="ops-alert ops-alert-warning" role="status" data-comail-configure-error>
                {{ __('mail.configure_state.failed') }}@if ($comailReasonLabel !== '') ({{ $comailReasonLabel }})@endif
                · {{ $site->comail_configure_failed_at?->diffForHumans() }}
                @if (filled($site->comail_configure_message))
                    <br>{{ __('mail.configure_state.cms_said', ['message' => $site->comail_configure_message]) }}
                @endif
            </p>
        @endif

        @if ($canEditComail)
            <form method="POST" action="{{ route('ops.sites.comail.package', $site) }}" class="ops-form" data-ops-pending>
                @csrf
                <div class="site-operation-line">
                    <div class="field">
                        <label class="field-label" for="site_comail_package">{{ __('comail.site.package') }}</label>
                        <select id="site_comail_package" class="field-input" name="mail_package_id">
                            <option value="">{{ __('comail.site.package_default', ['name' => \App\Models\MailPackage::default()->name]) }}</option>
                            @foreach ($mailPackages as $mailPackage)
                                <option value="{{ $mailPackage->id }}" @selected((string) $site->mail_package_id === (string) $mailPackage->id)>
                                    {{ $mailPackage->name }}@unless ($mailPackage->is_active) ({{ __('comail.packages.inactive_badge') }})@endunless
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <button type="submit" class="btn btn-secondary btn-sm" data-pending-label="{{ __('ops.actions.working') }}">{{ __('comail.site.package_save') }}</button>
                </div>
            </form>
        @endif

        @if ($comailRows->isEmpty())
            <p class="muted">{{ __('comail.site.empty') }}</p>
        @else
            <div class="sites-table-wrap">
                <table class="ops-table" data-comail-domains>
                    <thead>
                        <tr>
                            <th>{{ __('comail.site.columns.domain') }}</th>
                            <th>{{ __('comail.site.columns.status') }}</th>
                            <th>{{ __('comail.site.columns.dns') }}</th>
                            <th>{{ __('comail.site.columns.mx') }}</th>
                            @if ($canEditComail)
                                <th>{{ __('comail.site.columns.actions') }}</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($comailRows as $row)
                            @php($summary = $row->dnsSummary())
                            <tr data-comail-domain="{{ $row->domain }}">
                                <td>
                                    {{ $row->domain }}
                                    @if ($row->is_primary)
                                        <span class="status-chip">{{ __('comail.site.primary') }}</span>
                                    @endif
                                    @if (filled($row->last_error))
                                        <br><span class="muted">{{ \Illuminate\Support\Str::limit($row->last_error, 120) }}</span>
                                    @endif
                                </td>
                                <td><span class="status-chip" data-comail-status="{{ $row->status }}">{{ __('comail.statuses.'.$row->status) }}</span></td>
                                <td>
                                    @if ($row->dns_checked_at)
                                        <span class="status-chip" data-comail-dns="{{ $summary['ok'] }}/{{ $summary['total'] }}">{{ __('comail.site.dns_summary', ['ok' => $summary['ok'], 'total' => $summary['total']]) }}</span>
                                        <span class="muted">{{ $row->dns_checked_at->diffForHumans() }}</span>
                                    @else
                                        <span class="muted">{{ __('comail.site.dns_never') }}</span>
                                    @endif
                                </td>
                                <td><span class="status-chip" data-comail-mx="{{ $row->mxActive() ? 'active' : 'waiting' }}">{{ $row->mxActive() ? __('comail.site.mx_active') : __('comail.site.mx_waiting') }}</span></td>
                                @if ($canEditComail)
                                    <td class="site-mail-request-actions">
                                        <form method="POST" action="{{ route('ops.sites.comail.dns.check', [$site, $row]) }}" data-ops-pending>
                                            @csrf
                                            <button type="submit" class="btn btn-ghost btn-sm" data-pending-label="{{ __('ops.actions.working') }}">{{ __('comail.site.check_dns') }}</button>
                                        </form>
                                        <form method="POST" action="{{ route('ops.sites.comail.dns.apply', [$site, $row]) }}" data-ops-pending>
                                            @csrf
                                            <button type="submit" class="btn btn-ghost btn-sm" data-pending-label="{{ __('ops.actions.working') }}">{{ __('comail.site.apply_dns') }}</button>
                                        </form>
                                        @unless ($row->mxActive())
                                            <form method="POST" action="{{ route('ops.sites.comail.activate', [$site, $row]) }}" data-ops-pending data-confirm="{{ __('comail.site.activate_confirm', ['domain' => $row->domain]) }}" data-confirm-title="{{ __('comail.site.activate_title') }}" data-confirm-label="{{ __('comail.site.activate') }}" data-confirm-danger="true">
                                                @csrf
                                                <button type="submit" class="btn btn-secondary btn-sm">{{ __('comail.site.activate') }}</button>
                                            </form>
                                        @endunless
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        @if ($canEditComail)
            <div class="site-operation-line">
                @if ($availableComail !== [])
                    <form method="POST" action="{{ route('ops.sites.comail.domains.store', $site) }}" class="ops-form" data-ops-pending>
                        @csrf
                        <div class="site-operation-line">
                            <div class="field">
                                <label class="field-label" for="site_comail_domain">{{ __('comail.site.add_domain') }}</label>
                                <select id="site_comail_domain" class="field-input" name="domain">
                                    @foreach ($availableComail as $host)
                                        <option value="{{ $host }}">{{ $host }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <button type="submit" class="btn btn-secondary btn-sm" data-pending-label="{{ __('ops.actions.working') }}">{{ __('comail.site.add_domain_button') }}</button>
                        </div>
                    </form>
                @endif
                @if ($comailRows->isNotEmpty())
                    <form method="POST" action="{{ route('ops.sites.comail.configure', $site) }}" data-ops-pending data-comail-configure-resend>
                        @csrf
                        <button type="submit" class="btn btn-ghost btn-sm" data-pending-label="{{ __('ops.actions.working') }}">{{ __('mail.configure_state.resend') }}</button>
                    </form>
                @endif
            </div>
        @endif
    @endif
</article>
