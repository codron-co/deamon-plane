@extends('layouts.ops')

@section('title', 'Fleet')

@section('content')
    <p class="page-lede">Coolify-hosted Deamon sites. Deploy status updates from Coolify webhooks (HMAC or query token), with poll as fallback.</p>

    @include('ops.dashboard.kpis')
    @include('ops.dashboard.attention')
@endsection
