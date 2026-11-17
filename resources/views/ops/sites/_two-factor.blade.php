@php
    /** @var \App\Models\Site $site */
    $canEditTwoFactor = $canEdit ?? false;
    $twoFactorErrors = $errors->getBag('two_factor');
@endphp

<article id="site-two-factor" class="site-card site-operation" aria-labelledby="site-two-factor-heading">
    <div class="site-card-head">
        <h3 id="site-two-factor-heading">
            {{ __('two_factor.title') }} @include('ops.dashboard._hint', ['text' => __('two_factor.lede')])
        </h3>
        @if ($canEditTwoFactor)
            <div class="form-actions">
                <form method="POST" action="{{ route('ops.sites.two-factor.pull', $site) }}" data-ops-pending>@csrf<button type="submit" class="btn btn-ghost btn-sm" data-pending-label="{{ __('ops.actions.working') }}">{{ __('two_factor.actions.pull') }}</button></form>
            </div>
        @endif
    </div>

    @if ($twoFactorErrors->any())
        <p class="form-error" role="alert">{{ $twoFactorErrors->first() }}</p>
    @endif

    @if ($canEditTwoFactor)
        <form method="POST" action="{{ route('ops.sites.two-factor.update', $site) }}" class="form-stack" data-ops-pending>
            @csrf
            <label class="form-field">
                <span>{{ __('two_factor.fields.required') }}</span>
                <select name="required">
                    <option value="0">{{ __('two_factor.states.optional') }}</option>
                    <option value="1">{{ __('two_factor.states.required') }}</option>
                </select>
            </label>
            <label class="form-field">
                <span>{{ __('two_factor.fields.grace_days') }}</span>
                <input type="number" name="grace_days" min="0" max="60" value="7" inputmode="numeric">
            </label>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary btn-sm" data-pending-label="{{ __('ops.actions.working') }}">{{ __('two_factor.actions.save') }}</button>
            </div>
        </form>
    @else
        <p class="site-note">{{ __('two_factor.lede') }}</p>
    @endif
</article>
