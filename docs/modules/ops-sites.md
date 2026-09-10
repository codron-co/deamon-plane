# Ops Sites (desired state)

Draft CRUD for Coolify-hosted Deamon sites. Create/edit still write desired state only. **Provision** (`POST /sites/{site}/provision`) is the Coolify orchestration path (Task 4). **Channel switch** (`POST /sites/{site}/channel`) is Task 5.

## Routes

| Method | Path | Name | Who |
|--------|------|------|-----|
| GET | `/sites` | `ops.sites` | All ops roles |
| GET | `/sites/create` | `ops.sites.create` | operator, super_admin |
| POST | `/sites` | `ops.sites.store` | operator, super_admin |
| GET | `/sites/{site}` | `ops.sites.show` | All ops roles |
| GET | `/sites/{site}/deployments/{deployment}` | `ops.sites.deployments.show` | All ops roles |
| GET | `/sites/{site}/edit` | `ops.sites.edit` | All ops roles (viewer read-only) |
| PUT | `/sites/{site}` | `ops.sites.update` | operator, super_admin |
| POST | `/sites/{site}/provision` | `ops.sites.provision` | operator, super_admin; draft or error only |
| POST | `/sites/{site}/cloudflare/zone` | `ops.sites.cloudflare.zone` | operator, super_admin; create/refresh Free zone + return NS |
| POST | `/sites/{site}/cloudflare/dns` | `ops.sites.cloudflare.dns` | operator, super_admin; confirm registrar NS / tear down temp host |
| POST | `/sites/{site}/domains` | `ops.sites.domains.store` | operator, super_admin; add extra host + www on the same apex |
| POST | `/sites/{site}/channel` | `ops.sites.channel` | operator, super_admin; active or error with `coolify_app_uuid`; blocked while `deploying` |
| POST | `/sites/{site}/health` | `ops.sites.health` | operator, super_admin; on-demand agent poll |
| POST | `/sites/{site}/deploy` | `ops.sites.deploy` | operator, super_admin; Coolify `POST /deploy?force=true` (current pin or HEAD) |
| POST | `/sites/{site}/pin` | `ops.sites.pin` | operator, super_admin; pin SHA + auto-deploy off + deploy |
| POST | `/sites/{site}/follow-head` | `ops.sites.follow-head` | operator, super_admin; `git_commit_sha: HEAD` + auto-deploy on + deploy |
| POST | `/sites/bulk/channel` | `ops.sites.bulk.channel` | operator, super_admin; branch + APP_ENV for selected or `all=1` |
| GET | `/sites/bulk/channel` | `ops.sites.bulk.channel.get` | **Does not switch.** 302 to list |
| POST | `/sites/bulk/sync` | `ops.sites.bulk.sync` | operator, super_admin; Coolify sync for selected ids or `all=1` |
| GET | `/sites/bulk/sync` | `ops.sites.bulk.sync.get` | **Does not sync.** 302 to list |
| POST | `/sites/bulk/deploy` | `ops.sites.bulk.deploy` | operator, super_admin; force redeploy selected or `all=1` |
| POST | `/sites/bulk/follow-head` | `ops.sites.bulk.follow-head` | operator, super_admin; follow HEAD on selected or `all=1` |
| POST | `/sites/bulk/pin` | `ops.sites.bulk.pin` | operator, super_admin; pin `ref` on selected or `all=1` |
| POST | `/sites/bulk/live-sync` | `ops.sites.live-sync` | operator, super_admin; GET each public homepage |
| GET | `/sites/bulk/live-sync` | `ops.sites.live-sync.get` | **Does not probe.** 302 to list |
| POST | `/sites/{site}/sync` | `ops.sites.sync` | operator, super_admin; GET Coolify app + last 25 deployments |
| GET | `/sites/{site}/sync` | `ops.sites.sync.get` | **Does not sync.** 302 to show |
| POST | `/sites/{site}/live-sync` | `ops.sites.live-sync.one` | operator, super_admin; GET the site homepage |
| GET | `/sites/{site}/live-sync` | `ops.sites.live-sync.one.get` | **Does not probe.** 302 to show |
| POST | `/sites/{site}/activate` | `ops.sites.activate` | operator, super_admin; Coolify start + status `active` |
| POST | `/sites/{site}/deactivate` | `ops.sites.deactivate` | operator, super_admin; Coolify stop + status `archived` |
| POST | `/sites/bulk/purge` | `ops.sites.bulk.purge` | operator, super_admin; hard-delete selected sites |
| POST | `/sites/{site}/agent-secret` | `ops.sites.agent-secret` | operator, super_admin; Coolify env inject |
| DELETE | `/sites/{site}/purge` | `ops.sites.purge` | operator, super_admin; Coolify DELETE `delete_volumes=true` then `forceDelete` |
| DELETE | `/sites/{site}` | `ops.sites.destroy` | operator, super_admin; **soft delete**. Coolify is not contacted |

Coolify connections live under `/coolify` (`ops.coolify.*`) — left nav **Coolify**. See [coolify-client.md](coolify-client.md).

Routes live in `routes/ops/sites.php` (required from `routes/web.php`).

## Background jobs + no full-page POST

Authenticated mutating forms in the ops shell submit over `fetch` (`Accept: application/json`). HTML POST still **redirects** (existing tests). JSON callers get `{ ok, message, type, redirect }` instead of following the 302 — `ConvertOpsAjaxRedirect` rewrites flash redirects and leaves existing `JsonResponse` (appearance/locale, site Cloudflare zone) alone. Navigate only when `redirect` pathname differs (create/destroy). Site Cloudflare zone create returns `{ ok, nameservers, zone_id, zone_status }` with no `redirect` so the detail page can show copyable NS without a reload.

Slow syncs and list bulk work queue `ops_background_jobs` and return `{ ok, job }` immediately. `ProcessOpsBackgroundJob` runs `afterResponse()` (`dispatchSync` after the HTTP response) so Plane does not need a queue worker. Job types: `sites.live_sync`, `sites.coolify_sync`, `coolify.inventory_sync`, `themes.catalog_sync`, `sites.bulk_channel`, `sites.bulk_compose`, `sites.bulk_auto_deploy`, `sites.bulk_deploy`, `sites.bulk_follow_head`, `sites.bulk_pin`. Status is `GET /jobs` + `GET /jobs/{job}` (`ops.jobs`, `ops.jobs.show`) — writer only, own jobs. The bottom-right widget (`data-ops-jobs`, `ops-jobs.js`) polls ~1.5s and applies Live column/favicon from `result.sites` without reload. Logout and `data-ops-native` / `data-pref-form` stay native.

## Fields

Create/edit desired state: `slug`, `name`, `domain` (`sites.primary_domain` + primary `site_domains` row), optional `aliases[]` on the **same registrable apex**, automatic **www** siblings (`www.{host}` written to `site_domains`, Cloudflare, and Coolify `app` as a comma-separated `https://` list). Coolify generate-domains are not listed. Optional **Cloudflare account** (`sites.cloudflare_setting_id`; also changeable on the site detail Infrastructure tab before **Add to Cloudflare (Free)**), `channel` (`main` \| `beta` \| `alpha` only — no free-typed branch), Coolify **selects** (connection, active server / project / environment / Git source), optional **mail server** (`sites.mail_server_id`, Hostinger credentials; order is matched per site domain — also changeable on the site detail Infrastructure tab), optional attach of an existing `codron-co/deamon` app, `notes`. Extra hosts can also be added on site detail (`POST /sites/{site}/domains`).

Coolify UUIDs are **not** free-text on site create. Super Admin may open a collapsed, warned “Gelişmiş” paste. Compose file is never an operator field — always `/docker-compose.coolify.yml`.

**Attach existing:** dropdown of customer apps on the selected connection (`CoolifyFleetClassifier`). Sets `coolify_app_uuid` + domain + channel from `git_branch` if it is `main|beta|alpha`; otherwise `channel_needs_review` (channel stays an allowlisted pick). Does **not** `POST` a second create.

**Agent secret:** when the site has no secret, **Generate & inject secret** (`POST /sites/{site}/agent-secret`) writes `CONTROL_PLANE_AGENT_SECRET` via Coolify `updateEnvs`. When a secret already exists, the agent card shows a rotate icon (same route; new value). Encrypted on the site; never shown again. Provision also attempts inject after create (does not rotate). Runbook: [agent-secret-inject.md](../runbooks/agent-secret-inject.md).

- Status is always **draft** on create. The form cannot change status.
- `APP_KEY` / `agent_secret` are generated on **Provision**, stored encrypted, never shown in the form or audit payloads.
- Channel and slug are locked on the CRUD form once status is not `draft`. Live switches use the Channel switch panel (`ChannelSwitcher`).
- Destroy is a **soft delete** with the ops confirm modal (`data-confirm` / `PlaneConfirm.ask`). Coolify is not contacted. **Hard delete** (`DELETE /sites/{site}/purge` and list bulk Hard Delete) calls Coolify `DELETE /applications/{uuid}?delete_volumes=true` then `forceDelete()`s the Plane row. Channel switch still never DELETE. `window.confirm` is not used. Mutating site ops (provision, Coolify sync, live sync, activate/deactivate, auto-deploy, pin / follow HEAD / redeploy, channel switch, theme assign/update/sync/activate/auto-update, agent inject/rotate) use the same modal. Bulk buttons may put `data-confirm` on the submitter; `ops-confirm.js` reads the submitter and `requestSubmit(submitter)` so `formaction` is kept.

## Provision

`SiteProvisioner` + `ProvisionSiteJob` + `PollDeploymentJob`. Runbook: [provision-site.md](../runbooks/provision-site.md).

- Eligible statuses: `draft`, `error` (retry). Viewer is forbidden.
- Coolify: git + `build_pack=dockercompose` + `docker_compose_location=/docker-compose.coolify.yml`. **Every Plane deploy** (`CoolifyApplicationService::deploy`) checks Coolify env against **Settings → Coolify env defaults** for that pack (`dockercompose` or leftover `dockerfile`). Missing/placeholder `DB_PASSWORD` / `MYSQL_ROOT_PASSWORD` / `DEAMON_DEFAULT_ADMIN_PASSWORD` are generated; filled MySQL secrets are never rotated. Static keys that differ from the catalog (e.g. `APP_TIMEZONE`, compose `DB_HOST=mysql`) are corrected. Site keys (`APP_KEY`, `DEAMON_SITE_NAME`, `DEAMON_CHANNEL`, `APP_ENV`, `CONTROL_PLANE_AGENT_SECRET`) come from the site row. `SERVICE_*` / `APP_URL` are listed as skip and never written. Domain on compose service `app` only (`docker_compose_domains`). **Do not send `fqdn`.** Bind string is `https://{primary},https://www.{primary}` plus aliases. If the Cloudflare zone is `pending` / `initializing`, Plane still creates the app but binds a **temporary** `{adj}-{noun}.{wildcard}` host (usually `*.codron.co`) so the container can come up; `primary_domain` stays the customer host. **I updated DNS** (`POST /sites/{site}/cloudflare/dns`) re-reads the zone: still pending → warn and keep temp; `active` → bind operator hosts + www and delete the temp row. Operator host stays primary even if Coolify generates `{uuid}.demo.codron.co` — see [coolify-client.md](coolify-client.md). Default Coolify **environment name** is `main` (1:1 with the git channel). Leftover `production` / `prod` names canonicalize to `main` on create. Laravel `APP_ENV` on the CMS stays `production` / `staging` / `local`.
- Preflight before create POST (Turkish): selected server in live `listServers`; Git source in live sources list. 422 `errors` are shown on the provision flash and in the audit error field.
- Success → `coolify_app_uuid` + status `active`. Agent health is a separate poll (does not gate provision). Failure → `error` + audit.
- Retry reuses an existing Coolify app uuid (does not DELETE the app).

## Channel switch

`ChannelSwitcher` + `SwitchSiteChannelJob` + shared `PollDeploymentJob` (trigger `channel_switch`). Runbook: [channel-switch.md](../runbooks/channel-switch.md).

- Eligible: `active` or `error` with `coolify_app_uuid`. Status `deploying` / `provisioning` is rejected.
- Coolify: `PATCH git_branch` + `git_commit_sha: "HEAD"` (follow the new branch tip; empty SHA fails Coolify validation). Do **not** send `environment_uuid` — Coolify 4.x rejects it on application PATCH (`This field is not allowed`) and the whole branch update fails. `APP_ENV` / `DEAMON_CHANNEL` go via `/envs/bulk`, then `deploy`. Never `DELETE` the application. Volumes persist (ADR-7). Same path for site detail and list **Branch Değiştir**.
- During the switch: `desired_channel` is set and status is `deploying`. Success copies it to `channel` and clears `desired_channel`.
- Policy config `config/ops.php` → `channel_switch`: `main` → `beta`/`alpha` requires confirm; `alpha`/`beta` → `main` is a version gate. When last health has `deamon_version`, the minimum is enforced. Missing health does **not** block. Force is Super Admin only.
- Audit: `site.channel_switch_started`, `site.channel_switched`, `site.channel_switch_failed`.

## Site detail

Site detail (`GET /sites/{site}`) is the operational overview: hero (status, domain, repo branch, reported version), sticky section nav, metrics, then Deployments / Themes / Infrastructure / Danger. The topbar uses **Deploy** (Redeploy / Deploy HEAD / Open in Coolify), **Sync** (Coolify / Live Site / Health check), **Site** (homepage / admin panel), and **Settings** (Edit / Activate or Deactivate / Soft Delete / Hard Delete) dropdowns. Infrastructure auto-deploy card always exposes pin-commit, update-to-latest, redeploy, and follow HEAD. Provision stays a primary button on draft/error. **Edit** is a separate route. Explanatory copy lives in `i` hints (`ops.dashboard._hint`), not page paragraphs, kickers, or chips. Git repository sits under Technical identifiers. The next-action card appears only when there is work (error, provision, health). Overview, list, and the Themes tab show `activeThemeInstallation` when present; otherwise they show `last_health_payload.active_theme_id` as “reported by agent health” and do not claim the site has no theme. The identity mark uses `IdentityMark::letter()` (UTF-8 first character — not PHP `substr`) as the no-JS / failure fallback. JS prefers `data-favicon-src` from Live Sync, then `https://{host}/favicon.ico`, then `/apple-touch-icon.png`. Do not use a third-party icon CDN. Reference layout: [site-detail-reference.html](../prototypes/site-detail-reference.html).

## Deployments

Site detail includes a **Deployments** table (`ops/deployments/index`): last 25 rows, status chip, duration, commit, **Sync Coolify**, **Open in Coolify**. Sync (`POST /sites/{site}/sync`) GETs the Coolify application (same fill as connection inventory) and `GET /deployments/applications/{uuid}` and upserts those rows. Historical Coolify deploys that never hit a Plane webhook become visible. Sync does not change `status` or secrets. The Coolify URL is `/project/{project_uuid}/environment/{environment_uuid}/application/{app_uuid}` — environment **uuid**, not the name or git branch. Click a row (or **Show**) for `GET /sites/{site}/deployments/{deployment}` — status, channel, trigger, commit, duration, times, full Coolify error (`message` + `errors` JSON), truncated redacted logs in `<pre>`, and a copyable pasteable report. Poll (`PollDeploymentJob`) and Coolify webhook failures write this text onto the `deployments` row (`error_message` + `log_excerpt`); status `failed` alone is not enough. See [coolify-webhooks.md](coolify-webhooks.md).

## Policy

`App\Policies\SitePolicy`: viewer can `viewAny` / `view`. `create` / `update` / `delete` / `forceDelete` / `provision` / `switchChannel` / `checkHealth` require `User::canWriteOps()` (operator or super_admin). Force on the version gate is Super Admin only (enforced in `ChannelSwitcher`, not a separate Gate).

## Agent health

`SiteAgentClient` + `CheckSiteHealthJob` (schedule 5–15 min) + on-demand **Check health**. Persists `last_health_at` / `last_health_payload` summary. Sites without `agent_secret` are `needs_secret` (import does not invent secrets). Contract and header names: [agent-client.md](agent-client.md). Secret inject: [agent-secret-inject.md](../runbooks/agent-secret-inject.md).

## List

GET filters with `withQueryString`: `q` (name / slug / domain), `channel`, `status`. Search input debounces a GET submit (300 ms).

**Sync Coolify** on the list (`POST /sites/bulk/sync`, `all=1` or selected ids) is the same `CoolifySiteSync` as site detail. Sites without `coolify_app_uuid` are skipped. GET `/sites/bulk/sync` is a 302.

**Live Sync** (`POST /sites/bulk/live-sync`) GETs `https://{primary_domain}/` (timeout 8s / connect 4s, follow redirects, UA `Deamon-Plane-LiveSync/1`). Host must match `^[a-z0-9.-]+$`. Stores `last_live_http_status` (0 = connection failure), `last_live_checked_at`, and `last_live_favicon_url` from HTML `<link rel*="icon">` (absolute; skip `javascript:` / `data:` / `file:`). Does not write secrets or flip `sites.status`. The Live column shows 200 / 404 / 500 / Down / —. Viewer forbidden. GET does not probe. Tests use `Http::fake` only.

Imported sites whose Coolify `build_pack` is `dockerfile` keep a `dockerfile_build_pack` line in `notes`. The list shows a **Dockerfile (eski pack)** chip. Site detail / edit can **PATCH** the existing Coolify app to `dockercompose` + `/docker-compose.coolify.yml` (no DELETE). Compose brings its own MySQL+Redis; external Dockerfile DB data stays put; `APP_KEY` is rewritten onto service `app` only. After the pack PATCH, env sync applies the **compose** catalog: `DB_HOST=mysql`, empty `MYSQL_ROOT_PASSWORD` / `DB_PASSWORD` generated, existing `DB_PASSWORD` not copied-over-if-filled. Recreate required → abort.

List header checkbox **Select all** sends `all=1` for the current filters (every matching site, not only the page). Bulk `form-actions` show only when something is selected: **Change branch**, **Switch to Compose** (only if a Dockerfile leftover exists), **Auto-deploy on/off** (all on → off; all off → on; mixed → off), **Redeploy**, **Deploy HEAD**, **Deploy commit** (SHA from the page’s latest deployments or a typed ref; all selected sites share `codron-co/deamon`), **Hard Delete**. Confirm on each. Viewer forbidden.

## Import existing Coolify apps

Artisan `ops:import-coolify-apps` (Task 7). Default is **dry-run**; `--apply` upserts. Classification, domain parse, and status rules: [import-coolify-apps.md](../runbooks/import-coolify-apps.md).

- Customer filter: `DEAMON_GIT_REPOSITORY` / `codron-co/deamon`, excluding `deamon-plane`, `entron`, `webapp-transfer`, theme repos.
- Upsert by `coolify_app_uuid` then primary domain. Slug from host / app name on create only.
- No agent secrets. `dockerfile` is imported with a notes flag + visible list/edit warning; compose preferred.
- Duplicate domain in one import (two Coolify uuids, same host): first write wins, second **skip** — not merged.
