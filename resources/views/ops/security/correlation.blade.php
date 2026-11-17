@extends('layouts.ops')

@section('title', __('security.correlation.title'))

@section('content_class', 'ops-content-wide')

@section('content')
    <p class="page-lede">{{ __('security.correlation.lede') }}</p>

    @include('ops.security._tabs')

    <nav class="security-period" aria-label="{{ __('security.correlation.period_aria') }}">
        @foreach (['24h', '7d', '30d'] as $option)
            <a href="{{ route('ops.security.correlation', ['period' => $option]) }}" @if ($period === $option) class="is-active" aria-current="true" @endif>{{ __('security.period.'.$option) }}</a>
        @endforeach
    </nav>

    @php $canWrite = (bool) auth()->user()?->canWriteOps(); @endphp

    @if ($cards->isEmpty())
        <div class="empty-panel">
            <h2>{{ __('security.correlation.empty_title') }}</h2>
            <p>{{ __('security.correlation.empty_hint') }}</p>
        </div>
    @else
        <div class="security-cards">
            @foreach ($cards as $card)
                @include('ops.security._card', ['card' => $card, 'canWrite' => $canWrite])
            @endforeach
        </div>
    @endif
@endsection
