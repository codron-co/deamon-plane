@extends('layouts.ops')

@section('title', __('deskron.title'))

@section('content')
    <p class="page-lede">{{ __('deskron.lede') }}</p>

    <div class="ops-mail-state-row">
        <span class="ops-mail-state-title">{{ __('deskron.state_label') }}</span>
        <span class="status-chip {{ $settings->isReady() ? 'status-active' : '' }}">
            {{ $settings->isReady() ? __('deskron.state.ready') : __('deskron.state.missing') }}
        </span>
        <span class="ops-mail-state-hint">{{ $settings->isReady() ? __('deskron.state_hint.ready') : __('deskron.state_hint.missing') }}</span>
    </div>

    <form method="POST" action="{{ route('ops.deskron.update') }}" class="ops-form ops-form-stack">
        @csrf
        @method('PUT')

        @if ($errors->any())
            <p class="ops-alert" role="alert">{{ __('deskron.form_errors') }}</p>
            <ul class="ops-alert-list">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        @endif

        <article class="site-card">
            <h2>{{ __('deskron.application.title') }}</h2>
            <p class="field-hint">{{ __('deskron.application.hint') }}</p>

            <div class="field">
                <label class="field-label" for="deskron-application-id">{{ __('deskron.fields.application_id') }}</label>
                <p class="field-hint">{{ __('deskron.fields.application_id_hint') }}</p>
                <input id="deskron-application-id" class="field-input" type="text" name="application_id" value="{{ old('application_id', $settings->application_id) }}" autocomplete="off" @disabled(! $canWrite)>
            </div>

            <div class="field">
                <label class="field-label" for="deskron-api-key">{{ __('deskron.fields.api_key') }}</label>
                <p class="field-hint">{{ $settings->exists && $settings->hasApiKey() ? __('deskron.fields.secret_saved') : __('deskron.fields.api_key_hint') }}</p>
                <input id="deskron-api-key" class="field-input" type="password" name="api_key" value="" autocomplete="new-password" @disabled(! $canWrite)>
            </div>

            <div class="field">
                <label class="field-label" for="deskron-webhook-secret">{{ __('deskron.fields.webhook_secret') }}</label>
                <p class="field-hint">{{ $settings->exists && $settings->hasWebhookSecret() ? __('deskron.fields.secret_saved') : __('deskron.fields.webhook_secret_hint') }}</p>
                <input id="deskron-webhook-secret" class="field-input" type="password" name="webhook_secret" value="" autocomplete="new-password" @disabled(! $canWrite)>
            </div>
        </article>

        <article class="site-card">
            <h2>{{ __('deskron.rollout.title') }}</h2>
            <p class="field-hint">{{ __('deskron.rollout.hint') }}</p>
        </article>

        @if ($canWrite)
            <button type="submit" class="btn btn-primary">{{ __('deskron.save') }}</button>
        @endif
    </form>
@endsection
