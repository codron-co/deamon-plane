@extends('layouts.guest')

@section('title', __('instagram.status.title'))
@section('shell_class', 'guest-shell--doc')

@section('content')
    <section class="guest-card guest-doc" aria-labelledby="instagram-status-heading">
        <div class="guest-brand">
            <span class="ops-mark" aria-hidden="true"></span>
            <div>
                <p class="guest-kicker">{{ config('app.name') }}</p>
                <h1 id="instagram-status-heading">{{ __('instagram.status.title') }}</h1>
            </div>
        </div>
        <p class="guest-doc-meta">{{ __('instagram.status.code', ['code' => $deletion->confirmation_code]) }} · {{ __('instagram.status.received', ['date' => $deletion->created_at->format('Y-m-d H:i')]) }}</p>
        <p class="guest-lede">{{ __('instagram.status.state.'.$deletion->status) }}</p>
        @if ($deletion->completed_at)
            <p>{{ __('instagram.status.completed_at', ['date' => $deletion->completed_at->format('Y-m-d H:i')]) }}</p>
        @endif
        <p>{{ __('instagram.status.contact') }}</p>
        @include('instagram.partials.legal-links')
    </section>
@endsection
