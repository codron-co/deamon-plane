# Site Admin Management — Design

**Date:** 2026-09-11  
**Status:** implemented  
**Repos:** `deamon-plane` (ops UI + agent client) + `deamon` (CMS agent endpoints)

## Goal

Operators manage each Deamon site’s `role=admin` users from Plane site detail, over the existing HMAC site agent. Plane never stores admin passwords and never opens customer MySQL.

## Decisions

| Topic | Choice |
|-------|--------|
| Transport | HMAC `X-Deamon-*` to `/internal/control/v1/admins*` (same as health/themes/mail) |
| Scope | list, create, password reset, deactivate/reactivate, hard delete |
| Password | Plane generates by default; optional manual (min 12, mixedCase, numbers, symbols). One-time flash reveal only |
| RBAC | Operator: list / create / reset. Super Admin: deactivate / delete |
| Guard | Last **active** admin cannot be deactivated or deleted (CMS + UI) |
| Out of scope | Direct DB access, Plane admin mirror, email/name PATCH, customer users, Coolify `DEAMON_DEFAULT_ADMIN_PASSWORD` as management path |

## CMS contract (Deamon ≥ 1.2.13)

| Method | Path |
|--------|------|
| GET | `/internal/control/v1/admins` |
| POST | `/internal/control/v1/admins` |
| POST | `/internal/control/v1/admins/{id}/password` |
| PATCH | `/internal/control/v1/admins/{id}` (`is_active` only) |
| DELETE | `/internal/control/v1/admins/{id}` |

Allowlisted fields: `id`, `name`, `email`, `is_active`, `must_change_password`, `has_two_factor`, `created_at`. Password reset clears 2FA and DB sessions.

## Plane surface

- Site detail **Admins** tab (`resources/views/ops/sites/_admins.blade.php`)
- `SiteAdminController` + `SitePolicy::{manageAdmins,toggleAdminActive,destroyAdmin}`
- `SiteAgentClient` admin methods + `AdminAgentResult`
- Audit actions: `site.admin.created|password_reset|activated|deactivated|deleted` (email/id only)
- 404 from CMS → “Deamon 1.2.13+” message

## Why not direct MySQL

Same Coolify network access is fragile (per-app networks, credential sprawl, schema coupling). A second Coolify has no shared Docker network without VPN/overlay. HTTPS agent already works across fleets without exposing MySQL.
