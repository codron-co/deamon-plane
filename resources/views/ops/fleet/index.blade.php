@extends('layouts.ops')

@section('title', 'Fleet')

@section('content')
    <p class="page-lede">Coolify-hosted Deamon sites. Deploy status updates from signed Coolify webhooks, with poll as fallback.</p>

    @include('ops.dashboard.kpis')
@endsection
