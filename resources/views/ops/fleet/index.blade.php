@extends('layouts.ops')

@section('title', __('fleet.title'))

@section('content_class', 'ops-content-wide')

@section('content')
    @include('ops.dashboard.attention')
    @include('ops.dashboard.kpis')
@endsection
