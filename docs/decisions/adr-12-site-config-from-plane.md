# ADR-12 · Site configuration comes from Plane; env is bootstrap only

**Status:** accepted (2026-09-24, product owner). Implementation is staged; see "Transition" below.
**CMS side:** `codron-co/deamon` → `docs/modules/configuration-sources.md` (source of truth for the per-key table).
**Findings behind it:** CMS `docs/urun-analizi-2026-09/30-oncelikli-backlog.md` — F-G-03 (P0), C-H-03, E-E-10, E-B-09.

## Context

Every Coolify env change on a customer app needs a redeploy. The env catalog (CMS `.env.production.example`, synced per branch) is already small, but three problems remain:

- **Channel → `APP_ENV`.** `ChannelEnvironmentMap::appEnv()` writes `alpha → local`, `beta → staging`. Live customer sites run on `alpha`, so they run with `APP_ENV=local`. On CMS this opens theme-editor PHP/Blade writes (`allow_php_edit` defaults to `APP_ENV !== 'production'`) and makes canonical/robots/schema URLs `http://`. The catalog comment says "beta/alpha → staging"; code says `local`. Docs and code disagree.
- **Env as a settings channel.** Security switches (`DEAMON_THEME_EDITOR_ALLOW_PHP`, `DEAMON_CSP_*`, `DEAMON_SECURITY_*`) and the reported version (`DEAMON_VERSION`) can all be changed from env. Env changes leave no Plane audit trail and race auto-deploy.
- **Proven pattern.** The agent push pattern already works for site name (`/site/identity`, CMS 1.2.27), platform SMTP (`/platform-mail/configure`), DeskRon (`/deskron/configure`) and mailboxes (`/mail/configure`): no deploy, audited in Plane, persisted in the site DB.

## Decision

Each site setting belongs to exactly one source.

1. **Env (bootstrap only).** Only values needed before the app can reach its DB, plus the agent trust anchor. Target set of 6:
   - `APP_KEY`, `DB_PASSWORD`, `MYSQL_ROOT_PASSWORD`, `CONTROL_PLANE_AGENT_SECRET`, `CONTROL_PLANE_HOST_ALLOWLIST`, `DEAMON_CHANNEL`.
   - Coolify injects (`SERVICE_*`, `COOLIFY_*`) are not ours and are never written or pruned.
   - **Env prune must never delete these six.**
2. **Plane (runtime).** Anything that varies per site or per agency is pushed over a signed agent endpoint (`/internal/control/v1/*`) and persisted in the site DB, encrypted when secret. Each such setting must:
   - have a safe default in CMS code (a missing setting is a default, not an error);
   - keep the last-known value when Plane is down;
   - be reported back in health, so Plane can detect drift and re-push (the `site_name` / `core_theme` pattern).
3. **Code (fixed).** Security baseline and environment behaviour are fixed in CMS code and never loosened by env or Plane:
   - `APP_ENV=production` and `APP_DEBUG=false` on live sites;
   - theme-editor PHP/Blade off;
   - security headers and CSP on;
   - 2FA baseline.

**`APP_ENV` is independent of channel.** A channel (`main` / `beta` / `alpha`) only selects the git branch that is deployed.

**No new env keys.** A new catalog line needs a written reason why the value cannot come from the DB or Plane.

## Consequences

**Good:**
- Setting changes need no redeploy.
- One source of truth, audited in Plane.
- Drift is detectable.
- Fleet-wide changes use the same fan-out as other Plane actions.
- Secrets leave the Coolify env UI.
- The `alpha → local` class of bug disappears.

**Costs:**
- Plane becomes a critical dependency for *changes*, not for *running*: sites keep the last pushed value.
- A Plane compromise reaches the fleet. That is already true with the signed agent, and the code-fixed baseline limits it.
- Every new setting needs an agent endpoint, a CMS default and a health field.
- Local dev and tests need config fallbacks. Those must be inert in production.

**Not in scope:**
- Plane's own env (its bootstrap: `APP_KEY`, DB, `COOLIFY_BASE_URL`, …) — see [../runbooks/deploy-plane.md](../runbooks/deploy-plane.md).
- Customer-entered business settings (payment keys, AI key, SEO IDs). These already live in the CMS admin → site DB and stay there.

## Transition

| Step | Owner | Status |
|---|---|---|
| Env prune never deletes the 6 bootstrap keys | Plane `CoolifyAppEnvSync` (`BOOTSTRAP_KEYS`) | Done (2026-09-24) |
| Remove `APP_ENV` from the catalog; compose sets `APP_ENV: production`; `ChannelEnvironmentMap::appEnv()` no longer drives env | CMS catalog + compose, Plane | Open — F-G-03 (P0), week 0 |
| `allow_php_edit` off in production regardless of env | CMS | Open — F-G-03 |
| Canonical/robots/schema scheme not derived from `APP_ENV` | CMS | Open — C-H-03 |
| Remove `DEAMON_SITE_NAME` from the catalog; provision pushes `/site/identity` right after first health | CMS catalog, Plane provision | Open |
| Security switches and `DEAMON_VERSION` become code constants in production | CMS | Open — F-G-13, E-B-09 |
| Health reports applied-settings version/hash; Plane re-pushes on drift | CMS health v2, Plane | Open — N-09 |
| New settings (module entitlements, CSP extra hosts, Google Business agency OAuth, backup target) ship as agent endpoints | CMS + Plane | Planned — N-01, C-G-10, N-02 |

Until the `APP_ENV` step ships, docs that describe `ChannelEnvironmentMap` describe **current behaviour**, not the target.
