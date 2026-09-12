@extends('layouts.guest')

@section('title', __('platform_mail.unsubscribe.title'))

@section('content')
    <section class="guest-card" aria-labelledby="unsubscribe-heading">
        <div class="guest-brand">
            <span class="ops-mark" aria-hidden="true"></span>
            <div>
                <p class="guest-kicker">{{ config('app.name') }}</p>
                <h1 id="unsubscribe-heading">{{ __('platform_mail.unsubscribe.title') }}</h1>
            </div>
        </div>
        <p class="guest-lede">{{ __('platform_mail.unsubscribe.done') }}</p>
    </section>
@endsection
