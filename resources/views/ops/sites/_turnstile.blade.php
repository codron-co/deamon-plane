@php
    /** @var \App\Models\Site $site */
    /** @var \App\Models\SiteTurnstile|null $turnstile */
    $turnstile = $site->turnstile;
    $canEditTurnstile = $canEdit ?? false;
    $turnstileErrors = $errors->getBag('turnstile');
    $pushState = $turnstile?->pushState() ?? 'never';
    $pushTone = match (true) {
        $turnstile === null => 'unknown',
        ! $turnstile->enabled => 'unknown',
        $turnstile->sync_error !== null => 'error',
        $pushState === 'ok' => 'ok',
        $pushState === 'failed' => 'error',
        $pushState === 'pending' => 'deploying',
        default => 'unknown',
    };
    $chip = match (true) {
        $turnstile === null => __('turnstile.states.not_enrolled'),
        ! $turnstile->enabled => __('turnstile.states.off'),
        default => __('turnstile.push_states.'.$pushState),
    };
    $widgets = $turnstile?->widgets() ?? [];
    $reported = $site->reportedTurnstile();
    $mode = old('mode', $turnstile?->mode ?? \App\Models\SiteTurnstile::MODE_INTERACTION_ONLY);
    $forms = old('forms', $turnstile?->formList() ?? \App\Models\SiteTurnstile::FORMS);
    $enabled = (bool) old('enabled', $turnstile?->enabled ?? true);
@endphp

<article id="site-turnstile" class="site-card site-operation" aria-labelledby="site-turnstile-heading">
    <div class="site-card-head">
        <h3 id="site-turnstile-heading">
            {{ __('turnstile.title') }} @include('ops.dashboard._hint', ['text' => __('turnstile.lede')])
            <span class="status-chip status-{{ $pushTone }}" data-turnstile-state="{{ $turnstile === null ? 'not_enrolled' : $pushState }}">{{ $chip }}</span>
        </h3>
        @if ($canEditTurnstile)
            <div class="form-actions">
                <form method="POST" action="{{ route('ops.sites.turnstile.sync', $site) }}" data-ops-pending>@csrf<button type="submit" class="btn {{ $turnstile === null ? 'btn-secondary' : 'btn-ghost' }} btn-sm" data-pending-label="{{ __('ops.actions.working') }}">{{ $turnstile === null ? __('turnstile.actions.enroll') : __('turnstile.actions.sync') }}</button></form>
            </div>
        @endif
    </div>

    @if ($turnstile === null)
        <p class="site-note">{{ __('turnstile.empty') }}</p>
    @else
        <dl class="site-fact-list is-compact">
            <div><dt>{{ __('turnstile.facts.account') }}</dt><dd>{{ $turnstile->cloudflareAccount?->name ?? __('ops.none') }}</dd></div>
            <div><dt>{{ __('turnstile.facts.hostnames') }}</dt><dd>{{ $turnstile->hostnameList() !== [] ? implode(', ', $turnstile->hostnameList()) : __('ops.none') }}</dd></div>
            <div><dt>{{ __('turnstile.facts.widgets') }}</dt><dd>{{ $widgets !== [] ? collect($widgets)->map(fn ($widget) => $widget->name)->implode(', ') : __('ops.none') }}</dd></div>
            <div><dt>{{ __('turnstile.facts.last_sync') }}</dt><dd><x-ops.freshness :at="$turnstile->synced_at" :missing="__('ops.never')" :mark-stale="false" /></dd></div>
            <div><dt>{{ __('turnstile.facts.last_push') }}</dt><dd><x-ops.freshness :at="$turnstile->pushed_at" :missing="__('ops.never')" :mark-stale="false" /></dd></div>
            @if ($reported !== null)
                <div>
                    <dt>{{ __('turnstile.facts.site_reports') }}</dt>
                    <dd>{{ __('turnstile.site_reports_value', [
                        'state' => $reported['ready'] ? __('turnstile.push_states.ok') : __('turnstile.states.off'),
                        'mode' => __('turnstile.modes.'.(in_array($reported['mode'], \App\Models\SiteTurnstile::MODES, true) ? $reported['mode'] : 'interaction-only')),
                        'source' => __('turnstile.sources.'.($reported['source'] ?? 'none')),
                    ]) }}</dd>
                </div>
            @endif
        </dl>

        @if ($turnstile->sync_error !== null)
            <p class="ops-alert" role="alert" data-turnstile-sync-error>{{ \App\Services\Turnstile\TurnstilePresenter::errorLabel($turnstile->sync_error) }}</p>
        @endif
        @if ($pushState === 'failed')
            <p class="ops-alert" role="alert" data-turnstile-push-error>{{ \App\Services\Turnstile\TurnstilePresenter::errorLabel($turnstile->push_error) }}</p>
        @endif
        @if (! $site->hasAgentSecret())
            <p class="ops-alert ops-alert-warning" role="status">{{ __('turnstile.no_agent') }}</p>
        @endif
    @endif

    @if ($canEditTurnstile && $turnstile !== null)
        <form method="POST" action="{{ route('ops.sites.turnstile.update', $site) }}" class="ops-form" data-ops-pending data-turnstile-form>
            @csrf

            @if ($turnstileErrors->any())
                <p class="ops-alert" role="alert">{{ $turnstileErrors->first() }}</p>
            @endif

            <div class="field">
                <input type="hidden" name="enabled" value="0">
                <label class="field-check">
                    <input type="checkbox" name="enabled" value="1" @checked($enabled)>
                    <span>{{ __('turnstile.fields.enabled') }}</span>
                </label>
            </div>

            <div class="field">
                <label class="field-label" for="turnstile_mode">{{ __('turnstile.fields.mode') }}</label>
                <select id="turnstile_mode" class="field-input" name="mode">
                    @foreach (\App\Models\SiteTurnstile::MODES as $modeOption)
                        <option value="{{ $modeOption }}" @selected($mode === $modeOption)>{{ __('turnstile.modes.'.$modeOption) }}</option>
                    @endforeach
                </select>
                <p class="field-hint">{{ __('turnstile.mode_hints.'.(in_array($mode, \App\Models\SiteTurnstile::MODES, true) ? $mode : 'interaction-only')) }}</p>
            </div>

            <fieldset class="field">
                <legend class="field-label">{{ __('turnstile.fields.forms') }}</legend>
                @foreach (\App\Models\SiteTurnstile::FORMS as $group)
                    <label class="field-check">
                        <input type="checkbox" name="forms[]" value="{{ $group }}" @checked(in_array($group, (array) $forms, true))>
                        <span>{{ __('turnstile.forms_labels.'.$group) }}</span>
                    </label>
                @endforeach
            </fieldset>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary btn-sm" data-pending-label="{{ __('ops.actions.working') }}">{{ __('turnstile.actions.save') }}</button>
            </div>
        </form>
    @elseif ($turnstile !== null)
        <dl class="site-technical-list">
            <div><dt>{{ __('turnstile.fields.mode') }}</dt><dd>{{ __('turnstile.modes.'.$turnstile->mode) }}</dd></div>
            <div><dt>{{ __('turnstile.fields.forms') }}</dt><dd>{{ collect($turnstile->formList())->map(fn ($group) => __('turnstile.forms_labels.'.$group))->implode(', ') }}</dd></div>
        </dl>
        <p class="site-note">{{ __('turnstile.readonly') }}</p>
    @endif
</article>
