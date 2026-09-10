# Architecture (özet)

Living özet. Detay ve task’lar: [plans/2026-08-13-deamon-plane.md](plans/2026-08-13-deamon-plane.md).

## Diyagram

```text
┌─────────────────────────────────────────────────────────────┐
│  Deamon Plane (bu repo — Laravel)                           │
│  Sites / Domains / Channels / Deploys / Themes / Mail / Audit     │
└───────────────┬─────────────────────────────┬───────────────┘
                │ Coolify API                 │ One Plane GitHub App
                ▼                             ▼
┌───────────────────────────┐     ┌───────────────────────────┐
│ Coolify                   │     │ Theme git connections (N) │
│ App per customer:         │     │ user and/or org installs  │
│  app + mysql + redis      │     │ all | selected repos      │
│  branch = channel         │     │ App webhook → plane       │
│  GET /github-apps ≠ catalog│     └─────────────┬─────────────┘
└─────────────┬─────────────┘                   │
              │ signed agent                    │ git clone/pull
              ▼                                   ▼
┌───────────────────────────┐     clone_token = installation token
│ Deamon CMS instance       │     (PAT never sent to a site)
│ /internal/control/v1/*    │
└───────────────────────────┘
```

Theme catalog connections: [modules/theme-catalog.md](modules/theme-catalog.md). Spec: [superpowers/specs/2026-09-10-theme-git-connections-design.md](superpowers/specs/2026-09-10-theme-git-connections-design.md).

## Sabit kararlar

1. Site başına **ayrı MySQL + Redis** (paylaşımlı havuz yok).
2. Kanal = git branch (`main` | `beta` | `alpha`) + Coolify redeploy.
3. Tema = git (ZIP plane UI’da yok); push → opt-in auto update.
4. v1 = internal ops only.
5. Plane kendi Coolify stack’inde çalışır: **Docker Compose** + `docker-compose.coolify.yml` (kendi MySQL+Redis; Nixpacks yok). Laravel 12 ops scaffold (Task 0) is in this repo. Fleet schema (Task 1): `sites`, `site_domains`, `deployments`, `audit_logs`. Theme schema (Task 10): `themes`, `theme_site_access`, `site_theme_installations`, `github_settings` (App singleton) plus `theme_git_connections` / `theme_git_connection_repos` (2026-09-10). Task 3: draft Site CRUD at `/sites` (desired state). Task 4: `SiteProvisioner` + provision/poll jobs — Coolify compose app per site (app+MySQL+Redis), env `APP_KEY`+`DEAMON_SITE_NAME`, poll until finished/failed. Task 5: `ChannelSwitcher` — PATCH `git_branch` + APP_ENV + deploy on the same app; confirm for main→beta/alpha; version gate reads last health `deamon_version` when present (missing health does not block); force = Super Admin; `desired_channel` while `deploying`. Tests: `Http::fake` only.
6. Coolify HTTP adapter (Task 2): [modules/coolify-client.md](modules/coolify-client.md). Method lock: [plans/2026-08-13-coolify-spike-notes.md](plans/2026-08-13-coolify-spike-notes.md) (**Go**). Token: `coolify_connections.api_token` encrypted (legacy `coolify_settings` fallback); Coolify menu Test connection = `listServers` via `Http::fake` in tests. Settings is customer/env defaults; theme GitHub connect lives under **Themes** (Manifest + installations). Coolify `GET /github-apps` is not the theme App.
7. Kurulum: [modules/deployment.md](modules/deployment.md). Provision: [runbooks/provision-site.md](runbooks/provision-site.md). Channel switch: [runbooks/channel-switch.md](runbooks/channel-switch.md). Fleet import (Task 7): [runbooks/import-coolify-apps.md](runbooks/import-coolify-apps.md) — `ops:import-coolify-apps` dry-run required; `--apply` upserts Deamon customer apps only (`Http::fake` in tests). Agent secret inject (Task 9): [runbooks/agent-secret-inject.md](runbooks/agent-secret-inject.md).
8. Deploy visibility (Task 6): `POST /webhooks/coolify` (HMAC or query `token`) updates `deployments.status` (same rows as `PollDeploymentJob`). Fleet home KPIs: total sites, by channel, unhealthy (`status=error` or agent fail/stale), failed deploys, deploying. Auth scheme: [modules/coolify-webhooks.md](modules/coolify-webhooks.md). GitHub theme webhooks: [modules/github-webhooks.md](modules/github-webhooks.md).
9. Site agent (Task 9): [modules/agent-client.md](modules/agent-client.md). Plane signs `GET /internal/control/v1/health` (HMAC `{timestamp}.{nonce}.{rawBody}`) with locked CMS headers `X-Deamon-Timestamp` / `X-Deamon-Nonce` / `X-Deamon-Signature` (Task 8, Deamon v1.1.43). Theme assign (Task 12): [modules/theme-agent-client.md](modules/theme-agent-client.md) — same headers; catalog [modules/theme-catalog.md](modules/theme-catalog.md). Hostinger mail: configure POST + reverse `/internal/site/v1/mail` ([modules/mail-servers.md](modules/mail-servers.md)).
10. Harden (Task 14): [security.md](security.md). Plane production deploy checklist: [runbooks/deploy-plane.md](runbooks/deploy-plane.md) (live Coolify mutate of the Plane app is optional/high-risk).
