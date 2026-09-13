# Software mail (Deamon product SMTP)

Plane holds the **software / ops SMTP** used for Deamon admin and fleet emails. This is separate from Hostinger mailbox servers ([mail-servers.md](mail-servers.md)).

## Split

| Channel | Where configured | Examples |
|---------|------------------|----------|
| Software mail | Plane → CMS `platform-mail/configure` | Admin password reset, new admin welcome, weekly visitor report, new member/order to admin, site published/unpublished |
| Plane ops mail | Same Plane SMTP, sent by Plane | Site down / recovered, Deamon version change, deploy failed |
| Site mail module | CMS `/admin/mail/settings` | Contact form, newsletter, customer order receipts |
| Hostinger mailboxes | Plane mail servers + bindings | Create/list mailbox via H-Posta |

## Global settings UI

`/platform-mail` — SMTP + notification toggles.

### Where it lives in the nav

The sidebar has a **Posta** group with two entries: **Posta sunucuları** (`/mail-servers`) and
**Yazılım maili** (`/platform-mail`). Both are plain links, always rendered, so platform mail is one
click from any page. Before this, the single `Mail` item linked to `/mail-servers` while also
matching `ops.platform-mail*` for its active state, so the product SMTP was reachable only through a
ghost link on the mail servers page and the nav highlighted the wrong entry once you got there.

### State chip

`PlatformMailState::current()` is the one answer both mail pages render, so they cannot disagree:

| Key | Chip (tr) | When |
|-----|-----------|------|
| `unconfigured` | `Yapılandırılmadı` | no row, or `isReady()` false (disabled or missing host / user / password / from) |
| `push_failed` | `Son gönderim başarısız · :count site` | at least one site whose **last** push attempt failed |
| `not_pushed` | `Etkin · sitelere aktarılmadı` | ready, but `last_pushed_at` is null |
| `active` | `Etkin` | ready, pushed, and no site is in a failed state |

Every chip carries a visible sentence next to it, not a `title` tooltip, and the tone is never the
only signal. The chip never renders SMTP credentials; `platform_mail.fields.password_saved`
behaviour on the form is untouched.

`sites.platform_mail_pushed_at` / `platform_mail_push_failed_at` / `platform_mail_push_error` are
written by `PlatformMailConfigurer::sync()` — the single choke point for both the queued push and the
site-detail sync. A success **clears** the failure columns, so "failed" always means "the last
attempt failed", not "it failed once in the past". Sites that were never pushed (no agent secret) stay
null and are not counted as failures. Reasons are short codes (`timeout`, `http_500`, `no_base_url`),
never a response body, so nothing leaks into the panel.

### Save vs push to sites

- **Save** (`PUT`) persists `PlatformMailSetting`, writes an audit log, and flashes success immediately (`platform_mail.flash.saved_push_queued`). Validation errors stay on the form; no success flash on failed validation.
- **Push to sites** does not block the redirect: both save and manual **Push to sites** dispatch `DispatchPlatformMailPushJob`, which queues one `PushPlatformMailJob` per site that has an agent secret. Failures are handled in the per-site jobs (log/audit); the operator always gets the queued flash, not a synchronous per-site count.
- Manual push uses the same job and flashes `platform_mail.flash.pushed_queued`.

### Test mail

- `POST /platform-mail/test` (`ops.write`) — optional body field `to`; default is `default_admin_recipient`.
- Requires saved settings and `PlatformMailSetting::isReady()`; otherwise an error flash (no secrets in messages).
- Sends via the same runtime SMTP as ops mail (`PlatformOpsMailer::sendTest`), using **From** from saved settings. Does not depend on notification catalog toggles.

Notifications support:

- Global on/off (+ weekly day/hour, version threshold major/minor/patch)
- Per-site override on site detail **Infrastructure → Yazılım e-postası** (`platform_notification_overrides`, `platform_mail_recipient`)

## Plane → CMS

`POST {agent_base_url}/internal/control/v1/platform-mail/configure`

HMAC same as Hostinger configure. Body includes `enabled`, `site_id`, `plane_base_url`, `admin_recipient`, `smtp` (when enabled), `notifications`. Password is encrypted at rest on CMS; never logged.

## Plane-side senders

- `SiteHealthMailNotifier` after agent health (down/up + version)
- `CoolifyDeploymentSync` when a deployment becomes failed
- `PlatformOpsMailer` — respects Plane user opt-outs (see below); adds unsubscribe footer and `List-Unsubscribe` for preference-based ops keys

## Ops mail unsubscribe (Plane host)

Plane-sent preference mails (`site_down`, `site_up`, `site_version_update`, `deploy_failed`) include a signed one-click unsubscribe link. **Unsubscribe URLs are on the Plane app** (not customer sites), per locked design.

- Public routes (no login): `GET` and `POST` `/platform-mail/unsubscribe` — Laravel temporary signed URL (`user` + `key`, 60-day expiry).
- Valid click sets `users.mail_notification_opt_outs[key] = true` for that Plane user; confirmation view `ops.platform-mail.unsubscribed`.
- Invalid/expired signature → HTTP 403.
- Primary recipient email that is not a Plane `User` is still sent mail; only `User` recipients get opt-out filtering and personalized unsubscribe links.

CMS-sent platform mail uses the customer site unsubscribe host (companion CMS plan; not implemented in Plane).

## Tests

- `tests/Feature/Ops/PlatformMailSettingsTest.php` — save flash, validation, push job dispatch, test mail
- `tests/Feature/Ops/PlatformMailStateTest.php` — nav reachability from any page, the four chip states, per-site push outcome recording
- `tests/Feature/Ops/PlatformMailUnsubscribeTest.php` — signed unsubscribe, opt-out storage, mail headers
- CMS: `ControlPlanePlatformMailConfigureTest`
