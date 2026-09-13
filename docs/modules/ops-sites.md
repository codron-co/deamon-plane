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
| POST | `/sites/{site}/publish-status` | `ops.sites.publish-status` | operator, super_admin; CMS yayın durumu via signed agent |
| POST | `/sites/bulk/publish-status` | `ops.sites.bulk.publish-status` | operator, super_admin; publish/unpublish selected or `all=1` |
| POST | `/sites/list-preferences` | `ops.sites.list-preferences` | All ops roles; own column layout |
| DELETE | `/sites/list-preferences` | `ops.sites.list-preferences.reset` | All ops roles; back to default columns + sort |
| POST | `/sites/{site}/deploy` | `ops.sites.deploy` | operator, super_admin; Coolify `POST /deploy?force=true`; rebuilds the ref the app already points at, pin untouched |
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
| POST | `/sites/bulk/agent-secret` | `ops.sites.bulk.agent-secret` | operator, super_admin; inject only where missing, never rotate |
| POST | `/sites/{site}/agent-secret` | `ops.sites.agent-secret` | operator, super_admin; Coolify env inject |
| POST | `/sites/{site}/admins` | `ops.sites.admins.store` | operator, super_admin; CMS admin create |
| POST | `/sites/{site}/admins/{remoteAdmin}/password` | `ops.sites.admins.password` | operator, super_admin; CMS password reset |
| POST | `/sites/{site}/admins/{remoteAdmin}/deactivate` | `ops.sites.admins.deactivate` | super_admin |
| POST | `/sites/{site}/admins/{remoteAdmin}/activate` | `ops.sites.admins.activate` | super_admin |
| DELETE | `/sites/{site}/admins/{remoteAdmin}` | `ops.sites.admins.destroy` | super_admin |
| DELETE | `/sites/{site}/purge` | `ops.sites.purge` | operator, super_admin; Coolify DELETE `delete_volumes=true` then `forceDelete` |
| DELETE | `/sites/{site}` | `ops.sites.destroy` | operator, super_admin; **soft delete**. Coolify is not contacted |

Coolify connections live under `/coolify` (`ops.coolify.*`) — left nav **Coolify**. See [coolify-client.md](coolify-client.md).

Routes live in `routes/ops/sites.php` (required from `routes/web.php`).

## Background jobs + no full-page POST

Authenticated mutating forms in the ops shell submit over `fetch` (`Accept: application/json`). HTML POST still **redirects** (existing tests). JSON callers get `{ ok, message, type, redirect }` instead of following the 302 — `ConvertOpsAjaxRedirect` rewrites flash redirects and leaves existing `JsonResponse` (appearance/locale, site Cloudflare zone) alone. Navigate only when `redirect` pathname differs (create/destroy). Site Cloudflare zone create returns `{ ok, nameservers, zone_id, zone_status }` with no `redirect` so the detail page can show copyable NS without a reload.

Slow syncs and list bulk work queue `ops_background_jobs` and return `{ ok, job }` immediately. `ProcessOpsBackgroundJob` runs `afterResponse()` (`dispatchSync` after the HTTP response) so Plane does not need a queue worker. Job types: `sites.live_sync`, `sites.coolify_sync`, `coolify.inventory_sync`, `themes.catalog_sync`, `sites.bulk_channel`, `sites.bulk_compose`, `sites.bulk_auto_deploy`, `sites.bulk_deploy`, `sites.bulk_follow_head`, `sites.bulk_pin`, `sites.bulk_publish_status`, `domains.bulk_bind`. Status is `GET /jobs` + `GET /jobs/{job}` (`ops.jobs`, `ops.jobs.show`) — writer only, own jobs. `DELETE /jobs/{job}` dismisses completed/failed own jobs. The bottom-right widget (`data-ops-jobs`, `ops-jobs.js`) polls ~1.5s and applies Live column/favicon from `result.sites` without reload. Coolify deployments (manual redeploy / pin / follow-head, plus provision and channel switch) appear in the same widget; **live Coolify queue** (`GET /deployments`) is merged for **Plane Deamon sites only** (matched by site name / app uuid; Plane itself and other apps are hidden). Hover actions: **X** dismisses finished rows or **cancels** queued/running Coolify deploys (`POST /deployments/{uuid}/cancel`); **play** force-starts a queued Deamon deploy (cancel queue row + `POST /applications/{uuid}/start?force=true&instant_deploy=true`). `PollDeploymentJob` keeps local `deployments.status` in sync (cancel → `cancelled` without flipping Active sites to Error for Manual/ThemeRollout). `GET /jobs` also refreshes stale open deployments from Coolify and re-kicks poll (~20s). Logout and `data-ops-native` / `data-pref-form` stay native.

A widget row says **what kind of work it is** before anything else. Every payload (`OpsBackgroundJob::toWidget`, `Deployment::toWidget`, `OpsCoolifyDeployQueue::remoteWidget`) carries `kind_label`, `subject`, `status_label` and `detail`; the title is `kind_label · subject` (`Coolify deploy · beyazlar`, `Live Sync · beyazlar`, `App hatalarını düzelt · 3 site`) and the meta line is `detail · status_label` (`manuel · kuyrukta`). `subject` comes from `payload.subject` when a job targets one thing, otherwise it is the site count. A Coolify queue row Plane did not start reads `Coolify kuyruğu` as its detail instead of inventing a Plane trigger.

### A job that only triggers Coolify never says "Bitti"

A Plane bulk job finishes when Coolify **accepts** the deploy request; the build keeps running in its own `Coolify deploy · <site>` row. So the widget has two completion vocabularies:

- Work Plane itself finishes (`sites.live_sync`, `sites.coolify_sync`, `coolify.inventory_sync`, `themes.catalog_sync`, `sites.bulk_compose`, `sites.bulk_auto_deploy`, `sites.bulk_publish_status`, `domains.bulk_bind`): counts from `ops.bulk.result*` (`1 tamam`) and status `Bitti`. `domains.bulk_bind` is here on purpose: `setDomains` is a PATCH, not a rebuild.
- Work that only enqueues a Coolify build (`sites.bulk_deploy`, `sites.bulk_follow_head`, `sites.bulk_pin`, `sites.bulk_channel`, and `sites.bulk_app_health_fix` when the fix set ends in a redeploy): counts from `ops.bulk.triggered*` (`1 deploy tetiklendi`) plus `ops.bulk.triggered_note`, and status **`Tetiklendi`** (`ops.jobs.status.triggered`).

`BulkResultSummary::formatTriggered()` writes the second vocabulary for both the widget message and the no-JS flash (`SiteCoolifyOpsController::bulkTriggerFlash`). `OpsBackgroundJob::triggersRemoteWork()` is the single source of truth for which types are trigger-only; `statusLabel()` swaps `completed` for `triggered` from it. App-health sweeps are decided by fact, not by the requested fix: the runner records `result.deploy_triggered` from the fixes the targets actually needed, and `SiteAppHealthFixer::summarize($result, $deployTriggered)` appends the Coolify note only then. Failure and skip wording is unchanged (`hata`, `atlandı (istek sınırı)`) so the rate-limit note still matches its counts; `sıra bekliyor` is a third, separate outcome — see "Bulk counting" below.

### Force start promotes a row, it does not cancel one

Force start needs the queue slot released before Coolify will instant-start, but that cancel is Plane mechanics — never the reported outcome. `OpsCoolifyDeployQueue::forceStart()` therefore keeps **one** row: it cancels the queued Coolify deployment, instant-starts the app, then repoints the same `deployments` row at the new `coolify_deployment_uuid` with `in_progress`, a fresh `started_at`, cleared `error_message`, and a `site.deploy_force_started` audit entry (old + new uuid). The widget row keeps its `dep-<id>` key, so it patches `kuyrukta` → `çalışıyor` in place and no `iptal` ghost is left behind. The endpoint also returns `message` (`ops.jobs.force_started`) which `ops-jobs.js` shows as a toast.

Failure modes stay truthful instead of English: if the instant start fails after the queue slot was dropped, the row is cancelled with `ops.jobs.force_start_aborted` ("kuyruktaki deploy iptal edildi ama Coolify yenisini başlatamadı"); if a webhook already created a row for the new uuid, that row becomes the live one and the forced row retires with `ops.jobs.force_start_replaced`. Cancelled/failed rows with no Coolify message fall back to `ops.deploy_failure.cancelled` / `ops.deploy_failure.failed` (`CoolifyDeploymentSync`), so the widget no longer prints `Coolify deployment cancelled.` next to a Turkish status.

`ops-jobs.js` **patches** rows instead of rebuilding the list. Rows are keyed by `data-job-id`; each poll updates only the title/meta/status class/progress that actually changed, inserts new rows, removes gone ones, and keeps focus. Cancel / force-start / dismiss buttons are created once per row with listeners that read the latest payload from row state, so a 1.5s poll can never swap the button out from under the pointer mid-click. Unavailable actions carry `hidden` (global `[hidden]` rule) rather than being unmounted. Queued and running rows show their actions without hover.

Widget motion is a status signal, not decoration: only a **running** row gets the animated indeterminate bar (`is-indeterminate`). A **queued** row (`kuyrukta`) renders an inert muted rail (`is-waiting`, `aria-valuenow="0"`), so a 25-item sweep shows at a glance which site Coolify is building and which are still waiting. `indeterminate` in the widget payload (`Deployment::toWidget`, `OpsCoolifyDeployQueue`) therefore means "running with unknown progress" and is `false` while queued; `ops-jobs.js` also derives it from `status` so a stale payload cannot animate a queued row.

### A queued row says where in line it is

`ops.coolify.deploy.max_concurrent_per_server` is 1, so a 23-site bulk deploy is one build and 22 waits. `kuyrukta` alone cannot tell the operator whether that means two minutes or an hour, so every waiting row carries its place in the queue: meta reads `manuel · kuyrukta · sırada 4 / 22` and the widget header adds `1 derleniyor · 22 kuyrukta`.

`OpsCoolifyDeployQueue::queueStanding()` is the single read behind both. It ranks unfinished `deployments` per **Coolify host** — connection id, else server uuid, else the site alone, the same grouping `CoolifyDeployGate` counts against, because a queue the gate does not share is not a queue the operator waits behind — ordered by `created_at` then `id`. Depth is the whole host queue, deliberately **not** the widget's own 30-row page, so a 200-site sweep does not report `sırada 4 / 30`. A running build is counted in `running` but never given a position; it already has its elapsed timer.

`applyQueueStanding()` stamps `queue_position`, `queue_depth` and a translated `queue_label` onto a widget row (only when `status` is `queued` and the row has a `deployment_id` — a Coolify queue row Plane never started has no rank). `queueSummaryLabel()` builds the header line and returns `null` when nothing is waiting. Both come from `ops.jobs.queue_position` / `queue_building` / `queue_waiting`; `ops-jobs.js` only prints what the payload already translated. `GET /jobs` and every cancel / force-start response carry `queue { running, queued, label }`, so promoting or cancelling a row re-ranks everything behind it without a page reload. Tests: `DeployQueueStandingTest`.

### A poll is a Coolify read, so long waits cost less

Each `/jobs` poll runs `OpsCoolifyDeployQueue::widgetRows()`, which reads `GET /deployments` **per Coolify connection**. At a flat 1.5s a forgotten tab on a ten-minute build was ~400 Coolify requests and two tabs doubled it — the pressure that produced `Too Many Attempts.` ([coolify-throttle-cure-design](../superpowers/specs/2026-09-11-coolify-throttle-cure-design.md)). Three rules now hold it down:

- **A hidden tab polls not at all.** `PlaneOpsContracts.pageIsHidden` / `shouldSchedulePoll` gate `schedulePoll` and `visibilitychange`; becoming visible again spends exactly one catch-up read and then resumes at the interval the wait had already earned. Duck-typed `{ hidden: true }` — no jsdom.
- **The interval grows while nothing changes.** `ops-jobs.js` chains `setTimeout` (never `setInterval`, whose gap cannot change) through the tiers in `ops.jobs.poll` — `fast_ms` 1500 → `slow_ms` 5000 → `max_ms` 10000 — stepping down one tier per `slow_after` (8) polls whose state signature is unchanged. The signature is row id + status + progress + `queue_position`, so a queue advancing one place counts as news and snaps back to the fast tier, as does any operator action through `PlaneJobs.track()`.
- **N pollers cost one Coolify read.** `runningDeployments()` caches the raw queue payload per connection for `ops.coolify.deploy.queue_cache_seconds` (5), so concurrent tabs and concurrent operators share one read per window. Failures are **not** cached — an unreachable connection must be retried, and `CoolifyRateGuard` owns the cooldown. Cancel and force start `Cache::forget` the connection's entry, so the operator who just acted is never handed back the queue they changed.

Cancel / force-start / dismiss stay clickable throughout: rows are still patched in place, and the slower tiers only lengthen the gap between reads. Tests: `JobsPollPressureTest` (server cache + `data-poll-*`). Poll signature / backoff and row-identity helpers: `node --test tests/js/*.js` — [ADR-11](../decisions/adr-11-dom-test-harness.md) (not Playwright).

### Failed-only triage

A mixed two-hour widget hides the one failure behind twenty completed rows. **Sadece hatalılar** is a header toggle that polls `GET /jobs?failed=1`: only this operator's `status=failed` jobs and deploys in the same two-hour window. `failed=yes` / junk widen to the mixed list, like the Sites filters. The live Coolify queue is **not** merged on that lane — those rows are running work, and `widgetRows()` is a `GET /deployments` per connection, the pressure P0-4 exists to cut. `queueStanding()` (SQL) still fills the header line.

`ops-jobs.js` persists the toggle in `sessionStorage` (`planeOpsJobsFailedOnly`) and builds the poll URL with `PlaneOpsContracts.jobsIndexUrl`. Client-side `isFailedStatus` hides non-failed rows already on screen until the next poll arrives. An empty failed-only list **keeps the panel** so the toggle stays reachable. Tests: `JobsFailedFilterTest`; `node --test` for `isFailedStatus` / `jobsIndexUrl`.

## Fields

Create/edit desired state: `slug`, `name`, `domain` (`sites.primary_domain` + primary `site_domains` row), optional `aliases[]` on the **same registrable apex**, automatic **www** siblings (`www.{host}` written to `site_domains`, Cloudflare, and Coolify `app` as a comma-separated `https://` list). Coolify generate-domains are not listed. Optional **Cloudflare account** (`sites.cloudflare_setting_id`; also changeable on the site detail Infrastructure tab before **Add to Cloudflare (Free)**), `channel` (`main` \| `beta` \| `alpha` only — no free-typed branch), Coolify **selects** (connection, active server / project / environment / Git source), optional **mail server** (`sites.mail_server_id`, Hostinger credentials; mailbox domains are selected on the site detail Infrastructure tab), optional attach of an existing `codron-co/deamon` app, `notes`. Extra hosts can also be added on site detail (`POST /sites/{site}/domains`).

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
- Coolify: git + `build_pack=dockercompose` + `docker_compose_location=/docker-compose.coolify.yml`. **Every Plane deploy** (`CoolifyApplicationService::deploy`) checks Coolify env against **Settings → Coolify env defaults** for that pack (`dockercompose` or leftover `dockerfile`). Missing/placeholder `DB_PASSWORD` / `MYSQL_ROOT_PASSWORD` / `DEAMON_DEFAULT_ADMIN_PASSWORD` are generated; filled MySQL secrets are never rotated. Static keys that differ from the catalog (e.g. `APP_TIMEZONE`, compose `DB_HOST=mysql`) are corrected. Site keys (`APP_KEY`, `DEAMON_SITE_NAME`, `DEAMON_CHANNEL`, `APP_ENV`, `CONTROL_PLANE_AGENT_SECRET`) come from the site row. `SERVICE_*` / `APP_URL` are listed as skip and never written. Domain on compose service `app` only (`docker_compose_domains`) — **after** the first deploy finishes (Coolify needs `docker_compose_raw` from git first; create/PATCH domains before that fails). **Do not send `fqdn`.** Bind string is `https://{primary},https://www.{primary}` plus aliases. If the Cloudflare zone is `pending` / `initializing`, Plane still creates the app but binds a **temporary** `{adj}-{noun}.{wildcard}` host (usually `*.codron.co`) so the container can come up; `primary_domain` stays the customer host. **I updated DNS** (`POST /sites/{site}/cloudflare/dns`) re-reads the zone: still pending → warn and keep temp; `active` → bind operator hosts + www and delete the temp row. Operator host stays primary even if Coolify generates `{uuid}.demo.codron.co` — see [coolify-client.md](coolify-client.md). Default Coolify **environment name** is `main` (1:1 with the git channel). Leftover `production` / `prod` names canonicalize to `main` on create. Laravel `APP_ENV` on the CMS stays `production` / `staging` / `local`.
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

Site detail (`GET /sites/{site}`) is the operational overview: hero (status, domain, repo branch, reported version, **Copy site ID** / **Copy app UUID** / **Copy Coolify link** when a deep link exists), sticky section nav, metrics, then Deployments / Themes / **Admins** / Infrastructure / Danger. The **Admins** tab (operator+) lists CMS admins via the signed agent; Super Admin can deactivate/delete. See [site-admins.md](site-admins.md). The topbar uses **Deploy** (Redeploy / Deploy HEAD / Open in Coolify plus copy for the deep link and app UUID), **Sync** (Coolify / Live Site / Health check), **Site** (homepage / admin panel), and **Settings** (Edit / Activate or Deactivate / Soft Delete / Hard Delete) dropdowns. Infrastructure auto-deploy card always exposes pin-commit, update-to-latest, redeploy, and follow HEAD. Provision stays a primary button on draft/error. **Edit** is a separate route. Explanatory copy lives in `i` hints (`ops.dashboard._hint`), not page paragraphs, kickers, or chips. Git repository sits under Technical identifiers with the Plane site ULID and Coolify app / server / project / environment UUIDs. Each filled identifier is a `data-copy-value` button (`setupCopyButtons` in `ops-ui.js`) so an operator can paste into Coolify, Geçmiş `?site=`, or a ticket without selecting the `<code>` by hand. The Coolify UI URL (project / environment / application uuids) copies the same way. Missing Coolify UUIDs stay `—` and have no copy control. Tests: `SiteDetailTest`. The next-action card appears only when there is work (error, provision, health). Overview, list, and the Themes tab show `activeThemeInstallation` when present; otherwise they show `last_health_payload.active_theme_id` as “reported by agent health” and do not claim the site has no theme. The identity mark uses `IdentityMark::letter()` (UTF-8 first character — not PHP `substr`) as the no-JS / failure fallback. JS prefers `data-favicon-src` from Live Sync, then `https://{host}/favicon.ico`, then `/apple-touch-icon.png`. Do not use a third-party icon CDN. Reference layout: [site-detail-reference.html](../prototypes/site-detail-reference.html).

## Deployments

Site detail includes a **Deployments** table (`ops/deployments/index`): last 25 rows, status chip, duration, commit, **Sync Coolify**, **Open in Coolify**. Sync (`POST /sites/{site}/sync`) GETs the Coolify application (same fill as connection inventory) and `GET /deployments/applications/{uuid}` and upserts those rows. Historical Coolify deploys that never hit a Plane webhook become visible. Sync does not change `status` or secrets. The Coolify URL is `/project/{project_uuid}/environment/{environment_uuid}/application/{app_uuid}` — environment **uuid**, not the name or git branch. Click a row (or **Show**) for `GET /sites/{site}/deployments/{deployment}` — status, channel, trigger, commit, duration, times, full Coolify error (`message` + `errors` JSON), truncated redacted logs in `<pre>`, and a copyable pasteable report. Poll (`PollDeploymentJob`) and Coolify webhook failures write this text onto the `deployments` row (`error_message` + `log_excerpt`); status `failed` alone is not enough. See [coolify-webhooks.md](coolify-webhooks.md).

## Policy

`App\Policies\SitePolicy`: viewer can `viewAny` / `view`. `create` / `update` / `delete` / `forceDelete` / `provision` / `switchChannel` / `checkHealth` / `manageAdmins` require `User::canWriteOps()` (operator or super_admin). `toggleAdminActive` / `destroyAdmin` are Super Admin only. Force on the version gate is Super Admin only (enforced in `ChannelSwitcher`, not a separate Gate).

## Agent health

`SiteAgentClient` + `CheckSiteHealthJob` (schedule 5–15 min) + on-demand **Check health**. Persists `last_health_at` / `last_health_payload` summary. Sites without `agent_secret` are `needs_secret` (import does not invent secrets). Contract and header names: [agent-client.md](agent-client.md). Secret inject: [agent-secret-inject.md](../runbooks/agent-secret-inject.md).

Health also **mirrors** the CMS publish state onto `sites.cms_site_status` / `cms_site_status_at` — see [Yayın durumu](#yayın-durumu-cms-publish-state).

## Yayın durumu (CMS publish state)

Publish is **CMS-owned**. The CMS `sites.status` column (`draft` \| `published`) is what drives its storefront maintenance page; Plane's own `sites.status` is the **Coolify lifecycle** (`draft`/`provisioning`/`active`/…) and is a different thing. The list therefore has two chips: **Yayın** (publish) and **Durum** (lifecycle). Design note: [2026-09-12 spec](../superpowers/specs/2026-09-12-site-publish-state-and-list-preferences-design.md).

Plane **mirrors** the CMS value into real columns so the list can sort and filter on it:

- `sites.cms_site_status` (`draft` \| `published` \| `null`), `sites.cms_site_status_at` (when the CMS last confirmed it).
- `null` renders as **Bilinmiyor** — it is not the same as `draft`. It means no CMS has reported yet (never polled, no secret, or CMS older than 1.2.16).
- `SiteHealthChecker` writes the mirror from the health payload's `site_status`. A poll that **could not reach** the CMS leaves a known value alone; a poll that reached a CMS which reported nothing clears it rather than keeping a stale claim.

Writes go through the signed agent (`SitePublishStateUpdater` → `SiteAgentClient::setSiteStatus` → CMS `POST /internal/control/v1/site/status`). Rules:

- The mirror stores **what the CMS echoed**, never what Plane asked for. A failed call changes nothing and writes no audit row.
- Sites without an agent secret or a resolvable base URL cannot be toggled (`Site::canChangePublishStatus()`); the detail card says why and bulk reports them as such.
- Audit: `site.published` / `site.unpublished`, written only on an actual transition.
- A CMS that answers 404 is too old for the endpoint and says so in Turkish instead of leaking an HTTP code.
- Site detail has a **Yayın durumu** card offering only the opposite action, with a confirm (danger when unpublishing). The list has **Yayına al** / **Yayından kaldır** bulk buttons (`sites.bulk_publish_status` when queued over JSON).

## App health

`SiteAppHealthInspector` scores Coolify pack / compose env / domains / last deploy / agent — **only once the site is running** (`active` / `deploying` / `stopped` / `archived`). Draft and provisioning show no App-health issues. Failed provision (`error`) only surfaces `missing_app` when there is no Coolify uuid; a failed create deploy or a premature agent poll does **not** become “Tekrar deploy” / “Agent kontrol”. Domain bind (`syncCoolifyDomains`, Sync auto-rebind) waits for `Site::canBindCoolifyDomains()` (active/deploying/stopped + uuid). List **App** column: **Healthy** or **N issues**. Hover is the issue list; click copies it. Row **Fix App issues** menu runs one fix or all for that site. Header **Fix App issues** runs a category (or all) across sites that need it via `sites.bulk_app_health_fix`. Site detail shows the same issues with in-page POST fixes (`sync_env`, `migrate_compose`, `bind_domains`, `inject_secret`, `redeploy`, `check_health`, `fix=all`) — `ops-async.js` keeps the page. Coolify Sync imports app hosts into `site_domains` and auto-rebinds Plane hosts missing on Coolify (`ops.coolify.auto_rebind_domains`) when the site can bind. Fleet **Domains** (`/domains`) lists hosts with unbound filter, per-row bind, and a bulk **Coolify’e bağla** that adopts the Sites `all=1` summary contract (`domains.bulk_*`). Live Coolify inspect is cached on `last_app_health_*` and refreshed by `InspectSiteAppHealthJob` (same schedule as agent health) or **Check Coolify**. Secrets are never stored or shown. Manual / pin / HEAD deploys write a local `deployments` row so the jobs widget can follow Coolify success/failure.

### Fleet domains: empty states and bulk bind

`/domains` now distinguishes an empty registry from a filtered miss, reusing `ops.partials.filter-chips`. Nothing in the table → **Domain ekle** (inline create) plus **Coolify’dan içe aktar** (`/coolify`); a viewer sees `ops.viewer_readonly` instead of the form. Filters excluded everything → `domains.empty.filtered_title`, the registry size, one removable chip per active filter (`q`, `unbound`), and **Filtreleri temizle** as the primary action. Tests: `DomainListEmptyStatesTest`.

**Bulk bind.** The header checkbox posts `all=1`, which `DomainController::bindBulk` resolves through `SiteDomain::matchingListFilters($q, $unbound)` — the same two filters the toolbar posts. The bar publishes `$domains->total()` and interpolates it into the confirm. With **Yalnızca bağlı olmayan** on, that confirm names unbound hosts (`:count bağlı olmayan domain…`). With the filter off, the confirm and the bar hint say already-bound hosts are skipped and how many in the current `q` are still unbound — the sweep already treated a bound host as a no-op, but the overlay used to read as if every selected row would be written. The sweep (`DomainBindSweep`, job `domains.bulk_bind`) talks to Coolify **once per site**: `SiteLanding::syncCoolifyDomains` writes the whole host list, so two aliases of one site are one PATCH. Already-bound selected hosts are a no-op and never touch Coolify. A 429 is `atlandı (istek sınırı)`, not `hata`; a host with no Coolify app is `unready`, not a throttle skip. `setDomains` is a PATCH, not a rebuild, so the widget reports **Bitti**, not **Tetiklendi**. Tests: `DomainBulkBindTest`.

**Bulk leftover clear.** The same bar has **Kayıttan sil** (`POST /domains/bulk-clear`). It is not a background job. Confirm is danger and names the P0-1 count. `DomainClearSweep` deletes only leftover unbound aliases (`verified_at` null, not primary, not temporary). Primaries stay even when the unbound filter lists them. Bound and temporary hosts are skipped, not errors. No Coolify HTTP. Fetch answers `{ ok, message, refresh_list }` so the region re-renders; a selection of only protected hosts is `422` / `domains.bulk.empty_clear`. Tests: `DomainBulkClearTest`.

## List

GET filters with `withQueryString`: `q` (name / slug / primary domain / any `site_domains` host / exact `coolify_app_uuid` — see [search reach](#search-reaches-aliases-and-coolify-uuids)), `channel`, `status` (lifecycle), `publish` (`published` \| `draft` \| `unknown`), `deploy` (`failed` — see [drill down from the failed-deploy card](#drill-down-from-the-failed-deploy-card)), `agent` (`missing` \| `unverified` \| `ok` — see [agent secret across the fleet](#agent-secret-across-the-fleet)), `health` (`unhealthy` — see [drill down from the unhealthy card](#drill-down-from-the-unhealthy-card)), `app` (`issues` — same section). Search input debounces a GET submit (300 ms). Bulk forms carry the active filters as `filter_q` / `filter_channel` / `filter_status` / `filter_publish` / `filter_deploy` / `filter_agent` / `filter_pack` / `filter_health` / `filter_app` hidden inputs so `all=1` re-resolves the same set via `Site::matchingListFilters`. An unrecognised filter value is dropped, never applied as a literal — a typo widens the list instead of emptying it.

Search, filters, sort, pagination and the column layout update the table **in place** — see [async list regions](ops-list-async.md). List `fetch` / `replaceState` helpers (`isSameList`, `shouldPatchListRegion`, `listHistoryMode`): `node --test tests/js/*.js` — [ADR-11](../decisions/adr-11-dom-test-harness.md).

An empty table names its own cause. Nothing created yet → `sites.empty.title` plus **Yeni site** (or `ops.viewer_readonly` when the operator cannot create). Filters excluded everything → `sites.empty.filtered_title`, the fleet size (`sites.empty.filtered_hint`), one removable chip per active filter (`ops.partials.filter-chips`, each a plain GET link that drops only itself), and **Filtreleri temizle** as the primary action. `SiteController::activeListFilters()` builds the chips; the toolbar clear button is a `btn-secondary` so the way out of a filtered miss is never a ghost link. Tests: `SiteListEmptyStatesTest`.

### Drill down from the failed-deploy card

The fleet **Başarısız dağıtımlar · son 24 sa** card and the attention list's `+N site daha` line both link to `/sites?deploy=failed`. That is only safe because the two sides share one predicate: `Deployment::scopeFailedInWindow()` (status `failed`, `COALESCE(finished_at, started_at, created_at)` inside `ops.fleet.failed_deploy_window_hours`). `FleetDashboardKpis` counts with it and `Site::matchingListFilters()` filters with it, so the list can never resolve a different set from the number that was clicked. The filter is a `whereHas`, not a join, so a site that failed five times is one row — the same per-site counting the card does. The select option and the chip both name the window (`Dağıtımı başarısız · son 24 sa`); "failed" with no timeframe is exactly the ambiguity the windowed card removed.

### Drill down from the unhealthy card

The fleet **Sağlıksız** card and the attention list's `+N site daha` line both link to `/sites?health=unhealthy`. That is only safe because both sides share `Site::scopeUnhealthy()`: persisted `health_unhealthy`, or `status=error`, or a stored agent-fail payload, or the stale window (`ops.agent.stale_after_minutes`). `FleetDashboardKpis::unhealthySiteCount()` counts with it and `Site::matchingListFilters(..., health:)` filters with it. Stale is time-based, so the column alone is not enough — the SQL still evaluates the window. `needs_secret` / `unknown` stay out, same as `SiteHealthEvaluator`.

`/sites?app=issues` is `sites.app_has_issues` — sites whose last inspect / health / local write left at least one needed fix (`SiteAppHealthFixer::neededFixes()`). The header **Fix App issues** menu links **Sorunlu siteleri gör** there. Category counts in that menu stay the 60s cached scan (P0-2); the filter is not that scan.

Verdicts are stamped by `SiteFilterVerdict` on the same `Site` save that writes `last_health_*` or `last_app_health_*` (health poll, `InspectSiteAppHealthJob`, compose-notes, secret, uuid). There is no fleet walk on list or dashboard render. [ADR-10](../decisions/adr-10-health-app-filter-verdict.md). Tests: `HealthAppFilterTest`, `FailedDeployFilterTest`.

### Agent secret across the fleet

The fleet **Agent gizli anahtarı** card (and the attention list under it) splits every site into one of three SQL buckets, never a PHP scan:

- `missing` (`yok`) — `agent_secret_encrypted` is empty. Import never invents a secret.
- `unverified` (`doğrulanmamış`) — Plane has a secret, but the last health payload is not a CMS **200** (`last_health_payload.http_status = 200` or `status = ok`). A secret nobody has accepted is not `tamam`. A later timeout overwrites that payload, so a once-verified site flips back to `doğrulanmamış` until the next 200. There is no separate `agent_secret_verified_at` column.
- `ok` (`tamam`) — stored secret and a 200 (or `status=ok`) from the signed health path.

`Site::scopeMissingAgentSecret` / `scopeUnverifiedAgentSecret` / `scopeVerifiedAgentSecret` are the single definition. `FleetDashboardKpis::agentSecretCounts()` and `Site::matchingListFilters(..., agent:)` both call them, so the number on the card and `/sites?agent=missing` cannot drift. The KPI numbers themselves are the drill-down (yok / doğrulanmamış); `tamam` is a count, not a problem list. The attention list is missing + unverified only, capped like the other cards.

**Bulk `Gizli anahtar üret ve gönder`** (`POST /sites/bulk/agent-secret`, job `sites.bulk_inject_agent_secret`) generates and writes `CONTROL_PLANE_AGENT_SECRET` only when the site has none. A selected site that already has a secret is `atlandı` — the sweep never passes `rotate: true`. The job is Plane-finished work (Coolify `updateEnvs` PATCH, not a deploy), so the widget says **Bitti**, not **Tetiklendi**. The generated value is never in the response, the widget, the audit `after` payload, or the flash. Fleet writers also get the same action on the attention card, scoped to `all=1` + `filter_agent=missing`. Tests: `AgentSecretFleetTest`.

### Search reaches aliases and Coolify uuids

The search box is one field with four legs, all inside a single `where` group in
`Site::scopeMatchingListFilters`: `name`, `slug` and `primary_domain` as `like`, every host in
`site_domains` (alias, `www`, temporary preview) through `whereHas('domains')`, and an **exact**
match on `coolify_app_uuid`.

- The host leg is an **exists subquery, not a join**: a site with three matching aliases is still
  one row. A join would have multiplied the row and the pagination total with it.
- The uuid leg is exact on purpose. A prefix match would pull in every app that shares a Coolify
  id fragment and read like a broken search; the operator pasting a uuid has the whole thing.
- `addcslashes($search, '%_\\')` still guards the `like` legs, so a `%` term stays literal.
- A row matched by something the operator cannot see says so under the site name:
  `sites.search_match.alias` (`Aramayla eşleşen adres: www.x.com`) or `sites.search_match.uuid`.
  `Site::searchMatchReason()` returns `null` when the term is already visible in the name, slug or
  primary domain, so a normal search adds no noise.
- `SiteController@index` eager-loads `domains` **only when the search term is non-empty**: one
  extra query while searching, zero while browsing the fleet.

Tests: `SiteFleetSearchTest` (alias found once, temporary host, exact uuid, partial uuid ignored,
`%`/`_` not honoured as wildcards, the match line, the quiet case, and the query cost both ways).

### Keyboard shortcuts and the quick-jump palette

The ops shell ships two keyboards, both documented in the `?` overlay:

- `/` focuses the list search on the current page (no-op where there is no toolbar).
- `g` then `s` / `d` / `t` / `f` jumps to Sites / Domains / Themes / Fleet. Targets are
  server-rendered on the overlay (`data-ops-shortcuts-go` + `data-ops-shortcuts-url`); the
  script never builds a route.
- `Ctrl/⌘+K` opens a labelled palette over pages, sites, domains and themes.
- Bare keystrokes stay off while a field is focused or the confirm modal is open. `Ctrl/⌘+K`
  still opens from a field; the confirm dialog keeps the keyboard.

`GET /palette?q=` (`ops.palette`, auth required) is the only search the palette script runs. An
empty `q` returns top-level nav pages — never `/sites/create` or `/jobs`. A non-empty `q` reuses
`Site::matchingListFilters`, `SiteDomain::matchingListFilters` and `Theme::matchingListFilters`
(limit 8 per group), so a paste that finds a site on `/sites` finds the same site here. Bound
domains jump to the site; an unbound host opens `/domains?q=`. **Sistem** section hashes
(`SettingsJump`: env / GitHub / customer defaults / Coolify) appear only when `q` matches that
section — `/settings#env-defaults-heading`, never a second Settings route. A Viewer receives only URLs those
lists already authorize.

Markup: `ops.partials.shortcuts-overlay`, `ops.partials.palette`. Scripts: `ops-shortcuts.js`,
`ops-palette.js`. Tests: `KeyboardShortcutsTest`, `PaletteSearchTest` pin the markup the
scripts read. Chord / palette `isTyping` / `confirmOpen` guards: `node --test tests/js/*.js` — [ADR-11](../decisions/adr-11-dom-test-harness.md).

### Columns and sorting

`SiteListColumns` is the catalog (key → i18n label + sortable SQL column + whether it is on by default). `SiteListView::resolve()` turns the request plus the operator's saved layout into the visible column list and the active sort.

- **Column picker** (toolbar **Kolonlar**): checkboxes POST `columns[]`, stored per user in `users.list_preferences` JSON under the `sites` key — **in the database**, not `localStorage`, so the layout follows the operator to another browser. Reset is a `DELETE` on the same route. `site` is **locked** (disabled checkbox plus a hidden input, and `sanitize()` forces it back server-side); unknown keys are dropped and an empty pick falls back to defaults. Catalog order always wins over checkbox order.
- Over fetch both routes answer `{ ok, message, list, columns, refresh_list }` instead of a redirect, so the picker re-checks its boxes and the table re-renders **without F5**. A plain POST still redirects back with a flash for the no-JS path.
- Viewers may save their own layout — it is a personal view setting, not an ops write.
- **Sorting** is real `<a>` headers carrying `?sort={key}&dir={asc|desc}` through `fullUrlWithQuery` with `page` dropped, so it works without JS, survives filters, and is shareable. `aria-sort` is on the `<th>`; the caret is decorative and never the only signal. Ties break on `name` so pagination stays stable. Unsortable columns (App, Theme) render a plain header.
- The chosen sort is remembered in the same preferences row, so the next unqualified visit to `/sites` reopens the operator's last sort. A stored or default sort key on a column the operator has since **hidden** falls back to the default column *and* the default direction, so nobody gets stuck sorted by something invisible.

### Saved views

Filters were URL-only. A chip row under the toolbar now offers built-in views (`Tümü`, `Sorunlu` = `status=error`, `Yayında değil` = `publish=draft`, `Dockerfile kalanları` = `pack=dockerfile`) plus up to five operator-named presets. Saving captures the current filters, columns and sort into `users.list_preferences.sites.views`. One saved view can be the default: a bare `/sites` visit 302s to that query (a region request applies it in place, so `ops-list.js` does not bounce). `?view=all` is the explicit «show everything this time» and is what **Filtreleri temizle** uses, so clearing a default view does not immediately put it back.

Chips are plain GET links. A saved view that still names a removed channel (or any other allowlisted value that no longer exists) drops that key the way a hidden-column sort falls back. `pack=dockerfile` is the same leftover predicate as `withDockerfileBuildPackWarning()`, so the built-in chip and `all=1` under it stay inside that set (`filter_pack` rides along with the other bulk filters). A saved view may also capture `health=unhealthy` and `app=issues`. Views are per user; a Viewer may save their own. Tests: `SiteSavedViewsTest`, `SiteListPreferencesTest`.

**Sync Coolify** on the list (`POST /sites/bulk/sync`, `all=1` or selected ids) is the same `CoolifySiteSync` as site detail. Sites without `coolify_app_uuid` are skipped. GET `/sites/bulk/sync` is a 302.

**Live Sync** (`POST /sites/bulk/live-sync`) GETs `https://{primary_domain}/` (timeout 8s / connect 4s, follow redirects, UA `Deamon-Plane-LiveSync/1`). Host must match `^[a-z0-9.-]+$`. Stores `last_live_http_status` (0 = connection failure), `last_live_checked_at`, and `last_live_favicon_url` from HTML `<link rel*="icon">` (absolute; skip `javascript:` / `data:` / `file:`). Does not write secrets or flip `sites.status`. The Live column shows 200 / 404 / 500 / Down / — plus a freshness line (see [Stale-data badges](#stale-data-badges-instead-of-bare-timestamps)). Viewer forbidden. GET does not probe. Tests use `Http::fake` only.

### Stale-data badges instead of bare timestamps

Agent health, the live probe and the publish mirror used to hide freshness in a `title` or print `Y-m-d H:i`. A poll that died four hours ago looked identical to a healthy one.

`App\Support\OpsFreshness` is the one primitive. It turns a timestamp into a relative label (`4 sa önce`, `ops.freshness.*` — never concatenated in Blade), puts the absolute time in the tooltip, and adds a visible **Eski** word (not colour alone) when the age is older than **2×** the clamped agent poll window (`ops.agent.poll_minutes`, 5–15, same clamp as `routes/console.php`). `null` renders `Hiç` or `Bilinmiyor` and is never stale.

Live has no scheduler of its own; the publish mirror is written by the same health job. Both reuse that window so "stale" means the same thing on every column. The fleet unhealthy KPI still uses `ops.agent.stale_after_minutes` inside `SiteHealthEvaluator` — that is a stronger verdict, not this badge.

The list cells (`_cell.blade.php`), the site-detail last-check row and the publish last-confirmed row all render `<x-ops.freshness>`. The optional **Güncellendi** column uses the same relative age with `markStale=false`: `updated_at` is not a poll, so a site nobody edited for four hours is not **Eski**. Tests: `OpsFreshnessTest`, `SiteFreshnessBadgeTest`.

Imported sites whose Coolify `build_pack` is `dockerfile` keep a `dockerfile_build_pack` line in `notes`. The list shows a **Dockerfile (eski pack)** chip and an App-health issue. Site detail / edit can **PATCH** the existing Coolify app to `dockercompose` + `/docker-compose.coolify.yml` (no DELETE). Compose brings its own MySQL+Redis; external Dockerfile DB data stays put; `APP_KEY` is rewritten onto service `app` only. After the pack PATCH (and on an already-compose retry), env sync applies the **compose** catalog: `DB_HOST=mysql`, empty `MYSQL_ROOT_PASSWORD` / `DB_PASSWORD` generated, existing `DB_PASSWORD` not copied-over-if-filled. Recreate required → abort. Pack migrate does **not** by itself `POST /deploy` — operator Redeploy / App fix **Redeploy** starts the stack.

List header checkbox **Select all** sends `all=1` for the current filters (every matching site, not only the page). Bulk `form-actions` show only when something is selected: **Change branch**, **Yayına al**, **Switch to Compose** (only if a Dockerfile leftover exists), **Oto-deploy aç** / **Oto-deploy kapat**, **Redeploy**, **Deploy HEAD**, **Deploy commit** (typed SHA or tag; page commits are suggestions only when the filtered set fits on this page). Confirm on each. **Yayından kaldır** and **Hard Delete** are not in that row — see [destructive bulk actions](#destructive-bulk-actions-sit-behind-a-menu). Viewer forbidden.

### The bulk bar states its own scope

`all=1` is a fleet-wide instruction, so the number it resolves to is rendered, not implied. `_region.blade.php` wraps the action row in `[data-ops-bulk-actions]` carrying `data-bulk-total="{{ $sites->total() }}"` plus the two summary templates. `setupBulkSelection` in `ops-ui.js` reads them and keeps one line honest above the buttons: `3 site seçildi` for a page selection, `Filtreye uyan 214 sitenin tümü seçildi` once the header box is ticked, with `Filtreye uyan 214 sitenin tümünü seç` / `Sadece bu sayfayı seç` to switch scope (both hidden when the filter does not reach past the page). Rows ticked one by one leave the header box `indeterminate`, never silently `all=1`.

Every bulk confirm body carries the same number. Buttons ship a `data-confirm-template` whose `__COUNT__` (and, for branch, `__TARGET__`) is substituted on each selection change, so `sites.danger.hard_confirm_bulk` reads `Seçili 214 site kalıcı silinsin mi?` instead of an uncounted `Seçili siteler`. The server-rendered `data-confirm` fallback is interpolated with `$sites->total()` on purpose: if `ops-ui.js` fails to bind, the confirm over-warns with the widest reachable scope rather than under-warning. Tests: `SiteBulkSelectionScopeTest` (Blade tokens). Scope count + `__COUNT__` / `__TARGET__` interpolate: `node --test tests/js/*.js` — [ADR-11](../decisions/adr-11-dom-test-harness.md).

### Bulk defaults match the selection

Two defaults used to lie at scale.

**Pin.** `$bulkPinCommits` was built from `$sites->getCollection()` — the current page — so `all=1` over 200 sites offered SHAs from 25 of them. The control is now always a text input (`name="ref"`). Unique SHAs from this page appear as a `<datalist>` only when the filtered set fits on the page (`!$sites->hasMorePages()`). When it does not, the placeholder asks for a SHA or tag and a hint says the page list is not the selection. `BulkPinSiteRequest` still requires the typed ref.

**Auto-deploy.** One **Aç/Kapa** button inferred mixed → off via `CoolifyDeploySettings::toggleEnabledFor()`, which GETs Coolify per site. That method is gone. The bar has **Oto-deploy aç** (`enabled=1`) and **Oto-deploy kapat** (`enabled=0`), each with its own counted confirm (`site_ops.bulk.confirm_auto_on` / `confirm_auto_off`). `BulkAutoDeploySiteRequest` requires `enabled`. A POST without it is 422 and never touches Coolify. The queued job payload always carries the flag.

Tests: `SiteBulkActionsTest`.

### Confirm matrix

Every `data-confirm` on Sites list and site detail also carries `data-confirm-title`, `data-confirm-label`, and an explicit `data-confirm-danger` of `true` or `false`. Confirm bodies are translation keys with placeholders — never Blade concatenation (`fixes.X — name?` is now `sites.app_health.confirm_fix`).

**Danger is true** when the action deletes, unpublishes, stops, rotates/injects a secret, or triggers a Coolify build on more than one site (bulk branch / redeploy / follow HEAD / pin). Single-site pin, redeploy, provision, sync, and auto-deploy toggles stay `false`. The same contract applies outside Sites: Coolify disconnect, mail-server delete, theme-allowlist revoke, theme-git disconnect, and Cloudflare account / zone / DNS / Deamon-DNS-default deletes are `true`; Coolify sync / make-default, Cloudflare apply-defaults, and Deamon-DNS reset stay `false`. Tests: `ConfirmMatrixTest`.

### Destructive bulk actions sit behind a menu

The routine bulk buttons stay inline. The two that cannot be walked back — **Yayından kaldır**
(`bulk.publish-status` with `publish_status=draft`) and **Hard Delete** (`bulk.purge`) — live in a
separated `Tehlikeli işlemler` disclosure at the end of the row, built on the shared
`ops-action-menu` primitive (`data-ops-action-menu`: closes on Escape and outside click, `<summary>`
opens on Enter/Space with no JS). `sites.bulk_danger.trigger` / `.label` name it; the items keep
`ops-menu-button is-danger`, `role="menuitem"`, `data-confirm-danger="true"` and the P0-1
`__COUNT__` template, so opening the menu is an extra step, not a replacement for the confirm.

The reason is `all=1`: with the header checkbox ticked, Hard Delete was one mis-click away from
**Commite geç** and reached the whole filtered fleet. The details element sits **inside** the bulk
form (nested forms are invalid), so the buttons remain plain submits with `formaction`, and
`setupBulkSelection` still finds their confirm templates through the form. The popover opens
upward (`.sites-bulk-danger .ops-action-popover`) because the bar is the last thing on the page.
Tests: `SiteBulkDangerMenuTest`.

### Fleet fix counts are cached, not rescanned per keystroke

The header **Fix App issues** menu needs fleet-wide counts (`SiteAppHealthFixer::categoryCounts()`), which walks the whole `sites` table through `neededFixes()`. The toolbar re-requests the list URL on every keystroke, filter, sort and page, and a region response does not render that menu at all — so `SiteController@index` skips the scan whenever `ListFragment::wanted($request)` is true or the user cannot write ops. Full page renders read `cachedCategoryCounts()` (`ops.app_health.counts_ttl`, default 60s, `0` disables caching) and the menu prints `sites.app_health.counts_age` so the number is never passed off as live. Any `fix()` attempt — success or failure — clears the entry via `forgetCategoryCounts()`, so the menu cannot keep offering work that is already applied. Tests: `SiteListAppHealthCountCostTest`.

### Redeploy never picks between pin and HEAD

`POST /deploy?force=true` is the whole action: no `git_commit_sha` PATCH, no `is_auto_deploy_enabled` PATCH. Each app therefore rebuilds the ref it already points at — a pinned site rebuilds its pinned commit, an unpinned site rebuilds the branch tip. **Redeploy is never an operator choice between pin and HEAD**, and it never moves a site off its pin; **Deploy HEAD** (`bulk.follow-head`) and **Deploy commit** (`bulk.pin`) are the two actions that do change the ref. Copy must state that rule instead of "pin or HEAD": the site detail card knows the live `git_commit_sha`, so it names the pinned short SHA (`site_ops.redeploy.confirm_pinned`) or the branch (`site_ops.redeploy.confirm_head`); the list has no per-site pin state without one Coolify GET per row, so the bulk confirm (`site_ops.bulk.confirm_redeploy`) states the per-site rule and points at the other two actions.

### Bulk counting: ok / hata / atlandı / sıra bekliyor

`PacedFanout` defers a throttled site to the back of the queue, so a 429 seen mid-sweep says nothing about the outcome. There are **three** ways a site can end a sweep untouched, and only one of them is a fault. Accounting is therefore:

- `ok` — the action went through, including sites that only succeeded after being re-queued behind a 429.
- `failed` — terminal non-throttle error (`hata`).
- `skipped` — ran out of `ops.coolify.bulk.max_site_attempts` while still throttled, so Coolify was never told to act (`atlandı (istek sınırı)`).
- `waiting` — the per-server build gate held the site back for every attempt: nothing was sent to Coolify and the site is exactly as it was (`sıra bekliyor`). A waiting site is **not** an error and is deliberately kept out of `errors` as well as out of `failed`.
- `rate_limited` is `skipped > 0`, never "a 429 happened". Only that flag appends `ops.bulk.rate_limited`, which asks the operator to re-run **the skipped sites**. `deploy_busy` is `waiting > 0` and appends `ops.bulk.deploy_busy` the same way.
- `throttled` / `deferred` / `deferrals` are observability only and never reach operator copy.

`waiting` is appended to the counts as its own segment (`1 deploy tetiklendi, 3 sıra bekliyor`) rather than folded into the ok/failed/skipped phrases, because it is orthogonal to all three: it can accompany any of them, and unlike a failure it clears itself once the build slot frees.

`BulkResultSummary` renders this one way for both the jobs widget (`OpsJobRunner`) and the no-JS flash (`SiteCoolifyOpsController::bulkFlash`), so a sweep where every selected site started reads `Pin: 27 tamam` with no skip warning attached.

### A sweep does not queue behind its own builds

`CoolifyDeployGate` caps concurrent builds per Coolify host at `ops.coolify.deploy.max_concurrent_per_server` (1). Applied naively that cap eats bulk deploys: the sweep queues site 1, then counts site 1's own open build against site 2, refuses it, and after `max_site_attempts` short waits reports a site that was never asked to deploy as `hata`. A 23-site sweep read `1 deploy tetiklendi, 22 hata`.

`PacedFanout::run()` therefore wraps the whole sweep in `CoolifyDeployGate::duringSweep()`. The gate snapshots the highest `deployments.id` when the sweep opens; while the sweep is running, only open builds at or below that watermark block a deploy. The sweep is already serial and hands each build to the Coolify queue, so it is the queue — it must not stand behind itself. The gate keeps doing what it was written for: a build somebody else started still refuses the sweep (every site comes back `sıra bekliyor`, untouched), and once the sweep closes, an unrelated single-site redeploy queues behind the builds it left open.

The gate is a container singleton (`AppServiceProvider`) for the same reason `CoolifyRateGuard` is: the deploy paths resolve it one site at a time and it has to recognise the sweep that opened it. The watermark is released in a `finally`, and nested sweeps inherit the outer one.

## Import existing Coolify apps

Artisan `ops:import-coolify-apps` (Task 7). Default is **dry-run**; `--apply` upserts. Classification, domain parse, and status rules: [import-coolify-apps.md](../runbooks/import-coolify-apps.md).

- Customer filter: `DEAMON_GIT_REPOSITORY` / `codron-co/deamon`, excluding `deamon-plane`, `entron`, `webapp-transfer`, theme repos.
- Upsert by `coolify_app_uuid` then primary domain. Slug from host / app name on create only.
- No agent secrets. `dockerfile` is imported with a notes flag + visible list/edit warning; compose preferred.
- Duplicate domain in one import (two Coolify uuids, same host): first write wins, second **skip** — not merged.
