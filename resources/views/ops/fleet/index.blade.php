@extends('layouts.ops')

@section('title', __('fleet.title'))

@section('content')
    <p class="page-lede">{{ __('fleet.lede') }}</p>

    @include('ops.dashboard.kpis')
    @include('ops.dashboard.attention')
@endsection
