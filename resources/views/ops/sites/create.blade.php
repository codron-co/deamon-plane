@extends('layouts.ops')

@section('title', 'New site')

@section('actions')
    <a class="btn btn-ghost btn-sm" href="{{ route('ops.sites') }}">Back to sites</a>
@endsection

@section('content')
    <p class="page-lede">Desired state. Coolify sunucu / proje / Git <strong>select</strong> — UUID yazılmaz. Compose her zaman <code>/docker-compose.coolify.yml</code>.</p>

    <form method="POST" action="{{ route('ops.sites.store') }}" class="ops-form settings-form">
        @csrf
        @include('ops.sites._form', ['site' => $site, 'channels' => $channels, 'readonly' => false, 'channelLocked' => false])
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Save draft</button>
            <a class="btn btn-ghost" href="{{ route('ops.sites') }}">Cancel</a>
        </div>
    </form>
@endsection

@section('scripts')
    <script src="{{ asset('js/ops-coolify-form.js') }}" defer></script>
@endsection
