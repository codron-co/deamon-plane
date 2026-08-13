# Coolify API spike (Dalga 0)

Live proof script for Deamon Plane Faz 0. **Does not scaffold Laravel.**

## Setup

1. Copy `.env.example` → `.env` (gitignored).
2. Fill `COOLIFY_BASE_URL` + `COOLIFY_API_TOKEN`.
3. Set `COOLIFY_STAGING_APP_UUID` to a **staging** compose app (not a customer production site).

```powershell
cd tools/coolify-spike
Copy-Item .env.example .env
# edit .env
.\spike.ps1              # read-only: list apps/servers/projects + get staging app
.\spike.ps1 -Mutate      # staging only: branch patch + deploy + poll + domain dry-check
```

Token is sent as `Authorization: Bearer`. Output redacts tokens. Do not paste full JSON with env values into git.

Plane itself is also a Coolify **Docker Compose** app (`docker-compose.coolify.yml` in this repo). This script talks to the Coolify **API** to manage customer apps; it does not deploy Plane.

## Modes

| Flag | What it does |
|------|----------------|
| (default) | GET list + GET staging app + GET storages/envs keys (values redacted) |
| `-Mutate` | PATCH `git_branch` on staging, POST `/deploy`, poll deployment, PATCH domain check |

Production fleet apps are out of scope for `-Mutate`.
