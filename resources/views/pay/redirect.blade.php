@extends('pay.layout')

@section('title', __('billing.pay.redirecting'))

@section('head')
    <meta http-equiv="refresh" content="3;url={{ $target }}">
@endsection

@section('content')
    <h1 data-pay-state="{{ $state }}">{{ __('billing.pay.results.'.$state.'.title') }}</h1>
    <p class="pay-lede">{{ __('billing.pay.redirecting') }}</p>
    <div class="pay-actions">
        <a class="pay-button" href="{{ $target }}" target="_top" rel="noopener" data-pay-target>{{ __('billing.pay.continue') }}</a>
    </div>
@endsection

@section('scripts')
    <script>
        (function () {
            var target = @json($target);
            try { window.top.location.replace(target); } catch (error) { window.location.replace(target); }
        })();
    </script>
@endsection
