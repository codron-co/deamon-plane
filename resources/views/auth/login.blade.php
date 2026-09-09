@extends('layouts.guest')

@section('title', 'Sign in')

@section('content')
    <section class="guest-card" aria-labelledby="login-heading">
        <div class="guest-brand">
            <span class="ops-mark" aria-hidden="true"></span>
            <div>
                <p class="guest-kicker">Deamon Plane</p>
                <h1 id="login-heading">Sign in to ops</h1>
            </div>
        </div>
        <p class="guest-lede">Internal CodRon control plane. Customer CMS admins do not use this app.</p>

        @if ($errors->any())
            <div class="ops-alert" role="alert">
                {{ $errors->first() }}
            </div>
        @endif

        <form method="POST" action="{{ route('login') }}" class="ops-form">
            @csrf
            <label class="field">
                <span class="field-label">Email</span>
                <input id="email" class="field-input" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username">
            </label>
            <label class="field">
                <span class="field-label">Password</span>
                <input id="password" class="field-input" type="password" name="password" required autocomplete="current-password">
            </label>
            <label class="field-check">
                <input type="checkbox" name="remember">
                <span>Remember this browser</span>
            </label>
            <button type="submit" class="btn btn-primary btn-block">Sign in</button>
        </form>
    </section>
@endsection
