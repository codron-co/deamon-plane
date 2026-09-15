@extends('layouts.ops')

@section('title', __('deskron.title'))

@section('actions')
    @if ($canWrite)
        <form method="POST" action="{{ route('ops.deskron.push') }}" data-ops-pending>
            @csrf
            <button type="submit" class="btn btn-ghost btn-sm" data-pending-label="{{ __('ops.actions.working') }}">{{ __('deskron.push') }}</button>
        </form>
    @endif
@endsection

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
            <p class="site-note">
                {{ __('deskron.rollout.pushed', ['count' => $pushedSites]) }}
                @if ($settings->last_pushed_at)
                    · {{ __('deskron.rollout.last_pushed', ['time' => $settings->last_pushed_at->diffForHumans()]) }}
                @endif
            </p>
            @if ($failedSites->isNotEmpty())
                <p class="ops-alert ops-alert-warning" role="status">{{ __('deskron.rollout.failed', ['count' => $failedSites->count()]) }}</p>
                <ul class="ops-alert-list">
                    @foreach ($failedSites as $failedSite)
                        <li><a href="{{ route('ops.sites.show', $failedSite) }}">{{ $failedSite->name }}</a> <span class="muted">{{ $failedSite->deskron_push_error }}</span></li>
                    @endforeach
                </ul>
            @endif
        </article>

        @if ($canWrite)
            <button type="submit" class="btn btn-primary">{{ __('deskron.save') }}</button>
        @endif
    </form>
@endsection
