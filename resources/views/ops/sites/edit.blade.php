@extends('layouts.ops')

@section('title', $site->name)

@section('actions')
    <a class="btn btn-ghost btn-sm" href="{{ route('ops.sites') }}">Back to sites</a>
@endsection

@section('content')
    <p class="page-lede">
        Desired state for <code>{{ $site->slug }}</code>.
        @if ($readonly)
            Viewer role is read-only.
        @else
            Status stays <strong>{{ $site->status?->value }}</strong> until provision. No Coolify call from this screen.
        @endif
    </p>

    <form method="POST" action="{{ route('ops.sites.update', $site) }}" class="ops-form settings-form">
        @csrf
        @method('PUT')
        @include('ops.sites._form')
        @if (! $readonly)
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Save changes</button>
                <button type="button" class="btn btn-ghost" disabled title="Provisioning is Task 4">Provision</button>
            </div>
        @endif
    </form>

    @if ($canDelete ?? false)
        <div class="danger-zone">
            <h2>Archive</h2>
            <p>Soft-delete this site record. Coolify is not contacted.</p>
            <form
                method="POST"
                action="{{ route('ops.sites.destroy', $site) }}"
                data-confirm="Archive {{ $site->name }}? This soft-deletes the Plane record. Coolify is not contacted."
                data-confirm-title="Delete site"
                data-confirm-label="Delete"
            >
                @csrf
                @method('DELETE')
                <button type="submit" class="btn btn-danger">Delete site</button>
            </form>
        </div>
    @endif
@endsection
