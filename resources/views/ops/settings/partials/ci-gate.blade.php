{{-- CI gate mode: what CI-gated sites and themes do when GitHub CI is on, off or paused. --}}
<section
    class="settings-panel"
    aria-labelledby="ci-gate-heading"
    data-settings-section
    data-settings-haystack="{{ \App\Support\Ops\SettingsJump::haystackFor('ci_gate') }}"
>
    <h2 id="ci-gate-heading">{{ __('settings.ci_gate.title') }}</h2>
    <p class="field-hint">{{ __('settings.ci_gate.lede') }}</p>

    <p>
        {{ __('settings.ci_gate.current') }}:
        <span class="status-chip {{ $ciGateMode === \App\Enums\CiGateMode::Enforce ? 'status-active' : 'status-warning' }}">{{ $ciGateMode->label() }}</span>
        @if ($ciGateSetting?->updated_at)
            <span class="field-hint">{{ __('settings.ci_gate.updated', [
                'time' => $ciGateSetting->updated_at->timezone(config('app.timezone'))->format('d.m.Y H:i'),
                'user' => $ciGateSetting->updatedBy?->name ?? __('ops.none'),
            ]) }}</span>
        @endif
    </p>

    @if ($canEditAutomation)
        <form method="POST" action="{{ route('ops.settings.ci_gate') }}" class="ops-form settings-form">
            @csrf
            <fieldset class="field">
                <legend class="visually-hidden">{{ __('settings.ci_gate.title') }}</legend>
                @foreach (\App\Enums\CiGateMode::cases() as $mode)
                    <label class="field-check">
                        <input type="radio" name="mode" value="{{ $mode->value }}" @checked($ciGateMode === $mode)>
                        <strong>{{ $mode->label() }}</strong>
                    </label>
                    <p class="field-hint">{{ __('settings.ci_gate.modes.'.$mode->value.'.hint') }}</p>
                @endforeach
            </fieldset>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">{{ __('settings.ci_gate.save') }}</button>
            </div>
        </form>
    @else
        <p class="field-hint">{{ __('settings.ci_gate.modes.'.$ciGateMode->value.'.hint') }}</p>
        <p class="field-hint">{{ __('ops.super_admin_only') }}</p>
    @endif
</section>
