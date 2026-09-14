@extends('layouts.ops')

@section('title', __('settings.title'))

@section('content_class', 'ops-content-wide')

@section('breadcrumbs')
    <span>{{ __('settings.title') }}</span>
@endsection

@section('content')
    @php
        $jumpSections = $settingsJump ?? \App\Support\Ops\SettingsJump::sections();
        $envKeys = $envKeyHaystack ?? '';
    @endphp
    <p class="page-lede">{{ __('settings.lede') }}</p>

    <nav class="settings-jump" aria-label="{{ __('settings.jump.label') }}" data-ops-settings-jump>
        <div class="settings-jump-search" role="search">
            <label class="visually-hidden" for="settings-jump-q">{{ __('settings.jump.search') }}</label>
            <input
                id="settings-jump-q"
                class="field-input"
                type="search"
                data-ops-settings-search
                placeholder="{{ __('settings.jump.search') }}"
                autocomplete="off"
            >
        </div>
        <div class="settings-jump-links">
            @foreach ($jumpSections as $section)
                <a
                    href="#{{ $section['hash'] }}"
                    data-settings-jump
                    data-settings-haystack="{{ \App\Support\Ops\SettingsJump::haystack($section, $section['id'] === 'env' ? $envKeys : '') }}"
                >{{ $section['label'] }}</a>
            @endforeach
        </div>
        <p class="settings-jump-empty" data-settings-empty hidden>{{ __('settings.jump.empty') }}</p>
    </nav>

    @include('ops.settings.partials.env-defaults')

    @include('ops.settings.partials.github')

    <section
        class="settings-panel"
        aria-labelledby="customer-defaults-heading"
        data-settings-section
        data-settings-haystack="{{ \App\Support\Ops\SettingsJump::haystackFor('defaults') }}"
    >
        <h2 id="customer-defaults-heading">{{ __('settings.defaults') }}</h2>
        <p class="field-hint">{{ __('settings.defaults_hint') }}</p>

        <div class="ops-form settings-form">
            <div class="field">
                <span class="field-label">{{ __('settings.repository') }}</span>
                <input class="field-input" type="text" value="{{ $repository }}" readonly>
            </div>
            <div class="field">
                <span class="field-label">{{ __('settings.compose') }}</span>
                <input class="field-input" type="text" value="{{ $composeFile }}" readonly>
            </div>
        </div>
    </section>

    <section
        class="settings-panel"
        aria-labelledby="system-coolify-heading"
        data-settings-section
        data-settings-haystack="{{ \App\Support\Ops\SettingsJump::haystackFor('coolify') }}"
    >
        <h2 id="system-coolify-heading">{{ __('settings.coolify.title') }}</h2>
        <p class="field-hint">{{ __('settings.coolify.lede') }}</p>
        <div class="form-actions">
            <a class="btn btn-secondary" href="{{ route('ops.coolify.index') }}">{{ __('settings.coolify.open') }}</a>
        </div>
    </section>
@endsection
