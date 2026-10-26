@extends('layouts.guest')

@section('title', __("instagram.{$page}.title"))
@section('shell_class', 'guest-shell--doc')

@section('content')
    <article class="guest-card guest-doc" aria-labelledby="instagram-legal-heading">
        <div class="guest-brand">
            <span class="ops-mark" aria-hidden="true"></span>
            <div>
                <p class="guest-kicker">{{ config('app.name') }}</p>
                <h1 id="instagram-legal-heading">{{ __("instagram.{$page}.title") }}</h1>
            </div>
        </div>
        <p class="guest-doc-meta">{{ __('instagram.legal.updated') }}</p>
        <p class="guest-lede">{{ __("instagram.{$page}.lede") }}</p>

        @foreach (__("instagram.{$page}.sections") as $section)
            <h2>{{ $section['heading'] }}</h2>
            @foreach ($section['paragraphs'] ?? [] as $paragraph)
                <p>{{ $paragraph }}</p>
            @endforeach
            @if (! empty($section['items']))
                <ul>
                    @foreach ($section['items'] as $item)
                        <li>{{ $item }}</li>
                    @endforeach
                </ul>
            @endif
            @foreach ($section['notes'] ?? [] as $note)
                <p>{{ $note }}</p>
            @endforeach
        @endforeach

        @include('instagram.partials.legal-links')
    </article>
@endsection
