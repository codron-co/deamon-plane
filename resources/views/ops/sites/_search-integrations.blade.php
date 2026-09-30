@php
    /** @var \App\Models\Site $site */
    /** @var \App\Models\SiteSearchIntegration|null $searchIntegration */
    $searchIntegration = $site->searchIntegration;
    $canEditSearch = $canEdit ?? false;
    $searchErrors = $errors->getBag('searchIntegrations');
    $pushState = $searchIntegration?->pushState() ?? 'never';
    $pushTone = match ($pushState) {
        'ok' => 'ok',
        'failed' => 'error',
        'pending' => 'deploying',
        default => 'unknown',
    };
    $moduleState = $searchIntegration?->site_module_enabled === null ? 'unknown' : ($searchIntegration->site_module_enabled ? 'on' : 'off');
    $reported = is_array($site->last_health_payload['search_integrations'] ?? null) ? $site->last_health_payload['search_integrations'] : null;
    $value = static fn (string $column): string => (string) old($column, $searchIntegration?->getAttribute($column) ?? '');
    $mode = old('google_mode', $searchIntegration?->google_mode ?? 'off');
    $enableModule = (bool) old('enable_module', $searchIntegration?->enable_module ?? true);
    $textFields = [
        'verification' => ['google_verification', 'google_file_token', 'bing_verification', 'yandex_verification'],
        'measurement' => ['ga4_id', 'gtm_id', 'yandex_metrica_id', 'clarity_id'],
    ];
@endphp

<div class="site-section-heading">
    <h2 id="site-search-heading">{{ __('search_integrations.title') }} @include('ops.dashboard._hint', ['text' => __('search_integrations.lede')])</h2>
</div>

<article class="site-card site-operation" aria-labelledby="site-search-status-heading">
    <div class="site-card-head">
        <h3 id="site-search-status-heading">
            <span class="status-chip status-{{ $pushTone }}" data-search-push-state="{{ $pushState }}">{{ __('search_integrations.push_states.'.$pushState) }}</span>
        </h3>
        @if ($canEditSearch)
            <div class="form-actions">
                <form
                    method="POST"
                    action="{{ route('ops.sites.search-integrations.pull', $site) }}"
                    data-ops-pending
                    data-confirm="{{ __('search_integrations.pull_confirm', ['name' => $site->name]) }}"
                    data-confirm-title="{{ __('search_integrations.pull_confirm_title') }}"
                    data-confirm-label="{{ __('search_integrations.actions.pull') }}"
                    data-confirm-danger="false"
                >@csrf<button type="submit" class="btn btn-secondary btn-sm" data-pending-label="{{ __('ops.actions.working') }}">{{ __('search_integrations.actions.pull') }}</button></form>
                @if ($searchIntegration !== null && $site->hasAgentSecret())
                    <form method="POST" action="{{ route('ops.sites.search-integrations.push', $site) }}" data-ops-pending>@csrf<button type="submit" class="btn btn-ghost btn-sm" data-pending-label="{{ __('ops.actions.working') }}">{{ __('search_integrations.actions.push') }}</button></form>
                @endif
            </div>
        @endif
    </div>

    <dl class="site-technical-list">
        <div><dt>{{ __('search_integrations.last_push') }}</dt><dd><x-ops.freshness :at="$searchIntegration?->pushed_at" :missing="__('ops.never')" :mark-stale="false" /></dd></div>
        <div><dt>{{ __('search_integrations.last_change') }}</dt><dd><x-ops.freshness :at="$searchIntegration?->changed_at" :missing="__('ops.none')" :mark-stale="false" /></dd></div>
        <div><dt>{{ __('search_integrations.last_pull') }}</dt><dd><x-ops.freshness :at="$searchIntegration?->pulled_at" :missing="__('ops.never')" :mark-stale="false" /></dd></div>
        <div><dt>{{ __('search_integrations.module') }}</dt><dd>{{ __('search_integrations.module_states.'.$moduleState) }}</dd></div>
        @if ($reported !== null)
            <div>
                <dt>{{ __('search_integrations.site_reports') }}</dt>
                <dd>{{ __('search_integrations.site_reports_value', [
                    'gsc' => ($reported['google_verification'] ?? false) ? __('search_integrations.yes') : __('search_integrations.no'),
                    'measurement' => __('search_integrations.modes.'.(in_array($reported['measurement'] ?? 'off', \App\Models\SiteSearchIntegration::MODES, true) ? $reported['measurement'] : 'off')),
                ]) }}</dd>
            </div>
        @endif
    </dl>

    @if ($pushState === 'failed')
        <p class="ops-alert" role="alert" data-search-push-error>{{ \App\Services\SearchIntegrations\SiteSearchIntegrationsPresenter::errorLabel($searchIntegration?->push_error) }}</p>
    @endif
    @if (! $site->hasAgentSecret())
        <p class="ops-alert ops-alert-warning" role="status">{{ __('search_integrations.no_agent') }}</p>
    @endif
    @if ($searchIntegration === null)
        <p class="site-note">{{ __('search_integrations.empty') }}</p>
    @endif
</article>

@if ($canEditSearch)
    <form method="POST" action="{{ route('ops.sites.search-integrations.update', $site) }}" class="ops-form site-card site-operation" data-ops-pending data-search-integrations-form>
        @csrf

        @if ($searchErrors->any())
            <p class="ops-alert" role="alert">{{ $searchErrors->first() }}</p>
        @endif

        <section class="ops-form-section" aria-labelledby="site-search-verification-heading">
            <h3 id="site-search-verification-heading">{{ __('search_integrations.verification_heading') }} @include('ops.dashboard._hint', ['text' => __('search_integrations.verification_hint')])</h3>
            @foreach ($textFields['verification'] as $field)
                <div class="field">
                    <span class="field-label"><label for="search_{{ $field }}">{{ __('search_integrations.fields.'.$field) }}</label> @include('ops.dashboard._hint', ['text' => __('search_integrations.hints.'.$field)])</span>
                    <input id="search_{{ $field }}" class="field-input" type="text" name="{{ $field }}" value="{{ $value($field) }}" autocomplete="off" spellcheck="false" maxlength="500">
                    @if ($searchErrors->has($field)) <p class="field-error">{{ $searchErrors->first($field) }}</p> @endif
                </div>
            @endforeach
        </section>

        <section class="ops-form-section" aria-labelledby="site-search-measurement-heading">
            <h3 id="site-search-measurement-heading">{{ __('search_integrations.measurement_heading') }} @include('ops.dashboard._hint', ['text' => __('search_integrations.measurement_hint')])</h3>
            <div class="field">
                <label class="field-label" for="search_google_mode">{{ __('search_integrations.fields.google_mode') }}</label>
                <select id="search_google_mode" class="field-input" name="google_mode">
                    @foreach (\App\Models\SiteSearchIntegration::MODES as $modeOption)
                        <option value="{{ $modeOption }}" @selected($mode === $modeOption)>{{ __('search_integrations.modes.'.$modeOption) }}</option>
                    @endforeach
                </select>
                @if ($searchErrors->has('google_mode')) <p class="field-error">{{ $searchErrors->first('google_mode') }}</p> @endif
            </div>
            @foreach ($textFields['measurement'] as $field)
                <div class="field">
                    <span class="field-label"><label for="search_{{ $field }}">{{ __('search_integrations.fields.'.$field) }}</label> @include('ops.dashboard._hint', ['text' => __('search_integrations.hints.'.$field)])</span>
                    <input id="search_{{ $field }}" class="field-input" type="text" name="{{ $field }}" value="{{ $value($field) }}" autocomplete="off" spellcheck="false" maxlength="64">
                    @if ($searchErrors->has($field)) <p class="field-error">{{ $searchErrors->first($field) }}</p> @endif
                </div>
            @endforeach
            <div class="field">
                <input type="hidden" name="enable_module" value="0">
                <label class="field-check">
                    <input type="checkbox" name="enable_module" value="1" @checked($enableModule)>
                    <span>{{ __('search_integrations.fields.enable_module') }}</span>
                </label>
            </div>
        </section>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary btn-sm" data-pending-label="{{ __('ops.actions.working') }}">{{ __('search_integrations.actions.save') }}</button>
        </div>
    </form>
@else
    <article class="site-card site-operation">
        <dl class="site-technical-list">
            @foreach (array_merge($textFields['verification'], ['google_mode'], $textFields['measurement']) as $field)
                @php $shown = (string) ($searchIntegration?->getAttribute($field) ?? ''); @endphp
                <div>
                    <dt>{{ __('search_integrations.fields.'.$field) }}</dt>
                    <dd>
                        @if ($field === 'google_mode')
                            {{ __('search_integrations.modes.'.($shown !== '' ? $shown : 'off')) }}
                        @elseif ($shown !== '')
                            <code>{{ $shown }}</code>
                        @else
                            {{ __('ops.none') }}
                        @endif
                    </dd>
                </div>
            @endforeach
        </dl>
        <p class="site-note">{{ __('search_integrations.readonly') }}</p>
    </article>
@endif
