@extends('layouts.ops')

@section('title', 'Settings')

@section('content')
    <p class="page-lede">GitHub App (tema kataloğu) burada. Coolify instance’ları sol menüdeki <strong>Coolify</strong> altında. Token asla gösterilmez.</p>

    @include('ops.settings.partials.coolify')

    @include('ops.settings.partials.github')

    <section class="settings-panel" aria-labelledby="customer-defaults-heading">
        <h2 id="customer-defaults-heading">Customer site defaults</h2>
        <p class="field-hint">From environment. Provision jobs (later) create git + Docker Compose apps — never Nixpacks, never raw <code>POST /applications/dockercompose</code>.</p>

        <div class="ops-form settings-form">
            <div class="field">
                <span class="field-label">Customer git repository</span>
                <input class="field-input" type="text" value="{{ $repository }}" readonly>
            </div>
            <div class="field">
                <span class="field-label">Compose file</span>
                <input class="field-input" type="text" value="{{ $composeFile }}" readonly>
            </div>
        </div>
    </section>
@endsection
