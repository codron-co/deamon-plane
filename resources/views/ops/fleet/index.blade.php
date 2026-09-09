@extends('layouts.ops')

@section('title', 'Fleet')

@section('content')
    <p class="page-lede">Coolify-hosted Deamon sites. Provisioning and channel switch land in Dalga 2 — this shell is the ops home.</p>

    <section class="kpi-grid" aria-label="Fleet snapshot">
        <article class="kpi-card">
            <p class="kpi-label">Sites</p>
            <p class="kpi-value">—</p>
            <p class="kpi-hint">Schema in Task 1</p>
        </article>
        <article class="kpi-card">
            <p class="kpi-label">Deploying</p>
            <p class="kpi-value">—</p>
            <p class="kpi-hint">Coolify poll in Task 2–4</p>
        </article>
        <article class="kpi-card">
            <p class="kpi-label">Channels</p>
            <p class="kpi-value kpi-value-sm">{{ implode(' / ', $channels) }}</p>
            <p class="kpi-hint">Git branch allowlist</p>
        </article>
        <article class="kpi-card">
            <p class="kpi-label">Compose</p>
            <p class="kpi-value kpi-value-sm">{{ config('ops.deamon.compose_file') }}</p>
            <p class="kpi-hint">Customer + Plane stack file</p>
        </article>
    </section>
@endsection
