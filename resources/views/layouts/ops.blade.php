<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Fleet') — {{ config('app.name') }}</title>
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/ops.css') }}">
</head>
<body class="ops-app">
    <div class="ops-shell">
        <aside class="ops-sidebar" aria-label="Primary">
            <a class="ops-brand" href="{{ route('ops.fleet') }}">
                <span class="ops-mark" aria-hidden="true"></span>
                <span class="ops-brand-text">
                    <strong>Plane</strong>
                    <span>Deamon ops</span>
                </span>
            </a>

            <nav class="ops-nav">
                <a class="ops-nav-item {{ request()->routeIs('ops.fleet') ? 'is-active' : '' }}" href="{{ route('ops.fleet') }}">
                    <svg viewBox="0 0 16 16" aria-hidden="true"><path d="M2.5 3.5h5v4h-5v-4Zm6 0h5v6h-5v-6Zm-6 5h5v4h-5v-4Zm6 7v-5h5v5h-5Z" fill="none" stroke="currentColor" stroke-width="1.25"/></svg>
                    Fleet
                </a>
                <a class="ops-nav-item {{ request()->routeIs('ops.sites*') ? 'is-active' : '' }}" href="{{ route('ops.sites') }}">
                    <svg viewBox="0 0 16 16" aria-hidden="true"><path d="M2.5 13.5V5.2L8 2.5l5.5 2.7v8.3H2.5Zm3-0.5v-4h5v4" fill="none" stroke="currentColor" stroke-width="1.25"/></svg>
                    Sites
                </a>
                <a class="ops-nav-item {{ request()->routeIs('ops.coolify*') ? 'is-active' : '' }}" href="{{ route('ops.coolify.index') }}">
                    <svg viewBox="0 0 16 16" aria-hidden="true"><path d="M3 11.2c-1.2 0-2.2-1-2.2-2.2 0-1 .7-1.9 1.6-2.1A3.2 3.2 0 0 1 8.2 5c.2 0 .3 0 .5.1A2.8 2.8 0 0 1 14 7.8c0 .2 0 .3-.1.5 1 .3 1.6 1.2 1.6 2.2 0 1.3-1 2.4-2.3 2.4H3Z" fill="none" stroke="currentColor" stroke-width="1.25"/></svg>
                    Coolify
                </a>
                <a class="ops-nav-item {{ request()->routeIs('ops.themes*') ? 'is-active' : '' }}" href="{{ route('ops.themes') }}">
                    <svg viewBox="0 0 16 16" aria-hidden="true"><path d="M3 3.5h10v9H3v-9Zm2 3h6M5 9h4" fill="none" stroke="currentColor" stroke-width="1.25" stroke-linecap="round"/></svg>
                    Themes
                </a>
                <a class="ops-nav-item {{ request()->routeIs('ops.settings*') ? 'is-active' : '' }}" href="{{ route('ops.settings') }}">
                    <svg viewBox="0 0 16 16" aria-hidden="true"><circle cx="8" cy="8" r="2.25" fill="none" stroke="currentColor" stroke-width="1.25"/><path d="M8 2.5v1.5M8 12v1.5M2.5 8h1.5M12 8h1.5M4.1 4.1l1.1 1.1M10.8 10.8l1.1 1.1M11.9 4.1l-1.1 1.1M5.2 10.8l-1.1 1.1" fill="none" stroke="currentColor" stroke-width="1.25" stroke-linecap="round"/></svg>
                    Settings
                </a>
            </nav>

            <div class="ops-sidebar-foot">
                @php($role = auth()->user()?->opsRole())
                <div class="ops-user">
                    <span class="ops-user-name">{{ auth()->user()?->name }}</span>
                    <span class="ops-user-role">{{ $role?->label() ?? 'No role' }}</span>
                </div>
                <form method="POST" action="{{ url('/logout') }}">
                    @csrf
                    <button type="submit" class="ops-logout">Sign out</button>
                </form>
            </div>
        </aside>

        <div class="ops-main">
            <header class="ops-topbar">
                <h1 class="ops-page-title">@yield('title', 'Fleet')</h1>
                <div class="ops-topbar-meta">
                    @hasSection('actions')
                        <div class="ops-topbar-actions">@yield('actions')</div>
                    @endif
                    <span class="ops-chip">{{ implode(' · ', config('ops.channels')) }}</span>
                </div>
            </header>
            <div class="ops-content @yield('content_class')">
                @if (session('status'))
                    <p class="ops-flash" role="status">{{ session('status') }}</p>
                @endif
                @if (session('error'))
                    <p class="ops-alert" role="alert">{{ session('error') }}</p>
                @endif
                @yield('content')
            </div>
        </div>
    </div>
    @include('ops.partials.confirm-modal')
    <script src="{{ asset('js/ops-confirm.js') }}" defer></script>
    @yield('scripts')
</body>
</html>
