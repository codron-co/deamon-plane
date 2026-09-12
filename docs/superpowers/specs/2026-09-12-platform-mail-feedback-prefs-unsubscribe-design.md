# Platform mail — save feedback, test mail, per-admin prefs, unsubscribe

**Date:** 2026-09-12  
**Status:** planned  
**Plans:** `docs/superpowers/plans/2026-09-12-platform-mail-plane-feedback-test-unsubscribe.md` + CMS companion plan  
**Repos:** `deamon-plane` + `deamon` (CMS)  
**CMS mirror:** `deamon` → `docs/superpowers/specs/2026-09-12-platform-mail-feedback-prefs-unsubscribe-design.md`

## Goal

1. Plane `/platform-mail` always shows clear save / validation / error / push feedback.
2. Operators can send a **test** software-mail from Plane.
3. CMS admins manage **their own** email preferences for panel notifications and platform software mails.
4. Preference-based mails include a **one-click unsubscribe** link (no login).

## Locked decisions

| Topic | Choice |
|-------|--------|
| Approach | Plane reliable save + async push; CMS per-admin prefs; one-click unsubscribe |
| Save vs push | Save writes Plane DB + flash immediately; site configure runs in background job (or explicit “Push to sites”) — never blocks the success flash |
| Test mail | Plane sends via current SMTP to default admin recipient (or override field); flash success/failure |
| CMS panel prefs | Per `(site, user)` — in-app + email toggles; site settings remain **defaults** for untouched users |
| CMS platform prefs | Per `(site, user)` email toggles for CMS-emitted platform keys only |
| Transactional | `password_reset`, `admin_welcome` — not preference-gated; **no** unsubscribe footer |
| Recipient fan-out | Stop single `contact_email` / single `admin_recipient` as sole mailbox for preference mail; send to each opted-in active admin |
| Plane ops recipient | Unchanged primary recipient + Plane users; add Plane-side per-user opt-out when unsubscribe lands |
| Unsubscribe host | **Sender owns URL**: CMS-sent → customer site; Plane-sent ops → `plane.codron.co` |
| Unsubscribe UX | Signed one-click (GET or POST) immediately disables that type for that user; confirmation page; optional `List-Unsubscribe` / `List-Unsubscribe-Post` headers |
| Out of scope | Hostinger mailboxes, site mail module customer mail, storefront newsletter, Mailcow, marketplace |

## Architecture

```text
Plane /platform-mail
  ├─ PUT save → PlatformMailSetting + flash (+ dispatch PushPlatformMailJob)
  ├─ POST push → syncAllSites (manual)
  └─ POST test → PlatformOpsMailer / raw SMTP test → flash

CMS admin prefs
  ├─ Site defaults: settings key admin_notifications (+ platform_mail_prefs defaults)
  ├─ Per-user: admin_user_notification_preferences (or equivalent JSON on pivot/settings)
  └─ Dispatcher / PlatformSoftwareMailer: fan-out to opted-in admins

Unsubscribe
  ├─ CMS: GET/POST /mail/unsubscribe/{token} (signed, no auth)
  └─ Plane: GET/POST /ops/mail/unsubscribe/{token} (signed, no auth) for plane-scope keys
```

## Plane surface

### Save feedback

- Form shows `@errors` / form-level alert when validation fails.
- Success: `ops-flash` with saved message; if push is async, flash may say “Saved; pushing to sites…” and a later status/warning for push results is acceptable via audit/log or follow-up flash on next load.
- Layout already renders `session('status'|'warning'|'error')` — keep using those keys; never silent redirect without flash on write paths.

### Push

- Prefer queue job per site or one job that iterates sites with timeouts; record failures in log/audit.
- Manual “Push to sites” remains; returns count + warning if any site failed.

### Test mail

- Route: `POST /platform-mail/test` (`ops.write`).
- Body: optional `to` email; default `default_admin_recipient`.
- Requires settings `isReady()`; otherwise clear error flash.
- Subject/body: short fixed “Deamon Plane test mail” (i18n).
- Does not require notification catalog flags.

### Plane unsubscribe (ops mails)

- Footer on Plane-sent preference mails (`site_down`, `site_up`, `site_version_update`, `deploy_failed`).
- Token binds: user id (or email) + notification key + expiry.
- Click sets that Plane user’s opt-out for that key (store on User preferences or small table).

## CMS surface

### Preference storage

- **Site defaults:** keep `admin_notifications` settings for defaults + visitor debounce (site-level debounce stays site-level).
- **Per-user overrides:** new persistence keyed by `site_id` + `user_id` for:
  - panel types: `{ in_app, email }`
  - platform CMS keys: `{ email }` (member_new, order_new, weekly_visitor_report, site_published, site_unpublished)
- Resolution: user override if present, else site default, else catalog default.
- UI: “Bildirim tercihlerim” (profile or settings subsection) editable by any admin for self; site defaults page remains for `settings.edit`.

### Sending

- `AdminNotificationDispatcher::queueEmail`: for each active admin with `allowsEmail(site, user, type)`, queue mail to **that user’s email** (not only `contact_email`). Optional: still allow a site “ops alias” later — not in v1.
- `PlatformSoftwareMailer`: same fan-out for platform CMS keys; Plane `admin_recipient` becomes fallback when no opted-in admins (document explicitly: fallback only if zero opted-in, or drop fallback — **prefer fallback to `admin_recipient` / contact for ops continuity** when no user prefs yet).
- Migration path: existing site prefs seed as defaults; until a user saves, they inherit site defaults (no mass opt-out).

### Unsubscribe (site)

- Signed token: `user_id`, `site_id`, `type` (panel or platform key), expiry (~30–90 days).
- Handler flips that user’s email preference off for that type; idempotent.
- Templates: `mail/admin-notification.blade.php`, `mail/platform-software.blade.php` — footer link + plain-text equivalent.
- Headers: `List-Unsubscribe` + one-click POST where practical.

## Error handling

| Case | UX |
|------|-----|
| Validation | Field + form errors, no success flash |
| SMTP test fail | `session('error')`, no secrets in message |
| Push partial fail | warning flash or audit; save still succeeded |
| Bad unsubscribe token | generic expired/invalid page |

## Tests

**Plane:** save flash; validation visible; test-mail success/fail; push job dispatched (or sync count); unsubscribe for ops type.

**CMS:** per-user allow/deny; fan-out recipient list; unsubscribe one-click; transactional mails have no unsubscribe; configure endpoint unchanged for Plane SMTP push.

## Docs to update (implementation)

- Plane: `docs/modules/platform-mail.md`, progress ledger, CHANGELOG
- CMS: `docs/modules/admin-notifications.md`, `docs/modules/control-plane-agent.md` (recipient/prefs note), CHANGELOG

## Non-goals (restate)

Hostinger / H-Posta, site `/admin/mail/settings` customer SMTP, newsletter lists, Mailcow, multi-tenant SaaS theme upload.
