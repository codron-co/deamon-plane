@extends('layouts.guest')

@section('title', __('auth.title'))

@section('content')
    <section class="guest-card" aria-labelledby="login-heading">
        <div class="guest-brand">
            <span class="ops-mark" aria-hidden="true"></span>
            <div>
                <p class="guest-kicker">{{ config('app.name') }}</p>
                <h1 id="login-heading">{{ __('auth.heading') }}</h1>
            </div>
        </div>
        <p class="guest-lede">{{ __('auth.lede') }}</p>

        @if ($errors->any())
            <div class="ops-alert" role="alert">
                {{ $errors->first() }}
            </div>
        @endif

        <form method="POST" action="{{ route('login') }}" class="ops-form">
            @csrf
            <div class="field">
                <label class="field-label" for="email">{{ __('auth.email') }}</label>
                <input id="email" class="field-input" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username">
                @error('email') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div class="field">
                <label class="field-label" for="password">{{ __('auth.password') }}</label>
                <input id="password" class="field-input" type="password" name="password" required autocomplete="current-password">
                @error('password') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <label class="field-check">
                <input type="checkbox" name="remember">
                <span>{{ __('auth.remember') }}</span>
            </label>
            <button type="submit" class="btn btn-primary btn-block">{{ __('auth.submit') }}</button>
        </form>
    </section>
@endsection
