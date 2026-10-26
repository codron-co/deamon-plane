@extends('layouts.guest')

@section('title', __('instagram.public.deletion_title'))
@section('shell_class', 'guest-shell--doc')

@section('content')
    <section class="guest-card guest-doc" aria-labelledby="instagram-deletion-heading">
        <div class="guest-brand">
            <span class="ops-mark" aria-hidden="true"></span>
            <div>
                <p class="guest-kicker">{{ config('app.name') }}</p>
                <h1 id="instagram-deletion-heading">{{ __('instagram.public.deletion_title') }}</h1>
            </div>
        </div>
        <p class="guest-lede">{{ __('instagram.public.deletion_lede') }}</p>
        <h2 style="font-size:1rem;margin:1.25rem 0 .5rem">{{ __('instagram.public.deletion_steps_title') }}</h2>
        <ol style="margin:0;padding-left:1.25rem;display:grid;gap:.5rem;line-height:1.55">
            <li>{{ __('instagram.public.deletion_step_1') }}</li>
            <li>{{ __('instagram.public.deletion_step_2') }}</li>
            <li>{{ __('instagram.public.deletion_step_3') }}</li>
        </ol>
        @include('instagram.partials.legal-links')
    </section>
@endsection
