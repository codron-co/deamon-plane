<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="color-scheme" content="light dark">
    <title>@yield('title') · {{ __('billing.pay.brand') }}</title>
    @yield('head')
    <style>
        :root {
            --pay-bg: #f5f5f7;
            --pay-surface: #ffffff;
            --pay-text: #1d1d1f;
            --pay-muted: #6e6e73;
            --pay-line: #e3e3e8;
            --pay-accent: #0a66c2;
            --pay-success: #1f7a3d;
            --pay-danger: #b3261e;
            --pay-radius: 14px;
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --pay-bg: #111113;
                --pay-surface: #1c1c1e;
                --pay-text: #f5f5f7;
                --pay-muted: #a1a1a6;
                --pay-line: #2c2c2e;
                --pay-accent: #4c9ef0;
                --pay-success: #5ec27e;
                --pay-danger: #ff8a80;
            }
        }
        * { box-sizing: border-box; }
        html, body { margin: 0; }
        body {
            background: var(--pay-bg);
            color: var(--pay-text);
            font: 16px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            -webkit-font-smoothing: antialiased;
        }
        .pay-shell { max-width: 640px; margin: 0 auto; padding: 32px 16px 48px; }
        .pay-brand { font-size: 15px; font-weight: 600; letter-spacing: -0.01em; color: var(--pay-muted); margin: 0 0 24px; }
        .pay-brand span { color: var(--pay-accent); }
        h1 { font-size: 28px; line-height: 1.2; font-weight: 700; letter-spacing: -0.02em; margin: 0 0 6px; }
        h2 { font-size: 13px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; color: var(--pay-muted); margin: 28px 4px 8px; }
        .pay-lede { color: var(--pay-muted); margin: 0 0 8px; }
        .pay-group { background: var(--pay-surface); border-radius: var(--pay-radius); overflow: hidden; }
        .pay-row { display: flex; justify-content: space-between; gap: 16px; padding: 12px 16px; border-top: 1px solid var(--pay-line); }
        .pay-row:first-child { border-top: 0; }
        .pay-row dt { color: var(--pay-muted); margin: 0; }
        .pay-row dd { margin: 0; text-align: right; font-weight: 500; overflow-wrap: anywhere; }
        .pay-row.is-total dd { font-size: 18px; font-weight: 700; }
        .pay-row small { display: block; font-weight: 400; color: var(--pay-muted); font-size: 13px; }
        .pay-frame { background: var(--pay-surface); border-radius: var(--pay-radius); padding: 8px; }
        .pay-frame iframe { display: block; width: 100%; min-height: 640px; border: 0; }
        .pay-note { font-size: 13px; color: var(--pay-muted); margin: 10px 4px 0; }
        .pay-copy { appearance: none; border: 0; background: none; color: var(--pay-accent); font: inherit; font-size: 14px; padding: 0 0 0 8px; cursor: pointer; }
        .pay-mono { font-variant-numeric: tabular-nums; letter-spacing: 0.02em; }
        .pay-actions { margin-top: 28px; display: flex; gap: 12px; flex-wrap: wrap; }
        .pay-button { display: inline-block; padding: 11px 20px; border-radius: 980px; background: var(--pay-accent); color: #fff; text-decoration: none; font-weight: 600; font-size: 15px; }
        .pay-link { color: var(--pay-accent); text-decoration: none; font-size: 15px; padding: 11px 0; }
        .pay-state { font-size: 15px; font-weight: 600; margin: 0 0 12px; }
        .pay-state.is-success { color: var(--pay-success); }
        .pay-state.is-danger { color: var(--pay-danger); }
        .pay-banner { background: var(--pay-surface); border-radius: var(--pay-radius); padding: 14px 16px; margin-top: 16px; }
        @media (max-width: 480px) {
            .pay-shell { padding-top: 20px; }
            h1 { font-size: 24px; }
            .pay-frame { padding: 0; }
        }
    </style>
</head>
<body>
    <main class="pay-shell">
        <p class="pay-brand"><span>Co</span>Mail</p>
        @yield('content')
    </main>
    @yield('scripts')
</body>
</html>
