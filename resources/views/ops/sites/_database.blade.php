@php
    /** @var \App\Models\Site $site */
    $sharedDatabase = $site->usesSharedDatabase();
    $sharedRedis = $site->usesSharedRedis();
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
    <p class="muted" data-site-redis>
        {{ __('databases.redis.site.label') }}:
        @if ($sharedRedis)
            {{ __('databases.redis.site.shared', ['server' => $site->redisServer?->name ?? '?', 'db' => $site->redis_db, 'cache_db' => (int) $site->redis_db + 1]) }}
        @else
            {{ __('databases.redis.site.local') }}
        @endif
    </p>
</article>
