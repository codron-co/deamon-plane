@extends('layouts.ops')

@section('title', __('settings.title'))

@section('content')
    <p class="page-lede">{{ __('settings.lede') }}</p>

    @include('ops.settings.partials.github')

    <section class="settings-panel" aria-labelledby="customer-defaults-heading">
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
@endsection
