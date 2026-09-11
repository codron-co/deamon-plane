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

`/platform-mail` — SMTP + notification toggles. Save pushes to every site with an agent secret.

Notifications support:

- Global on/off (+ weekly day/hour, version threshold major/minor/patch)
- Per-site override on site detail **Infrastructure → Yazılım e-postası** (`platform_notification_overrides`, `platform_mail_recipient`)

## Plane → CMS

`POST {agent_base_url}/internal/control/v1/platform-mail/configure`

HMAC same as Hostinger configure. Body includes `enabled`, `site_id`, `plane_base_url`, `admin_recipient`, `smtp` (when enabled), `notifications`. Password is encrypted at rest on CMS; never logged.

## Plane-side senders

- `SiteHealthMailNotifier` after agent health (down/up + version)
- `CoolifyDeploymentSync` when a deployment becomes failed

## Tests

`tests/Feature/Ops/PlatformMailSettingsTest.php`. CMS: `ControlPlanePlatformMailConfigureTest`.
