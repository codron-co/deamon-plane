@extends('layouts.ops')

@section('title', 'Coolify bağla')

@section('actions')
    <a class="btn btn-ghost btn-sm" href="{{ route('ops.coolify.index') }}">Geri</a>
@endsection

@section('content')
    <p class="page-lede">API token şifreli saklanır. Kaydettikten sonra sunucu / proje / Git listesini senkronlayın.</p>

    <form method="POST" action="{{ route('ops.coolify.store') }}" class="ops-form settings-form">
        @csrf
        @include('ops.coolify._connection-fields', ['connection' => $connection, 'canWrite' => true, 'requireToken' => true])
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Bağla</button>
        </div>
    </form>
@endsection
