@extends('pay.layout')

@section('title', __('billing.pay.waiting_title'))

@section('head')
    <meta http-equiv="refresh" content="2;url={{ $next }}">
@endsection

@section('content')
    <h1>{{ __('billing.pay.waiting_title') }}</h1>
    <p class="pay-lede">{{ __('billing.pay.waiting_lede') }}</p>
    <p class="pay-note pay-mono">{{ $order->reference }}</p>
@endsection
