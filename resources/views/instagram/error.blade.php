@extends('layouts.guest')

@section('title', __('instagram.public.error_title'))

@section('content')
    <section class="guest-card" aria-labelledby="instagram-error-heading">
        <div class="guest-brand">
            <span class="ops-mark" aria-hidden="true"></span>
            <div>
                <p class="guest-kicker">{{ config('app.name') }}</p>
                <h1 id="instagram-error-heading">{{ __('instagram.public.error_title') }}</h1>
            </div>
        </div>
        <p class="guest-lede">{{ $reason === 'not_configured' ? __('instagram.public.error_not_configured') : __('instagram.public.error_lede') }}</p>
    </section>
@endsection
