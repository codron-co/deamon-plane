@extends('layouts.ops')

@section('title', __('instagram.title'))

@section('actions')
    @if ($canWrite)
        <form method="POST" action="{{ route('ops.instagram.push') }}" data-ops-pending>
            @csrf
            <button type="submit" class="btn btn-ghost btn-sm" data-pending-label="{{ __('ops.actions.working') }}">{{ __('instagram.push') }}</button>
        </form>
    @endif
@endsection

@section('content')
    <p class="page-lede">{{ __('instagram.lede') }}</p>

    <div class="ops-mail-state-row">
        <span class="ops-mail-state-title">{{ __('instagram.state_label') }}</span>
        <span class="status-chip {{ $settings->isReady() ? 'status-active' : '' }}">
            {{ $settings->isReady() ? __('instagram.state.ready') : __('instagram.state.missing') }}
        </span>
        <span class="ops-mail-state-hint">{{ $settings->isReady() ? __('instagram.state_hint.ready') : __('instagram.state_hint.missing') }}</span>
    </div>

    <form method="POST" action="{{ route('ops.instagram.update') }}" class="ops-form ops-form-stack">
        @csrf
        @method('PUT')

        @if ($errors->any())
            <p class="ops-alert" role="alert">{{ __('instagram.form_errors') }}</p>
            <ul class="ops-alert-list">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        @endif

        <article class="site-card">
            <h2>{{ __('instagram.app.title') }}</h2>
            <p class="field-hint">{{ __('instagram.app.hint') }}</p>

            <div class="field">
                <label class="field-label" for="instagram-app-id">{{ __('instagram.fields.app_id') }}</label>
                <p class="field-hint">{{ __('instagram.fields.app_id_hint') }}</p>
                <input id="instagram-app-id" class="field-input" type="text" inputmode="numeric" name="app_id" value="{{ old('app_id', $settings->app_id) }}" autocomplete="off" @disabled(! ($canEdit ?? false))>
            </div>

            <div class="field">
                <label class="field-label" for="instagram-app-secret">{{ __('instagram.fields.app_secret') }}</label>
                <p class="field-hint">{{ $settings->exists && $settings->hasAppSecret() ? __('instagram.fields.secret_saved') : __('instagram.fields.app_secret_hint') }}</p>
                <input id="instagram-app-secret" class="field-input" type="password" name="app_secret" value="" autocomplete="new-password" @disabled(! ($canEdit ?? false))>
            </div>
        </article>

        <article class="site-card">
            <h2>{{ __('instagram.meta.title') }}</h2>
            <p class="field-hint">{{ __('instagram.meta.hint') }}</p>
            <div class="field">
                <label class="field-label" for="instagram-redirect-uri">{{ __('instagram.meta.redirect_uri') }}</label>
                <input id="instagram-redirect-uri" class="field-input" type="text" value="{{ $redirectUri }}" readonly>
            </div>
            <div class="field">
                <label class="field-label" for="instagram-data-deletion">{{ __('instagram.meta.data_deletion') }}</label>
                <input id="instagram-data-deletion" class="field-input" type="text" value="{{ $dataDeletionUrl }}" readonly>
            </div>
            <p class="site-note">{{ __('instagram.meta.scope') }}</p>
        </article>

        <article class="site-card">
            <h2>{{ __('instagram.rollout.title') }}</h2>
            <p class="site-note">
                {{ __('instagram.rollout.supported', ['count' => $supportedCount]) }}
                @if ($settings->last_pushed_at)
                    · {{ __('instagram.rollout.last_pushed', ['time' => $settings->last_pushed_at->diffForHumans()]) }}
                @endif
            </p>
            <p class="site-note">{{ __('instagram.rollout.connected', ['count' => $connectedSites->count()]) }}</p>
            @if ($connectedSites->isNotEmpty())
                <ul class="ops-alert-list">
                    @foreach ($connectedSites as $connectedSite)
                        <li><a href="{{ route('ops.sites.show', $connectedSite) }}">{{ $connectedSite->name }}</a> <span class="muted">{{ '@'.data_get($connectedSite->last_health_payload, 'instagram.username') }}</span></li>
                    @endforeach
                </ul>
            @endif
        </article>

        @if ($canEdit ?? false)
            <button type="submit" class="btn btn-primary">{{ __('instagram.save') }}</button>
        @elseif ($canWrite)
            <p class="field-hint">{{ __('ops.super_admin_only') }}</p>
        @endif
    </form>
@endsection
