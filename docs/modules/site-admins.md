# Site admin agent

Plane manages Deamon CMS `role=admin` users over the signed site agent. Spec: [../superpowers/specs/2026-09-11-site-admin-management-design.md](../superpowers/specs/2026-09-11-site-admin-management-design.md). CMS SoT: `codron-co/deamon` `docs/modules/control-plane-agent.md` (**≥ 1.2.13**).

## CMS endpoints

Base: `{agent_base_url}/internal/control/v1`

| Method | Path | Notes |
|--------|------|--------|
| GET | `/admins` | Allowlisted list (no passwords / 2FA secrets) |
| POST | `/admins` | `name`, `email`, `password` |
| POST | `/admins/{id}/password` | Force reset; clears 2FA + sessions |
| PATCH | `/admins/{id}` | `{ is_active }` only |
| DELETE | `/admins/{id}` | Hard delete |

Last **active** admin cannot be deactivated or deleted. Customers (`role≠admin`) are invisible.

## Plane

| Piece | Role |
|-------|------|
| `SiteAgentClient` | Signed GET/POST/PATCH/DELETE |
| `AdminAgentResult` | Allowlist + outdated/needs_secret |
| `SiteAdminController` | Mutations + one-time password flash |
| Site detail **Admins** tab | List + create (generate/manual password) |

RBAC: operator list/create/reset; Super Admin deactivate/delete. Audit never logs passwords. Tests: `tests/Feature/Agent/SiteAdminAgentTest.php` (`Http::fake` only).
