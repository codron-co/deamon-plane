<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="{{ $opsAppearance ?? 'dark' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', __('auth.title')) — {{ config('app.name') }}</title>
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <script>
        (function () {
            try {
                var theme = window.localStorage.getItem('plane-theme');
                if (theme === 'light' || theme === 'semidark' || theme === 'dark') {
                    document.documentElement.dataset.theme = theme;
                }
            } catch (error) {
                /* Keep the dark default when storage is unavailable. */
            }
        })();
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/ops.css') }}">
    <link rel="stylesheet" href="{{ asset('css/ops-ui.css') }}">
</head>
<body class="ops-guest">
    <main class="guest-shell">
        @yield('content')
    </main>
</body>
</html>
