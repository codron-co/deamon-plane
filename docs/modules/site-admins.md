# Site admin agent

Plane manages Deamon CMS `role=admin` users over the signed site agent. Spec: [../superpowers/specs/2026-09-11-site-admin-management-design.md](../superpowers/specs/2026-09-11-site-admin-management-design.md). CMS SoT: `codron-co/deamon` `docs/modules/control-plane-agent.md` (**≥ 1.2.13**; invite mode and password-invite need **≥ 1.2.18**). Invite spec: [../superpowers/specs/2026-09-14-admin-password-invite-design.md](../superpowers/specs/2026-09-14-admin-password-invite-design.md).

## CMS endpoints

Base: `{agent_base_url}/internal/control/v1`

| Method | Path | Notes |
|--------|------|--------|
| GET | `/admins` | Allowlisted list (no passwords / 2FA secrets). 1.2.18+ adds `password_is_set`; Plane treats a missing key as `true`. |
| POST | `/admins` | `name`, `email` + either `password` or `password_mode: invite` (1.2.18+: passwordless admin, CMS mails a set-password link) |
| POST | `/admins/{id}/password` | Force reset; clears 2FA + sessions |
| POST | `/admins/{id}/password-invite` | 1.2.18+: (re)send the set-password mail. Empty body. Token never leaves the CMS. |
| PATCH | `/admins/{id}` | `{ is_active }` only |
| DELETE | `/admins/{id}` | Hard delete |

Last **active** admin cannot be deactivated or deleted. Customers (`role≠admin`) are invisible.

## Plane

| Piece | Role |
|-------|------|
| `SiteAgentClient` | Signed GET/POST/PATCH/DELETE |
| `AdminAgentResult` | Allowlist + outdated/needs_secret |
| `SiteAdminController` | Mutations + one-time password flash (generate / manual only) |
| Site detail **Admins** tab | List + create (generate / manual / **invite**) + **Şifre oluşturma maili gönder** on `password_is_set=false` rows |

Create modes: **generate** and **manual** flash the password once (`admin_password_once`); **invite** sends `password_mode=invite`, no `password` key, and flashes nothing. Resend posts an empty body to `/password-invite` behind a (non-danger) confirm.

RBAC: operator list/create/reset/invite; Super Admin deactivate/delete. Audit: `site.admin.created` (`password_mode: invite|password`), `site.admin.password_reset`, `site.admin.password_invite_sent` (admin id + email only). Never log passwords or invite tokens. Outdated CMS (404 without an error code) shows the existing `sites.admins.errors.outdated` flash.

Coolify no longer seeds `DEAMON_DEFAULT_ADMIN_PASSWORD` (dropped from both env packs, leftover rows deleted by `2026_09_14_120000_drop_admin_password_coolify_env_defaults`). The CMS first admin starts passwordless; use invite to hand it over.

Tests: `tests/Feature/Agent/SiteAdminAgentTest.php` (`Http::fake` only).
