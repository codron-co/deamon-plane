# Architecture (özet)

Living özet. Detay ve task’lar: [plans/2026-08-13-deamon-plane.md](plans/2026-08-13-deamon-plane.md).

## Diyagram

```text
┌─────────────────────────────────────────────────────────────┐
│  Deamon Plane (bu repo — Laravel)                           │
│  Sites / Domains / Channels / Deploys / Themes / Audit      │
└───────────────┬─────────────────────────────┬───────────────┘
                │ Coolify API                 │ GitHub App
                ▼                             ▼
┌───────────────────────────┐     ┌───────────────────────────┐
│ Coolify                   │     │ Theme org                 │
│ App per customer:         │     │ deamon-theme-{id}         │
│  app + mysql + redis      │     │ push webhook → plane      │
│  branch = channel         │     └─────────────┬─────────────┘
└─────────────┬─────────────┘                   │
              │ signed agent                    │ git clone/pull
              ▼                                   ▼
┌───────────────────────────┐
│ Deamon CMS instance       │
│ /internal/control/v1/*    │
└───────────────────────────┘
```

## Sabit kararlar

1. Site başına **ayrı MySQL + Redis** (paylaşımlı havuz yok).
2. Kanal = git branch (`main` | `beta` | `alpha`) + Coolify redeploy.
3. Tema = git (ZIP plane UI’da yok); push → opt-in auto update.
4. v1 = internal ops only.
5. Plane kendi Coolify stack’inde çalışır: **Docker Compose** + `docker-compose.coolify.yml` (kendi MySQL+Redis; Nixpacks yok). Laravel 12 ops scaffold (Task 0) is in this repo. Fleet schema (Task 1): `sites`, `site_domains`, `deployments`, `audit_logs` (theme tables are Dalga 4). Task 3: draft Site CRUD at `/sites` (desired state only; no Coolify HTTP / provision).
6. Coolify HTTP adapter (Task 2): [modules/coolify-client.md](modules/coolify-client.md). Method lock: [plans/2026-08-13-coolify-spike-notes.md](plans/2026-08-13-coolify-spike-notes.md) (**Go**). Token: `coolify_settings.api_token` encrypted; Settings UI Test connection = `listServers` via `Http::fake` in tests.
7. Kurulum: [modules/deployment.md](modules/deployment.md).
