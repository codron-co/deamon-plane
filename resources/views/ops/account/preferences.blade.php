@extends('layouts.ops')

@section('title', __('account.preferences'))

@section('actions')
    <a class="btn btn-ghost btn-sm" href="{{ route('ops.account.show') }}">{{ __('account.title') }}</a>
@endsection

@section('breadcrumbs')
    <a href="{{ route('ops.account.show') }}">{{ __('account.title') }}</a>
    <span aria-hidden="true">/</span>
    <span>{{ __('account.preferences') }}</span>
@endsection

@section('content')
    <p class="page-lede">{{ __('account.preferences_lede') }}</p>

    <div class="ops-form-stack">
        <form method="POST" action="{{ route('ops.account.preferences.update') }}" class="ops-form">
            @csrf
            @method('PUT')

            <section class="ops-form-section" aria-labelledby="pref-language-heading">
                <h2 id="pref-language-heading">{{ __('account.language') }}</h2>
                <div class="field">
                    <label class="field-label" for="pref_locale">{{ __('account.language') }}</label>
                    <p class="field-hint">{{ __('account.language_hint') }}</p>
                    <select id="pref_locale" class="field-input" name="locale" required>
                        @foreach ($locales as $localeOption)
                            <option value="{{ $localeOption }}" @selected(old('locale', $user->localeValue()) === $localeOption)>
                                {{ __('ops.locale.'.$localeOption) }}
                            </option>
                        @endforeach
                    </select>
                    @error('locale') <p class="field-error">{{ $message }}</p> @enderror
                </div>
            </section>

            <section class="ops-form-section" aria-labelledby="pref-appearance-heading">
                <h2 id="pref-appearance-heading">{{ __('account.appearance') }}</h2>
                <div class="field">
                    <label class="field-label" for="pref_appearance">{{ __('account.appearance') }}</label>
                    <p class="field-hint">{{ __('account.appearance_hint') }}</p>
                    <select id="pref_appearance" class="field-input" name="appearance" required>
                        @foreach ($appearances as $appearance)
                            <option value="{{ $appearance->value }}" @selected(old('appearance', $user->appearanceValue()) === $appearance->value)>
                                {{ $appearance->label() }}
                            </option>
                        @endforeach
                    </select>
                    @error('appearance') <p class="field-error">{{ $message }}</p> @enderror
                </div>
            </section>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">{{ __('account.save_preferences') }}</button>
            </div>
        </form>
    </div>
@endsection
