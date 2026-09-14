# Admin password invite — Plane slice

**Date:** 2026-09-14  
**Status:** done (2026-09-14, branch `alpha`)  
**Repos:** `deamon-plane`  
**Plan:** `docs/superpowers/plans/2026-09-14-admin-password-invite.md`  
**CMS SoT:** `codron-co/deamon` → `docs/superpowers/specs/2026-09-14-admin-password-invite-design.md`

## Goal (Plane)

1. Site Admins tab: create admin with **invite** mode (no password; CMS sends set-password mail).
2. Resend **şifre oluşturma maili** for passwordless admins (`password_is_set=false`).
3. Remove `DEAMON_DEFAULT_ADMIN_PASSWORD` from Coolify env default catalogs and provision/deploy expectations.

## Locked (Plane)

| Topic | Choice |
|-------|--------|
| Create modes | generate \| manual \| **invite** |
| Invite flash | No one-time password |
| Resend | Operator+; audit `site.admin.password_invite_sent` |
| Secrets | Never store invite tokens or passwords |
| Catalog | Drop generated `DEAMON_DEFAULT_ADMIN_PASSWORD` |

## Work checklist

- [x] `CoolifyEnvDefaultCatalog` — remove admin password rows (+ migration deletes leftover catalog rows)
- [x] Agent client + `SiteAdminController` — invite create + password-invite
- [x] Admins Blade/JS — third mode + resend action
- [x] Lang TR/EN, audit actions
- [x] Tests: `SiteAdminAgentTest`, provision/Coolify env tests
- [x] Docs: `site-admins.md`, `coolify-client.md`, `provision-site.md`, ledger

## Depends on

CMS agent exposing `password_is_set`, invite create, and `POST /admins/{id}/password-invite` (see CMS SoT).
