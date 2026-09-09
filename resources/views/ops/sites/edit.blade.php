@extends('layouts.ops')

@section('title', $site->name)

@section('actions')
    <a class="btn btn-ghost btn-sm" href="{{ route('ops.sites.show', $site) }}">Back to site</a>
@endsection

@section('content')
    @if ($site->hasDockerfileBuildPackWarning())
        <p class="ops-alert ops-alert-warning" role="status">
            Coolify build pack is <strong>dockerfile</strong>, not <strong>dockercompose</strong>.
            Provision and channel switch still use the existing app UUID.
            Migrate this Coolify app to Docker Compose later; do not treat dockerfile as a skip.
        </p>
    @endif

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

    @if ($canInjectAgentSecret ?? false)
        <section class="ops-panel" aria-labelledby="agent-secret-heading">
            <h2 id="agent-secret-heading">Agent secret</h2>
            <p>Coolify env <code>CONTROL_PLANE_AGENT_SECRET</code>. Üretilir, şifreli saklanır, bir daha gösterilmez.</p>
            <form method="POST" action="{{ route('ops.sites.agent-secret', $site) }}" class="ops-form">
                @csrf
                <div class="form-actions">
                    <button type="submit" class="btn btn-secondary">Generate &amp; inject secret</button>
                </div>
            </form>
        </section>
    @endif

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

@section('scripts')
    <script src="{{ asset('js/ops-coolify-form.js') }}" defer></script>
@endsection
