# Shared requests — Settings / Account / Login

Subagent 6 (`ui/rollout-settings-auth`). Do not edit `resources/views/layouts/ops.blade.php`, `public/css/ops.css`, `public/css/ops-ui.css`, or `lang/*/ops.php` from this worktree.

## User menu (ops layout)

Sign out already lives inside the sidebar user menu. Account and Preferences pages do **not** add a second logout block.

### Do not add a Preferences page link without a test change

`PreferencesAppearanceTest::test_user_menu_uses_cycle_buttons_not_a_preferences_page_link` forbids `href="{{ route('ops.account.preferences') }}"` in the menu. Appearance and language are cycle buttons. The Preferences page stays reachable from the Account topbar (and the reverse link on Preferences).

If the orchestrator wants a literal Preferences item after Account, add this in `resources/views/layouts/ops.blade.php` after the Account `ops-menu-link` (around line 118) **and** update that test:

```blade
<a class="ops-menu-link {{ request()->routeIs('ops.account.preferences') ? 'is-active' : '' }}" href="{{ route('ops.account.preferences') }}">{{ __('ops.user_menu.preferences') }}</a>
```

Recommended: keep cycle buttons; do not add the link.

### Desktop viewport — no Blade change

Desktop already pins the control:

```css
@media (min-width: 769px) {
    .ops-sidebar { position: sticky; top: 0; height: 100dvh; overflow: hidden; }
    .ops-nav { min-height: 0; overflow-y: auto; }
    .ops-sidebar-foot { flex: 0 0 auto; }
}
```

### Mobile (≤768px) — user menu can leave the viewport

On narrow widths `ops-ui.css` sets `.ops-sidebar { position: static; height: auto; }`. A long Account/Settings page can scroll the user control away. If the orchestrator wants it pinned on mobile, add to `public/css/ops-ui.css` (do not put this in a page view):

```css
@media (max-width: 768px) {
    .ops-sidebar-foot {
        position: sticky;
        bottom: 0;
        z-index: 20;
        background: var(--surface-base);
        border-top: 1px solid var(--border);
    }
    .ops-user-popover {
        left: 12px;
        right: 12px;
        bottom: 64px;
        width: auto;
    }
}
```

(`bottom: 64px` matches the desktop popover offset so the menu does not cover the trigger. Today mobile uses `bottom: 12px`.)

### Sign out placement — no change

Keep Sign out as `ops-menu-button` inside `.ops-menu-section` at the bottom of the popover. Do not restore `.ops-logout` as a permanent full-width control under the name.

## Global primitives requested

Account/Settings could not use Site kickers without copying `site-*` names. Extract in `public/css/ops-ui.css`:

```css
.ops-kicker {
    color: var(--text-faint);
    font-size: 10px;
    font-weight: 650;
    letter-spacing: .07em;
    text-transform: uppercase;
}
```

Then point `.site-section-kicker` at `.ops-kicker` (or share the same rules). Optional: treat `.settings-panel` as a shared raised card primitive instead of a Settings-only class in `ops.css`.

## Worktree tests

`vendor` is a junction to `deamon-plane/vendor`, so Laravel infers the main repo as `APP_BASE_PATH`. Run PHPUnit from this worktree with:

```
$env:APP_BASE_PATH = "C:\workspace\deamon\plane-ui-wt\settings-auth"
```

Create `storage/framework/views` (and cache/sessions) if Blade says “Please provide a valid cache path.”

`--filter Settings` also matches `CloudflareSettingsTest` (out of this worktree). That suite has a pre-existing `assertSee('DNS & Zones', false)` vs `DNS &amp; Zones` mismatch. Do not treat it as a Settings/Account regression.

## Out of scope here

- Coolify credential forms stay on Coolify routes. Settings only links to `ops.coolify.index`.
- No rotate/destroy GitHub actions exist, so no danger zone was added.
- Fortify password rules and token storage were not changed.
