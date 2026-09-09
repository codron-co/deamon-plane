# Coolify API spike notes — Deamon Plane Dalga 0

**Date:** 2026-08-13  
**Source of truth (API):** Coolify OpenAPI `v4.x` (`https://github.com/coollabsio/coolify/blob/v4.x/openapi.yaml`, servers: `{host}/api/v1`)  
**Live instance:** `https://dev.codron.cloud` (Coolify **4.3.1**) — read-only list + authorized mutate on **Susa DEMO** only.

This file is secret-free. Tokens and env **values** must never be committed. Staging/demo resource UUIDs below are operational IDs, not secrets. Do not copy production customer app UUIDs into git.

## Verdict (this pass)

| Gate | Value |
|------|--------|
| **Go / Hybrid / No-Go** | **Go** — list + updateBranch + deploy + getDeployment OK; setDomains OpenAPI-proven (live PATCH skipped by design); volumes unchanged after `alpha`→`beta` redeploy |
| OpenAPI coverage | Confirmed on live instance for list, GET app, storages, env keys, servers/projects, PATCH `git_branch`, POST `/deploy`, GET `/deployments/{uuid}` |
| Volume persistence (ADR-7) | **OK (live)** — six persistent volume names identical before/after channel switch |
| Laravel scaffold | **Not started** (Dalga 1 **may start** — this spike does not scaffold it) |

**Left staging channel:** Susa remains on **`beta`** (was `alpha`). Site `https://susa.demo.codron.co/` `/up` and `/` returned HTTP 200 after deploy `finished`. Deamon Plane app `d6ovbjzxgpao23faam3vrcve` was **not** mutated.

---

## Auth

- Header: `Authorization: Bearer {token}`
- Base: `{COOLIFY_BASE_URL}/api/v1` (self-hosted: change host; cloud example `https://app.coolify.io/api/v1`)
- Token: Coolify **Keys & Tokens** (least privilege). Store later in plane DB encrypted — never git.

---

## Adapter method map (lock for Dalga 2 `CoolifyClient`)

Plane names ↔ Coolify HTTP. Paths are under `/api/v1`.

| Plane method | HTTP | Notes |
|--------------|------|--------|
| `listApps(?tag)` | `GET /applications?tag=` | Array of `Application`. Filter fields: `uuid`, `name`, `git_branch`, `build_pack`, `fqdn`, `git_repository`. **Live:** compose apps often have `fqdn=null`; use `docker_compose_domains`. Tag query supports §18 “tek project + tag/slug”. |
| `getApp(uuid)` | `GET /applications/{uuid}` | Full `Application`. |
| `listServers()` | `GET /servers` | Bootstrap server picker. |
| `listProjects()` | `GET /projects` | Bootstrap project picker. |
| `createComposeApp(...)` | `POST /applications/private-github-app` **or** `POST /applications/private-deploy-key` **or** `POST /applications/public` | **Git + `build_pack=dockercompose`**. Required: `project_uuid`, `server_uuid`, `environment_name` **or** `environment_uuid`, `git_repository`, `git_branch`, `build_pack`. Private GH App also needs `github_app_uuid`; deploy-key path needs `private_key_uuid`. Set `docker_compose_location=docker-compose.coolify.yml`. Optional `instant_deploy`, `name`, `docker_compose_domains`. |
| ~~`POST /applications/dockercompose`~~ | deprecated | Docs: “use POST /services”. That path wants `docker_compose_raw` **without git** — **wrong** for Deamon customer sites. Do not use for provision. |
| `updateEnvs(uuid, pairs)` | `PATCH /applications/{uuid}/envs/bulk` body `{ "data": [ { "key", "value" } ] }` | Also `POST /applications/{uuid}/envs` create; `PATCH .../envs` update one. Customer Coolify env: **only** `APP_KEY` + `DEAMON_SITE_NAME` (+ Coolify `SERVICE_*`). |
| `listEnvs(uuid)` | `GET /applications/{uuid}/envs` | Import / rotate. Never log `value`. |
| `setDomains(uuid, fqdn)` | `PATCH /applications/{uuid}` | OpenAPI: compose **`docker_compose_domains`: `[{ "name": "app", "domain": "https://fqdn" }]`**. **Live GET shape (Susa):** JSON **string** object `{"app":{"domain":"https://susa.demo.codron.co,https://www.susa.demo.codron.co/"}}` — not an array; `Application.fqdn` is **null** for compose apps. Adapter must parse the string and read `app.domain`. Conflict → HTTP 409-style payload: `force_domain_override=true` only with Super Admin + audit. Live PATCH skipped on Susa (do not bind a random domain). |
| `updateBranch(uuid, branch)` | `PATCH /applications/{uuid}` `{ "git_branch": "main\|beta\|alpha" }` | Channel switch. Allowlist in plane, not Coolify. |
| `deploy(uuid, force?)` | `POST /deploy?uuid={uuid}&force=` | Response: `{ deployments: [ { resource_uuid, deployment_uuid, message } ] }`. |
| `getDeployment(deploymentUuid)` | `GET /deployments/{uuid}` | Schema `ApplicationDeploymentQueue` (`status`, etc.). |
| `listAppDeployments(appUuid)` | `GET /deployments/applications/{uuid}?skip&take` | Site Deployments tab + poll fallback. |
| `listRunningDeployments()` | `GET /deployments` | Currently running only. |
| `listStorages(uuid)` | `GET /applications/{uuid}/storages` | `{ persistent_storages, file_storages }` — volume persistence check. |
| `start/stop/restart` | `POST /applications/{uuid}/start\|stop\|restart` | Not required for Faz A happy path. |

### Create body (git compose) — draft

```json
{
  "project_uuid": "<default project>",
  "server_uuid": "<target server>",
  "environment_name": "production",
  "github_app_uuid": "<Coolify GH App — private repo>",
  "git_repository": "https://github.com/codron-co/deamon.git",
  "git_branch": "main",
  "build_pack": "dockercompose",
  "docker_compose_location": "docker-compose.coolify.yml",
  "name": "deamon-izyem",
  "instant_deploy": true,
  "docker_compose_domains": [
    { "name": "app", "domain": "https://www.example.com" }
  ]
}
```

`codron-co/deamon` is private → expect **GitHub App or deploy key**, not `POST /applications/public`, unless the instance already mirrors a public fork.

### Application UUID field

OpenAPI `Application.uuid` (string). Plane `sites.coolify_app_uuid` maps here. Integer `id` is internal DB — do not store as Coolify id.

---

## Domain bind

- Compose service name in `docker-compose.coolify.yml` is **`app`** (port expose 8080). Domain must attach to that service via `docker_compose_domains` key **`app`**.
- Live GET (Susa): `fqdn` is null; domains live only in `docker_compose_domains` (JSON string). Trailing slash on `www` host is present on the existing record — do not “fix” during spike.
- `force_domain_override` default **false**. Conflict response includes `conflicts[]` with `domain`, `resource_name`, `resource_uuid`.
- Plane: unique `primary_domain`; surface Coolify conflict; do not silently override.
- **Live PATCH:** skipped (`SPIKE_PROBE_DOMAIN` unset). Do not bind a random domain on Susa.

---

## Plane itself — Coolify Docker Compose (this repo)

Plane is installed the **same way** as a customer site: Coolify **Docker Compose** build pack, not Nixpacks / Dockerfile-only.

| | Plane (`deamon-plane`) | Customer (`deamon`) |
|--|------------------------|---------------------|
| Compose file | `docker-compose.coolify.yml` | `docker-compose.coolify.yml` |
| Services | `app` + mysql + redis | `app` + mysql + redis |
| Volumes | `plane_storage`, `plane_mysql`, `plane_redis` | `deamon_storage`, `deamon_themes`, `deamon_mysql`, `deamon_redis` |
| Coolify env (minimal) | `APP_KEY` (+ later `COOLIFY_*`) | `APP_KEY` + `DEAMON_SITE_NAME` |
| Domain service | `app` (8080, `/up`) | `app` (8080, `/up`) |

Docs: `docs/modules/deployment.md`. **First successful image build** needs Laravel `composer.json` (Dalga 1). Coolify **resource** can be created now (repo + compose path + domain).

API create for **customer** sites still targets `codron-co/deamon`, never this repo.

---

## Volume persistence (ADR-7) — paper analysis

Compose named volumes (`codron-co/deamon` `docker-compose.coolify.yml`):

| Volume | Mount |
|--------|--------|
| `deamon_storage` | `/var/www/html/storage/app/public` |
| `deamon_themes` | `/var/www/html/themes` |
| `deamon_mysql` | `/var/lib/mysql` |
| `deamon_redis` | `/data` |

Coolify typically **prefixes** compose volume names with the **application UUID** (app-scoped). Channel switch must be **PATCH `git_branch` + deploy**, never delete+recreate the application.

**DELETE trap:** `DELETE /applications/{uuid}` query `delete_volumes` defaults to **`true`**. Channel switch and retry must not call delete. Destroy/archive UI must confirm and pass `delete_volumes` explicitly.

**Live proof (Susa DEMO, 2026-08-13):** PATCH `git_branch` `alpha`→`beta` + POST `/deploy` + deployment `mc4nhqssbfcn0o1ljpi38w11` → `finished`. `GET /applications/{uuid}/storages` names **unchanged** (6 persistent volumes). App status `running:healthy`. Compose generated volume names are **app-uuid prefixed** (`{uuid}_deamon-storage|themes|mysql|redis`). Two older hyphenated names (`{uuid}-deamon-themes`, `{uuid}-deamon-public-storage`) also remained — leftover, not wiped.

If a future test shows volumes recreated: Hybrid + manual checklist; do **not** “fix” with shared MySQL/Redis.

---

## Webhooks (Task 6 note)

OpenAPI v4.x dump used for this spike has **no** “register Coolify→Plane webhook URL” application endpoint. Deploy visibility v1 polls `GET /deployments/{uuid}` (15–30s) and accepts `POST /webhooks/coolify`. Coolify **Notifications → Webhook** POSTs are **unsigned** today. Plane assumes HMAC-SHA256 on the raw body with header `X-Coolify-Signature` (also `X-Hub-Signature-256` / `X-Signature`) and secret `coolify_settings.webhook_secret` or `COOLIFY_WEBHOOK_SECRET`. Details: [coolify-webhooks.md](../modules/coolify-webhooks.md).

---

## Hybrid manual steps (draft — activate only if live API fails)

Use only for gaps proven on the **staging** instance:

1. Create compose app in Coolify UI (Git + Docker Compose + `docker-compose.coolify.yml`) if create-from-git API rejects private repo without GH App UUID.
2. Bind domain in Coolify UI if `docker_compose_domains` 409/400.
3. Configure outbound deploy webhook in Coolify UI if no API.
4. Always: “Coolify’de aç” deep link ` {base}/project/{project}/environment/{env}/application/{uuid} ` (exact path confirm on instance).

Do **not** add shared DB/Redis or Mailcow to this list.

---

## §18 decision locks (Dalga 0 defaults applied)

| # | Decision | Lock |
|---|----------|------|
| 1 | Coolify project | **Tek project** + tag/slug (`GET /applications?tag=`) |
| 2 | Theme org | Config `GITHUB_ORG=deamon-themes`; existing `codron-co/deamon-theme-*` **migration is a separate job** (not this build) |
| 3 | Agent network | **Public HTTPS** + HMAC + optional IP allowlist (v1) |
| 4 | Channel rollback | **Manual** (v1); auto rollback v1.1 |
| 5 | Theme auto-update | **Default off** (opt-in) |
| 6 | Agent secret inject | Import sonrası **bulk Coolify env patch + redeploy** job/runbook |

Out of scope (not asked, not built): Mailcow, customer self-serve, ZIP themes in Plane, shared MySQL/Redis.

---

## Live proof (`tools/coolify-spike/spike.ps1`)

Script: `tools/coolify-spike/spike.ps1` (read-only default; `-Mutate` staging only). Windows PowerShell 5: ASCII-only comments in the script (UTF-8 em-dash broke parse).

**Defaults (confirmed live):** project **Deamon** `z8ocg8k04ww8osssccc088c0`; server **localhost** `no48ksggg0k8sk4o4w08gks8`. Coolify environment on Susa: `sns276euzsz2fprqg3xgfz17` (folder name `alpha` — independent of git channel).

**Staging target:** name **Susa**, uuid `crxguq6nodorlzy88wf9x305`, repo `codron-co/deamon`, `build_pack=dockercompose`, `docker_compose_location=/docker-compose.coolify.yml`. List `fqdn` is null; host is `https://susa.demo.codron.co` via `docker_compose_domains`. Matches the earlier uuid; used live match.

**Do not mutate:** Deamon Plane `d6ovbjzxgpao23faam3vrcve` (left `alpha`); production-looking `main`/`dockerfile` customer domains (izyem.com, basutsilo.com, etc.).

| Check | Result | Evidence (redacted) |
|-------|--------|---------------------|
| Token list applications | **ok** | `GET /applications` → 43 apps; sample fields `uuid`, `name`, `git_branch`, `build_pack`, `fqdn` |
| List servers / projects | **ok** | 1 server `localhost` `no48ksggg0k8sk4o4w08gks8`; 4 projects including Deamon `z8ocg8k04ww8osssccc088c0` |
| GET staging app shape | **ok** | Susa `crxguq6nodorlzy88wf9x305`; before mutate `git_branch=alpha`; compose pack + `/docker-compose.coolify.yml` |
| PATCH branch + POST deploy + GET deployment status | **ok** | PATCH `git_branch=beta` 200; POST `/deploy?uuid=` queued `mc4nhqssbfcn0o1ljpi38w11`; poll `in_progress` → `finished` (~3 min); commit `791af01e32708ac6bdd2162a298b20f59476f4da`; app left on **beta**, `running:healthy` |
| Domain PATCH `docker_compose_domains` / conflict | **OpenAPI-proven + skip live probe** | `SPIKE_PROBE_DOMAIN` unset — did not bind a new domain on Susa. Live GET already shows compose domains JSON string (see adapter map). |
| Storages names stable across redeploy | **ok** | Before = after: `{uuid}-deamon-themes`, `{uuid}-deamon-public-storage`, `{uuid}_deamon-storage`, `{uuid}_deamon-themes`, `{uuid}_deamon-mysql`, `{uuid}_deamon-redis`. Added/removed: none. `/up` HTTP 200, `/` HTTP 200. |
| Create git+compose (optional) | **skipped** | No live `POST /applications/private-github-app` (would create a new resource). OpenAPI map unchanged. GH App uuid still optional in spike `.env`. |
| Env list | **ok (keys only)** | Keys include `APP_KEY`, `DEAMON_SITE_NAME`, `SERVICE_URL_APP`, `SERVICE_FQDN_APP` (values never logged). Extra keys exist on this demo (DB/DeskRon/mobile) — Plane customer contract remains **only** `APP_KEY` + `DEAMON_SITE_NAME` (+ Coolify `SERVICE_*`). |

`GET /deployments/applications/{uuid}` returns a large payload (includes logs). Adapter must not persist full logs; use `GET /deployments/{deployment_uuid}` for poll (`status`, `commit`).

---

## Unblocked

Live Go recorded 2026-08-13. Orchestrator may start Dalga 1 Laravel scaffold. Do **not** start it from this spike. Token stays in gitignored `tools/coolify-spike/.env`.

---

## CMS / Mailcow

- CMS agent (Task 8/11): **not this repo**.  
- Mailcow: **out of scope** (ADR-9).
