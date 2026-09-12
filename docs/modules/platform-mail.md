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
- `tests/Feature/Ops/PlatformMailUnsubscribeTest.php` — signed unsubscribe, opt-out storage, mail headers
- CMS: `ControlPlanePlatformMailConfigureTest`
