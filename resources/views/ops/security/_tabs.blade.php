@php
    $tabs = [
        'ops.security' => ['label' => __('security.tabs.overview'), 'active' => request()->routeIs('ops.security', 'ops.security.site')],
        'ops.security.correlation' => ['label' => __('security.tabs.correlation'), 'active' => request()->routeIs('ops.security.correlation')],
        'ops.security.events' => ['label' => __('security.tabs.events'), 'active' => request()->routeIs('ops.security.events')],
        'ops.security.blocks' => ['label' => __('security.tabs.blocks'), 'active' => request()->routeIs('ops.security.blocks')],
    ];
@endphp
<nav class="security-tabs" aria-label="{{ __('security.tabs.aria') }}">
    @foreach ($tabs as $route => $tab)
        <a href="{{ route($route) }}" @if ($tab['active']) class="is-active" aria-current="page" @endif>{{ $tab['label'] }}</a>
    @endforeach
</nav>
