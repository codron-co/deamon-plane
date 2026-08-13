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
5. Plane kendi Coolify stack’inde çalışır: **Docker Compose** + `docker-compose.coolify.yml` (kendi MySQL+Redis; Nixpacks yok).
6. Coolify client imzaları: [plans/2026-08-13-coolify-spike-notes.md](plans/2026-08-13-coolify-spike-notes.md) (canlı Go henüz yok).
7. Kurulum: [modules/deployment.md](modules/deployment.md).
