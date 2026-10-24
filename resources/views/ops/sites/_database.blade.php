@php
    /** @var \App\Models\Site $site */
    $sharedDatabase = $site->usesSharedDatabase();
@endphp

<article class="site-card site-operation" id="site-database" aria-labelledby="site-database-heading">
    <div class="site-card-head">
        <h3 id="site-database-heading">{{ __('databases.site.card_title') }} @include('ops.dashboard._hint', ['text' => __('databases.site.card_hint')])</h3>
        <span class="status-chip">
            {{ $sharedDatabase ? __('databases.site.shared', ['server' => $site->databaseServer?->name ?? '?']) : __('databases.site.local') }}
        </span>
    </div>
    @if ($sharedDatabase)
        <p class="muted"><code>{{ $site->db_database }}</code></p>
        @can('ops.write')
            <a class="btn btn-secondary btn-sm" href="{{ route('ops.sites.database', $site) }}">{{ __('databases.site.open') }}</a>
        @endcan
    @endif
</article>
