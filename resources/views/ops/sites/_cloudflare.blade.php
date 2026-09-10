@php
    /** @var \App\Models\Site $site */
    $canEditCloudflare = $canEdit ?? false;
    $cloudflareAccounts = $cloudflareAccounts ?? collect();
    $nameservers = is_array($site->cloudflare_nameservers) ? array_values($site->cloudflare_nameservers) : [];
    $selectedCloudflare = old('cloudflare_setting_id', $site->cloudflare_setting_id);
    $hasZone = filled($site->cloudflare_zone_id);
@endphp

<article
    class="site-card site-operation"
    id="site-cloudflare"
    aria-labelledby="site-cloudflare-heading"
    data-cloudflare-panel
    data-copy-label="{{ __('sites.detail.copy_ns') }}"
    data-copied-label="{{ __('sites.detail.copied') }}"
    data-none-label="{{ __('ops.none') }}"
    data-pending-status="{{ __('sites.detail.zone_pending') }}"
    data-refresh-label="{{ __('sites.detail.refresh_zone') }}"
>
    <div class="site-card-head">
        <h3 id="site-cloudflare-heading">{{ __('sites.detail.cloudflare') }} @include('ops.dashboard._hint', ['text' => __('sites.detail.cloudflare_hint')])</h3>
        @if ($hasZone)
            <span class="status-chip" data-cloudflare-status-chip>{{ __('sites.detail.zone_added') }}</span>
        @endif
    </div>

    @if ($canEditCloudflare && $cloudflareAccounts->isNotEmpty())
        <form
            method="POST"
            action="{{ route('ops.sites.cloudflare.zone', $site) }}"
            class="ops-form"
            data-ops-pending
            data-cloudflare-zone
            data-confirm="{{ __('sites.cloudflare.confirm', ['domain' => $site->primary_domain]) }}"
            data-confirm-title="{{ __('sites.cloudflare.confirm_title') }}"
            data-confirm-label="{{ $hasZone ? __('sites.detail.refresh_zone') : __('sites.detail.add_zone') }}"
            data-confirm-danger="false"
        >
            @csrf
            <div class="site-operation-line">
                <div class="field">
                    <label class="field-label" for="site_detail_cloudflare_account">{{ __('sites.form.cloudflare_account') }}</label>
                    <select id="site_detail_cloudflare_account" class="field-input" name="cloudflare_setting_id" required>
                        @foreach ($cloudflareAccounts as $account)
                            <option value="{{ $account->id }}" @selected((string) $selectedCloudflare === (string) $account->id)>
                                {{ $account->name }}{{ $account->is_default ? ' ('.__('ops.default').')' : '' }}
                            </option>
                        @endforeach
                    </select>
                    @error('cloudflare_setting_id') <p class="field-error" role="alert">{{ $message }}</p> @enderror
                </div>
                <button type="submit" class="btn btn-secondary" data-pending-label="{{ __('ops.actions.working') }}" data-cloudflare-submit>
                    {{ $hasZone ? __('sites.detail.refresh_zone') : __('sites.detail.add_zone') }}
                </button>
            </div>
        </form>
    @elseif ($cloudflareAccounts->isEmpty())
        <p class="muted">{!! __('sites.form.cloudflare_empty', ['link' => '<a href="'.route('ops.cloudflare.index').'">'.e(__('sites.form.cloudflare_link')).'</a>']) !!}</p>
    @elseif ($site->cloudflareAccount)
        <p>{{ $site->cloudflareAccount->name }}</p>
    @endif

    <div class="ops-ns-block">
        <div class="ops-ns-head" data-copy-all-label="{{ __('sites.detail.copy_all_ns') }}">
            <span class="field-label">{{ __('sites.detail.nameservers') }} @include('ops.dashboard._hint', ['text' => __('sites.detail.nameservers_hint')])</span>
            @if ($nameservers !== [])
                <button type="button" class="btn btn-ghost btn-sm" data-copy-target="#site-ns-all" data-copied-label="{{ __('sites.detail.copied') }}" data-cloudflare-copy-all>{{ __('sites.detail.copy_all_ns') }}</button>
            @endif
        </div>
        <pre id="site-ns-all" class="ops-ns-all" hidden>{{ implode("\n", $nameservers) }}</pre>
        <ul class="ops-ns-list" data-cloudflare-ns-list>
            @forelse ($nameservers as $index => $ns)
                <li class="ops-ns-row">
                    <code id="site-ns-{{ $index }}">{{ $ns }}</code>
                    <button type="button" class="btn btn-ghost btn-sm" data-copy-target="#site-ns-{{ $index }}" data-copied-label="{{ __('sites.detail.copied') }}">{{ __('sites.detail.copy_ns') }}</button>
                </li>
            @empty
                <li class="muted" data-cloudflare-ns-empty>{{ __('ops.none') }}</li>
            @endforelse
        </ul>
    </div>

    <dl class="site-fact-list is-compact">
        <div>
            <dt>{{ __('sites.detail.zone') }}</dt>
            <dd data-cloudflare-zone-id>@if (filled($site->cloudflare_zone_id))<code>{{ $site->cloudflare_zone_id }}</code>@else{{ __('sites.detail.not_linked') }}@endif</dd>
        </div>
        <div>
            <dt>{{ __('sites.detail.zone_status') }}</dt>
            <dd data-cloudflare-zone-status>{{ __('ops.none') }}</dd>
        </div>
        <div>
            <dt>{{ __('sites.detail.dns_applied') }}</dt>
            <dd data-cloudflare-dns>{{ $site->dns_applied_at?->toDateTimeString() ?? __('ops.none') }}</dd>
        </div>
    </dl>
</article>
