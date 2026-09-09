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
        @elseif ($site->status === \App\Enums\SiteStatus::Provisioning)
            Coolify compose stack is being created. This page will show <strong>active</strong> when the deploy finishes.
        @elseif ($canProvision ?? false)
            Status is <strong>{{ $site->status?->value }}</strong>. Provision creates a per-site app + MySQL + Redis stack (no shared database).
        @elseif ($canSwitchChannel ?? false)
            Status is <strong>{{ $site->status?->value }}</strong>. Channel switch PATCHes Coolify git_branch and redeploys; volumes stay.
        @else
            Status is <strong>{{ $site->status?->value }}</strong>. Channel switch and destroy are separate actions.
        @endif
    </p>

    <form method="POST" action="{{ route('ops.sites.update', $site) }}" class="ops-form settings-form">
        @csrf
        @method('PUT')
        @include('ops.sites._form')
        @if (! $readonly)
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Save changes</button>
            </div>
        @endif
    </form>

    @if ($canProvision ?? false)
        <form method="POST" action="{{ route('ops.sites.provision', $site) }}" class="ops-form">
            @csrf
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Provision</button>
            </div>
        </form>
    @elseif (! $readonly && $site->status === \App\Enums\SiteStatus::Provisioning)
        <p class="ops-flash" role="status">Provisioning in progress. Coolify deploy status updates as the job finishes.</p>
    @endif

    @include('ops.sites._channel-switch')

    @include('ops.sites._agent-health')

    @include('ops.sites._themes')

    @include('ops.deployments.index')

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
