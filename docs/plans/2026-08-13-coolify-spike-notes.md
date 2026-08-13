# Coolify API spike notes — Deamon Plane Dalga 0

**Date:** 2026-08-13  
**Source of truth (API):** Coolify OpenAPI `v4.x` (`https://github.com/coollabsio/coolify/blob/v4.x/openapi.yaml`, servers: `{host}/api/v1`)  
**Live instance:** **not exercised** — `COOLIFY_BASE_URL` / token / staging app UUID were not in env, repo, or user profile.

This file is secret-free. Tokens, env values, and production UUIDs must never be committed.

## Verdict (this pass)

| Gate | Value |
|------|--------|
| **Go / Hybrid / No-Go** | **BLOCKED** — live proof missing (credentials + staging target) |
| OpenAPI coverage | **Sufficient on paper** for list, branch update, deploy, deployment status, compose domains, env bulk, servers/projects |
| Volume persistence (ADR-7) | **Risk noted, not live-proven** — see § Volume |
| Laravel scaffold | **Not started** (Dalga 1 blocked until Go or Hybrid) |

**Not No-Go:** required methods exist in Coolify Public API v4. Absence of a token is an ops blocker, not an API gap.

**Not Go / Hybrid yet:** Faz 0 acceptance 0.1–0.4 need a real staging call. Do not start Dalga 1 until this file’s Live proof section is filled and the orchestrator writes Go or Hybrid.

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
| `listApps(?tag)` | `GET /applications?tag=` | Array of `Application`. Filter fields: `uuid`, `name`, `git_branch`, `build_pack`, `fqdn`, `git_repository`. Tag query supports §18 “tek project + tag/slug”. |
| `getApp(uuid)` | `GET /applications/{uuid}` | Full `Application`. |
| `listServers()` | `GET /servers` | Bootstrap server picker. |
| `listProjects()` | `GET /projects` | Bootstrap project picker. |
| `createComposeApp(...)` | `POST /applications/private-github-app` **or** `POST /applications/private-deploy-key` **or** `POST /applications/public` | **Git + `build_pack=dockercompose`**. Required: `project_uuid`, `server_uuid`, `environment_name` **or** `environment_uuid`, `git_repository`, `git_branch`, `build_pack`. Private GH App also needs `github_app_uuid`; deploy-key path needs `private_key_uuid`. Set `docker_compose_location=docker-compose.coolify.yml`. Optional `instant_deploy`, `name`, `docker_compose_domains`. |
| ~~`POST /applications/dockercompose`~~ | deprecated | Docs: “use POST /services”. That path wants `docker_compose_raw` **without git** — **wrong** for Deamon customer sites. Do not use for provision. |
| `updateEnvs(uuid, pairs)` | `PATCH /applications/{uuid}/envs/bulk` body `{ "data": [ { "key", "value" } ] }` | Also `POST /applications/{uuid}/envs` create; `PATCH .../envs` update one. Customer Coolify env: **only** `APP_KEY` + `DEAMON_SITE_NAME` (+ Coolify `SERVICE_*`). |
| `listEnvs(uuid)` | `GET /applications/{uuid}/envs` | Import / rotate. Never log `value`. |
| `setDomains(uuid, fqdn)` | `PATCH /applications/{uuid}` | Compose apps: **`docker_compose_domains`: `[{ "name": "app", "domain": "https://fqdn" }]`**. Also `domains` comma-separated. Conflict → HTTP 409-style payload: `force_domain_override=true` only with Super Admin + audit. |
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

- Compose service name in `docker-compose.coolify.yml` is **`app`** (port expose 8080). Domain must attach to that service via `docker_compose_domains[].name = "app"`.
- `force_domain_override` default **false**. Conflict response includes `conflicts[]` with `domain`, `resource_name`, `resource_uuid`.
- Plane: unique `primary_domain`; surface Coolify conflict; do not silently override.

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

**Live proof still required:** after staging `alpha`↔`beta` redeploy, `GET /applications/{uuid}/storages` names unchanged + MySQL data / `/up` still healthy. Until then: **risk**, not fail.

If live test shows volumes recreated: Hybrid + manual checklist; do **not** “fix” with shared MySQL/Redis.

---

## Webhooks (Task 6 note)

OpenAPI v4.x dump used for this spike has **no** “register Coolify→Plane webhook URL” application endpoint. Deploy visibility v1 can **poll** `GET /deployments/applications/{uuid}` (15–30s) as specified in the plan. If the instance UI supports outbound webhooks, document as optional Hybrid step (“Coolify’de aç” + paste Plane URL) — confirm on live instance.

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

## Live proof (fill after `tools/coolify-spike/spike.ps1`)

Script: `tools/coolify-spike/spike.ps1` (read-only default; `-Mutate` staging only).

| Check | Result | Evidence (redacted) |
|-------|--------|---------------------|
| Token list applications | pending | |
| List servers / projects | pending | |
| GET staging app shape (`uuid`, `git_branch`, `build_pack`) | pending | |
| PATCH branch + POST deploy + GET deployment status | pending | |
| Domain PATCH `docker_compose_domains` / conflict | pending | |
| Storages names stable across redeploy | pending | |
| Create git+compose (optional) | skipped until mutate + GH App uuid | |

---

## How to unblock Go/Hybrid

1. Coolify → Keys & Tokens → API token.  
2. Pick **one staging** Docker Compose Deamon app (not a paying customer production site).  
3. `tools/coolify-spike/.env` from `.env.example`.  
4. Run `.\spike.ps1` then `.\spike.ps1 -Mutate` (staging `alpha`↔`beta`).  
5. Paste redacted `last-run.json` summary into the table above.  
6. Orchestrator sets **Go** or **Hybrid** and only then starts Dalga 1.

---

## CMS / Mailcow

- CMS agent (Task 8/11): **not this repo**.  
- Mailcow: **out of scope** (ADR-9).
