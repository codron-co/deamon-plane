# Plane progress ledger

Durable orchestrator state. Do not re-dispatch completed tasks.

## Site publish state + operator-owned sites list (2026-09-12)

- Status: **code**, both repos. Publish stays **CMS-owned**: the CMS `sites.status` (`draft` | `published`) that already drives its maintenance page is the only publish model, and Plane's `sites.status` remains the Coolify lifecycle. Plane mirrors the CMS value into `sites.cms_site_status` / `cms_site_status_at` so the list can sort and filter on a real column, and writes only through the signed agent (`SitePublishStateUpdater`  CMS `POST /internal/control/v1/site/status`, new in CMS 1.2.16). The mirror records what the CMS **echoed**, never what Plane asked for; a failed call writes neither mirror nor audit; an unreachable poll leaves a known state alone. `null` is **Bilinmiyor**, not `draft`. Yay�n chip + filter on the list, a Yay�n durumu card on site detail, and Yay�na al / Yay�ndan kald�r bulk actions (`sites.bulk_publish_status`).
- Fixed along the way: `AgentHealthResult::fromCmsPayload` never put `site_status` in the summary, so the field `SiteHealthChecker` allowlisted could never arrive. `ops-jobs.js` gated `applyAppHealthResults` behind `job.type === "sites.live_sync"` while the function itself required `sites.bulk_app_health_fix`, so it was dead code; the gate is now "just finished" and each applier checks its own type.
- Sites list columns and sorting are now the operator's: `SiteListColumns` catalog + `SiteListView`, layout and last sort persisted per user in `users.list_preferences` JSON (**DB**, not localStorage). `site` is locked. Sorting is real `?sort=&dir=` anchors with `aria-sort`, works without JS, keeps filters, resets `page`, ties break on `name`. A stored sort on a column the operator hid falls back to the default column *and* direction.
- Docs: [../modules/ops-sites.md](../modules/ops-sites.md), [../modules/agent-client.md](../modules/agent-client.md), [../superpowers/specs/2026-09-12-site-publish-state-and-list-preferences-design.md](../superpowers/specs/2026-09-12-site-publish-state-and-list-preferences-design.md). Tests: `SitePublishStateTest`, `SiteListPreferencesTest` (Plane), `ControlPlaneSiteStatusTest` (CMS).

## Jobs widget says what it is, and stops rebuilding itself (2026-09-12)

- Status: **code**. Every widget row now names its kind before anything else: `kind_label` / `subject` / `status_label` / `detail` ship in `OpsBackgroundJob::toWidget`, `Deployment::toWidget` and `OpsCoolifyDeployQueue::remoteWidget`, so a deploy reads `Coolify deploy · beyazlar` + `manuel · kuyrukta` instead of a bare site name over `manuel · kuyrukta`. Bulk sweeps carry a site count (`App hatalarını düzelt · 3 site`); single-target jobs carry `payload.subject`. Coolify queue rows Plane did not start say `Coolify kuyruğu`, not a made-up trigger.
- `ops-jobs.js` patches rows keyed by `data-job-id` instead of `replaceChildren()` on every 1.5s poll, which is what made cancel / force-start / dismiss unclickable: the button was replaced between mousedown and click. Listeners bind once per row and read the current payload from row state; focus is restored if a reorder moves a focused node; the indeterminate bar no longer restarts its animation each tick. Docs: [../modules/ops-sites.md](../modules/ops-sites.md). Tests: `DeploymentPollWidgetTest`, `SiteAppHealthTest`.

## Coolify deploy-row heal — generic API-failure race (2026-09-12)

- Status: **code**. `ops:heal-throttled-deploys` markers now cover every sentence Plane writes about a *status read* rather than a build: the bare `Coolify API request failed.` a raced read leaves behind (empty body mid cancel/restart, so the row lands `failed` while Coolify reports `cancelled-by-user`) and `Timed out waiting for Coolify deployment.` from an exhausted poll. When Coolify still says `failed` and carries no message or logs, the row's original text and `finished_at` are restored instead of the generic `Coolify deployment failed.`. Runbook: [../runbooks/coolify-rate-limit.md](../runbooks/coolify-rate-limit.md). Tests: `HealThrottledDeploysCommandTest`.
- Live: the four rows left from the 2026-09-11 poll storm (izyem #737, Lökçe #738, ltscanta #739, makermak #740) are `cancelled` from Coolify truth, and ltscanta #721 is `finished`. Older `failed` rows on other sites are real build failures, not this race.

## CMS runtime image has no `git` — control-plane theme git is dead on live (2026-09-12)

- Status: **blocker, CMS repo**. `themes/install` → `502 git_failed` and `themes/update` → `404 theme_not_found` on izyem because the CMS runtime image (`php:8.2-fpm-bookworm`, CMS `Dockerfile`) never installs `git`; `ProcessThemeGitRunner` shells out to `git clone`. `themes/.control-plane-git/` is empty on the site and `storage/app/theme-data/` does not exist, so CMS 1.2.14 `themes/data-install` has no source and answers `422 data_package_missing` — the Sync self-heal cannot fire. izyem's live `themes/izyem` carries theme files only (uploaded 2026-09-11 19:40), which is why Sync has always reported `sync.json tanımlı değil`. Fix is a CMS `Dockerfile` change plus a rebuild; not a Plane bug. Plane's error text is accurate and was left in place.

## Theme data package rollout fix (2026-09-11)

- Status: **code**. CMS `themes/install|update` now publish the SoT repo-root data package (`sync.json` + `data/`) into `storage/app/theme-data/{id}` (CMS **1.2.14**), so Sync after Activate works without SSH. New `POST /internal/control/v1/themes/data-install` repairs sites installed by an older agent; `themes/sync` preflights and returns `data_package_missing`. `ThemeRolloutService` retries sync once through data-install (audit `theme.data_installed`). Merge mode no longer unpublishes rows the package stopped shipping. Docs: [modules/theme-agent-client.md](../modules/theme-agent-client.md). Tests: Plane `ThemeAssignTest`; CMS `ControlPlaneThemeDataPackageTest`, `ThemeSyncMergeContractTest`.
- Follow-up (2026-09-12): **Sync now** self-heal is now covered end to end, and two holes on that path are closed — a healed sync clears `last_error` / lifts `error` status, and a failed repair surfaces the CMS data-package message instead of the misleading "CMS older than 1.2.5". `ControlPlaneAgentSignatureTest` asserts a `CMS_VERSION` floor of 1.2.14 rather than an exact pin that broke on every CMS release.

## Site admin management (2026-09-11)

- Status: **code**. Site detail **Admins** tab: list/create/reset (operator); deactivate/delete (Super Admin). HMAC `/internal/control/v1/admins*` (CMS **1.2.13+**). Passwords generate-or-manual, one-time flash only, never stored. Last active admin guarded. Spec: [../superpowers/specs/2026-09-11-site-admin-management-design.md](../superpowers/specs/2026-09-11-site-admin-management-design.md). Module: [modules/site-admins.md](../modules/site-admins.md). Tests: Plane `SiteAdminAgentTest`; CMS `ControlPlaneAdminAgentTest`.

## Software mail (Plane SMTP) — 2026-09-11

- Status: **code**. Global `/platform-mail` SMTP + notification catalog; site Infrastructure overrides; push `POST /internal/control/v1/platform-mail/configure`. Plane sends site down/up/version/deploy-failed. CMS sends password reset, admin welcome, weekly report, member/order, publish toggle. Hostinger mailboxes unchanged. Docs: [modules/platform-mail.md](../modules/platform-mail.md).

## Site mailbox bindings + CMS requests (2026-09-10)

- Status: **code**. Infrastructure mail card is mailbox-domain selects (existing values pre-selected), not “mail order” auto-match only. `site_mail_bindings` holds many domains; `site_mailbox_requests` is the CMS queue. Configure sends `mail_domains` (CMS `MailConfigureController` validates the array). Proxy lists all bindings; create accepts `domain`. Tests: Plane `SiteMailAssignTest` / `SiteMailProxyTest` / `MailServerOpsTest`; CMS `ControlPlaneMailConfigureTest` / `HostingerMailAdminTest` / `HostingerMailCustomerTest`.

## Sites App health + Coolify job widget (2026-09-10)

- Status: **code**. Sites list **App** column (Healthy / N issues, hover + copy). Detail card with AJAX fixes (env sync, compose migrate, agent secret, redeploy, agent check). Jobs widget polls fleet Coolify `deployments` plus recent ops jobs. Manual redeploy/pin/HEAD now insert a local deployment row. Tests: `SiteAppHealthTest`.

## Bulk App health fixes (2026-09-11)

- Status: **code**. Sites list row + header Fix App issues menus; `fix=all`; bulk job `sites.bulk_app_health_fix`. Spec: [../superpowers/specs/2026-09-11-bulk-app-health-fixes-design.md](../superpowers/specs/2026-09-11-bulk-app-health-fixes-design.md).

## Deploy status App health sync + auto-deploy verify (2026-09-11)

- Status: **code**. `forDisplay` merges local deploy/agent/secret into cached inspect; Sync/poll refresh local cache; latest deploy by `started_at`; auto-deploy verifies Coolify `is_auto_deploy_enabled` after PATCH. Spec: [../superpowers/specs/2026-09-11-deploy-status-app-health-sync-design.md](../superpowers/specs/2026-09-11-deploy-status-app-health-sync-design.md).

## Domain registry + Coolify binding (2026-09-11)

- Status: **code**. `/domains` fleet UI; Coolify Sync import + auto-rebind; App health `domain_unbound` + `bind_domains`. Spec: [../superpowers/specs/2026-09-11-domain-registry-coolify-binding-design.md](../superpowers/specs/2026-09-11-domain-registry-coolify-binding-design.md).

## Theme Git connections — Settings paste → Themes Manifest (2026-09-10)

- Status: **code**. Themes hosts Connect GitHub (Manifest), Connect another (same App), PAT fallback, all vs selected, Sync repos, Disconnect (App stays). Settings GitHub paste removed (one-line pointer; leftover POSTs 302 to Themes). Catalog walks every `theme_git_connections` row. CMS **1.2.7** theme-git `repo` allowlist **landed** (any github.com owner); do not re-edit `deamon` for that contract. Plane `ControlPlaneAgentContract::CMS_VERSION` is **1.2.7**. Connect / connect-another / manifest forms are `data-ops-native` so `ops-async.js` does not swallow the GitHub redirect.
- Product: Coolify-style GitHub App Manifest (one Plane App, many user/org installations). Connections + all/selected picker under **Themes**. Settings GitHub paste removed (one-line pointer). Advanced PAT for local/no public URL. Coolify `/github-apps` UUID never reused. ZIP still out. First sync `private`. `clone_token` = installation token only.
- Spec: [../superpowers/specs/2026-09-10-theme-git-connections-design.md](../superpowers/specs/2026-09-10-theme-git-connections-design.md)
- Plan: [2026-09-10-theme-git-connections.md](2026-09-10-theme-git-connections.md)
- Deferred (not blockers): install callback does not auto-refresh the repo picker (operator clicks **Sync repos**); Disconnect does not delete the GitHub App.

## Coolify env default catalogs (2026-09-10)

- Status: **code**. Settings holds per-pack catalogs (`dockerfile` / `dockercompose`) on `coolify_env_defaults` (seeded). Normal view: static / required / generated / site / Coolify-injects. Developer view is an open KEY=value dump. `CoolifyAppEnvSync` runs on every Plane `deploy()` and after Dockerfile → compose. Empty/placeholder MySQL secrets are filled; filled secrets are not rotated; compose `DB_HOST` is forced to `mysql`. Tests: CoolifyAppEnvSyncTest, SettingsEnvDefaultsTest (`Http::fake`).

## Landing pause + multi-domain / www (2026-09-10)

- Status: **code**. Create/edit `aliases[]` on the same apex; every host gets a `www` sibling on `site_domains`, Cloudflare A records, and Coolify `app` (`https://host,https://www.host`). Pending Cloudflare zone: provision still creates the app on a temporary `{adj}-{noun}.{wildcard}` host; site detail warns and offers **I updated DNS**. Confirm while pending keeps temp; confirm when active binds customer hosts and deletes the temp row. Detail can add another same-apex host. Tests: `SiteLandingFlowTest`, `CloudflareHostnameTest`, updated `ProvisionSiteTest` / `ProvisionSiteCloudflareTest`.

## Europe/Istanbul (+03:00) everywhere (2026-09-10)

- Status: **code**. `APP_TIMEZONE=Europe/Istanbul`, MySQL session/server `+03:00`, container `TZ` on app/MySQL/Redis, PHP `date.timezone`. TIMESTAMP rows are not +3-updated (would double-shift). DATETIME columns shift +3 once in `2026_09_10_160000_shift_mysql_datetime_columns_to_istanbul`. Tests: AppTimezoneTest, MysqlWallClockShiftTest.

## Site detail + list deploy actions (2026-09-10)

- Status: **code**. Site show **Deploy** menu: Redeploy (`POST /deploy?force=true`), Deploy HEAD (follow HEAD), Open in Coolify. Infrastructure auto-deploy card always shows commit pin, update-to-latest, redeploy, follow HEAD. Sites list selection: Redeploy, Deploy HEAD, pin SHA. Tests: CoolifyDeploySettingsTest, SiteBulkActionsTest (`Http::fake`). No Coolify DELETE except hard purge.

## Site Cloudflare account + Free zone NS (2026-09-10)

- Status: **code**. `sites.cloudflare_setting_id` persists the selected account. Create/edit has an account select. Site detail Infrastructure card: account + **Add to Cloudflare (Free)** via AJAX (`POST /sites/{site}/cloudflare/zone`) returns copyable NS without reload. Unbound customer hostnames create a Free full zone on the apex instead of `{adjective}-{noun}.codron.co`. Provision uses the site account when set. Tests: `SiteCloudflareZoneTest`, `ProvisionSiteCloudflareTest`.

## Site detail action menus + Coolify start/stop/hard delete (2026-09-10)

- Status: **code**. Site show topbar is three menus: Sync (Coolify / Live Site / health), Site (homepage / admin), Settings (edit / activate-or-deactivate / soft delete / hard delete). Activate = Coolify `POST .../start` + status `stopped`→`active`. Deactivate = `POST .../stop` + `active`→`stopped`. Soft delete unchanged. Hard delete is the only Coolify `DELETE ?delete_volumes=true`, then `forceDelete`; also on `/sites` bulk. Tests: SiteLifecycleTest, CoolifyClientTest (`Http::fake`).

## Site detail copy in i-hints (2026-09-10)

## Dalga 0 — Discovery / Spike

- Status: **complete — Go**
- OpenAPI + live proof: `docs/plans/2026-08-13-coolify-spike-notes.md`
- Spike script: `tools/coolify-spike/` (read-only then `-Mutate` on Susa DEMO only)
- Staging app: **Susa** `crxguq6nodorlzy88wf9x305` (`codron-co/deamon`, compose, left on **beta**)
- Defaults: project Deamon `z8ocg8k04ww8osssccc088c0`; server localhost `no48ksggg0k8sk4o4w08gks8`
- Go/Hybrid/No-Go: **Go** (list + updateBranch + deploy + getDeployment + volume OK; setDomains OpenAPI + skip live domain PATCH; createComposeApp skipped)
- Coolify Compose sözleşmesi (Dalga 0): `docker-compose.coolify.yml` + `Dockerfile` + `docker/` — Laravel Task 0 scaffold present; first Deploy unblocked
- Laravel create-project: **done** (Task 0 bootstrap — merged non-destructively)
- CMS code in this repo: none
- Mailcow: out of scope
- Commit: `7d6d738 v0` plus this worktree (Dalga 0 notes + Task 0–7 uncommitted)

## Dalga 2 — Coolify Fleet

- Status: **complete** (Tasks 2–7; Http::fake suite **114 passed**)
- API mode: **hybrid** — compose create/branch/deploy/poll/import are automated; Coolify Notifications webhook POSTs are **unsigned**. Plane accepts query `token` matching the webhook signing secret. HMAC (`X-Coolify-Signature`) remains preferred when a signer exists. `PollDeploymentJob` remains the backup.
- Commit: not requested

## Dalga 3 — Agent Health

- Status: **both tracks done**
- Task 8 (CMS `codron-co/deamon`, **v1.1.43**): `/internal/control/v1/health` + HMAC; contract `docs/modules/control-plane-agent.md` in the CMS repo
- Task 9 (this repo): `SiteAgentClient` + jobs/UI; **headers locked to CMS**
- Integration: outgoing Plane requests use `X-Deamon-Timestamp`, `X-Deamon-Nonce`, `X-Deamon-Signature`; canonical `{timestamp}.{nonce}.{rawBody}`; GET body `""`
- Live smoke: later (staging site with injected secret)
- Secret inject: still manual — [agent-secret-inject.md](../runbooks/agent-secret-inject.md)
- Docs: `docs/modules/agent-client.md`
- Commit: not requested

## Dalga 4 — Themes (overnight closer)

- Status: **Plane track complete** (Tasks 10, 12, 13). Task 11 remains CMS repo.
- ZIP in Plane UI: **yok** (asserted: no `type=file` / ZipArchive / `name=zip`)
- Auto-update default: **off**
- Theme agent (historical overnight lock): **CMS v1.2.5** headers/paths/bodies. **Current SoT: CMS 1.2.7** — same HMAC/paths; `repo` now any github.com owner (see [theme-agent-client.md](../modules/theme-agent-client.md)). Paths `/internal/control/v1/themes` + install/update/activate/sync. HMAC `X-Deamon-*`. Install body includes `source=git`. Sync body `{action, mode, theme_id}`. Assign = install → activate → sync as separate POSTs.
- HMAC: same `X-Deamon-*` helper as Task 9. Coolify webhook secret is separate.
- Suite after historical CMS 1.2.5 lock: **163 passed** (805 assertions). Pint `--dirty` clean.
- Commit: not requested

```txt
## Dalga 4 raporu — Deamon Plane
Subagents: closer implemented CATALOG / ASSIGN / WEBHOOKS in-repo (CMS-THEME is the other repo)
CMS handoff Task 11: **done** (historical: deamon v1.2.5 headers/paths; **current: v1.2.7** any github.com `repo`) — Plane Task 12 HMAC/paths/bodies unchanged
ZIP in plane: yok (doğrulandı)
Auto-update default: off
Sonraki: Dalga 5 (done in same overnight pass)
```

## Dalga 5 — Harden + Deploy

- Status: **code + docs complete; live Plane deploy skipped (by design)**
- SECURITY: audit on theme mutations; `OPS_IP_ALLOWLIST`; `ProductionDebugGuard`; webhook throttles; `SecretRedactor`; confirm modals; [security.md](../security.md) checklist closed
- PROD-DEPLOY: compose contract unchanged; [deploy-plane.md](../runbooks/deploy-plane.md) is operator-complete. **No PATCH/deploy** of Plane or customer apps.
- Read-only Coolify GET `d6ovbjzxgpao23faam3vrcve` (2026-09-09): name **Deamon Plane**, `running:healthy`, repo `codron-co/deamon-plane`, branch **alpha**, `build_pack=dockercompose`, compose `/docker-compose.coolify.yml`, **fqdn and docker_compose_domains null**. Did not mutate. Susa not touched.
- Staging smoke: **blocked** until operator binds a Plane domain and deploys the branch that contains this overnight work (live app is still `alpha`).
- Faz A: **met** (fake/test). Faz B: **met** in Plane (CMS Task 11 parallel). Faz C: **partial** (docs + artifacts; live deploy/smoke leftover).

```txt
## Dalga 5 raporu — Deamon Plane
SECURITY: closed (tests + runbooks + IP allowlist + APP_DEBUG gate)
PROD-DEPLOY: artifacts ready; live Coolify mutate SKIPPED
Faz A/B/C: met / met (Plane) / partial (live deploy + import apply leftover)
Open follow-ups: Coolify UI domain + deploy current git; GitHub App/PAT; agent secret inject; import dry-run→apply
Subagent-driven: overnight closer (this track) — CMS Task 11 separate
```

## Task 0–15

| Task | Role | Status |
|------|------|--------|
| Spike | Dalga 0 | **complete — Go** |
| 0 bootstrap | BOOTSTRAP | **done** |
| 1 schema | SCHEMA | **done** |
| 2 Coolify client | COOLIFY-CLIENT | **done** |
| 3 site CRUD | UI-SITES | **done** |
| 4 provision | PROVISION | **done** |
| 5 channel | CHANNEL | **done** |
| 6 webhooks | WEBHOOKS-DEPLOY | **done** |
| 7 import | IMPORT | **done** |
| 8 CMS health | CMS-AGENT-HEALTH (deamon) | **done** (separate repo; health since v1.1.43) |
| 9 plane agent client | PLANE-AGENT-CLIENT | **done** |
| 10 theme catalog | THEME-CATALOG | **done** (2026-09-10): Themes Manifest + `theme_git_connections`; Settings paste retired (historical Task 10 was org+PAT/PEM on Settings) |
| 11 CMS theme agent | CMS-THEME-AGENT (deamon) | **done** (CMS **v1.2.7** theme git `repo` = any github.com owner; HMAC/paths from v1.2.5 lock) |
| 12 assign | THEME-ASSIGN | **done** (headers/paths/bodies locked; install→activate→sync separate; CMS **1.2.7** accepts any github.com `repo`) |
| 13 GH webhooks | THEME-WEBHOOKS | **done** (`POST /webhooks/github`, distinct secret, fan-out + semver skip) |
| 14 security | SECURITY | **done** (checklist closed in `docs/security.md`) |
| 15 prod deploy plane | PROD-DEPLOY | **partial** — runbook complete; **live deploy skipped**; read-only status recorded above |

## Settings: Coolify panel removed (2026-09-10)

- Settings (`/settings`) is customer / Coolify env defaults. Theme GitHub connect lives under **Themes** (Manifest; code shipped 2026-09-10). Coolify token / webhook / UUID / Test connection live under **Coolify** (`/coolify`).
- Leftover `POST /settings` and `POST /settings/coolify/test` redirect to Coolify connections.

## Coolify menu (ops — 2026-09-10)

- Status: **code complete** (this worktree). Multi-connection + server aktif/pasif + project/env/git allowlists + site `<select>` + attach existing + preflight + 422 field errors + agent secret inject.
- Nav: **Coolify**. Default connection for new sites. Super Admin advanced UUID paste (collapsed, warned). Compose path fixed `/docker-compose.coolify.yml`.
- GitHub Apps: `GET /github-apps` (v4.x). 404 → hybrid UI (deploy keys + advanced paste). Not the Plane theme-catalog GitHub App.
- Defaults: option **name** only (uuid = value/title). Single active server/project/env auto-persisted. Env dropdown is **project-scoped** (`project_uuid`), not a global dump.
- Servers: Sync stores Coolify `ip` (prefer `public_ip`/`public`). Connection server table has an IP column.

## Hybrid leftovers (operator on wake)

- Coolify Notifications remain unsigned — paste `https://{plane}/webhooks/coolify?token=<webhook signing secret>`. HMAC still preferred if a signer exists. Poll remains backup.
- Agent secret inject is in the site UI (`Generate & inject secret`) when Coolify env API works; Coolify UI leftover if that PATCH fails.
- Theme GitHub: **Settings paste → Themes Manifest** (code shipped 2026-09-10). Connect user/org under Themes; do not paste PAT/PEM on Settings; do not reuse Coolify `/github-apps` UUID. Theme catalog ≠ Coolify Git source. Coolify API token lives under **Coolify** (`/coolify`), not Settings. Deferred: Sync repos after install; App stays on last disconnect.
- CMS Task 11 is live at **v1.2.7** (any github.com owner/name for `source=git`). Site still needs `CONTROL_PLANE_AGENT_SECRET` injected before theme assign 200s. Historical Task 11 ship was v1.2.5 (headers/paths only).
- Plane app `d6ovbjzxgpao23faam3vrcve` domain **https://plane.codron.co**. Deploy that uuid only. Never touch Susa `crxguq6nodorlzy88wf9x305`.

## Open in Coolify — environment uuid (2026-09-10)

- Status: **ship**. Deep link is `{base}/project/{project_uuid}/environment/{environment_uuid}/application/{app_uuid}`. Site uuids first, then connection defaults. Environment **name** or git channel (`alpha`) is never a path segment.

## Slice 7 — Hostinger mail servers (2026-09-10)

- Status: **code**. `mail_servers` hold Hostinger API tokens only. Each site matches `GET /api/mail/v1/orders?domain=` against `sites.primary_domain` (exact). No server-wide order picker. Hostinger Mail cannot create orders. Assign/provision → bind then signed CMS configure (no token). Tests: MailServerOpsTest, SiteMailAssignTest, SiteMailProxyTest. Docs: [modules/mail-servers.md](../modules/mail-servers.md).

## Slice 6 — dockerfile → compose + auto-deploy + pin (2026-09-10)

- Status: **code**. PATCH existing Coolify app to `dockercompose` + `/docker-compose.coolify.yml` **and** `docker_compose_domains` (from live compose domains, else `fqdn`, else `sites.primary_domain`). Omitting domains wipes the Coolify proxy. No DELETE. `listEnvs` snapshot restores `APP_KEY`/`APP_URL`/`DEAMON_*` via Coolify 4.3 `/envs` `{ key, value, is_literal }` (not `is_literally` / `available_in_services` / env `uuid`); `DB_*` not copied. Recreate → abort. Env restore API errors are flash, not 500. Auto-deploy `is_auto_deploy_enabled` (Coolify rejects `is_auto_deploy`). Pin SHA/tag + auto-deploy off; Follow HEAD sends `git_commit_sha: "HEAD"` (empty string is invalid on Coolify 4.x) + auto-deploy on + branch deploy. Channel switch uses the same `HEAD` SHA then `POST /deploy`. Bulk selected + all Dockerfile, confirm on dangerous actions.

## Site Coolify / theme / confirm parity (2026-09-10)

- Status: **code**. Auto-deploy chip is On / Off / Unknown. GET reads `settings.is_auto_deploy` as well as `is_auto_deploy_enabled`; a missing flag is not shown as Off. Theme tab / overview / list use health `active_theme_id` when Plane has no installation row. Agent secret: generate only when missing; rotate icon when set. Mutating site ops and Coolify connection Sync / Make default use `PlaneConfirm` (`data-confirm` on form or submitter). Mail assign/refresh confirm only — mail backend unchanged.

## Site Coolify sync + deployments pull (2026-09-10)

- Status: **code**. Site detail **Sync Coolify** (`POST /sites/{site}/sync`) GETs the Coolify app (project/env/server/git/channel) and last 25 `GET /deployments/applications/{uuid}` rows. Upsert by `coolify_deployment_uuid`; new rows `trigger=manual`. Does not write secrets or flip `sites.status` from historical failures. Connection inventory Sync does the same deployment pull per site. GET `/sites/{site}/sync` is a 302. Tests: CoolifySiteSyncTest, CoolifyDeploySettingsTest (`Http::fake`).

## Sites list bulk selection (2026-09-10)

- Status: **code**. Header checkbox selects every site matching current filters (`all=1`), not the page. Bulk actions show only when a selection exists: Change branch, Switch to Compose (Dockerfile leftovers only), Auto-deploy on/off (all on → off; all off → on; mixed → off). Channel switch writes `APP_ENV` / `DEAMON_CHANNEL` and `git_branch` + `git_commit_sha: HEAD`. Coolify application PATCH must not send `environment_uuid` (API rejects it). Tests: SiteBulkActionsTest, ChannelSwitchTest.

## Background jobs widget + AJAX ops (2026-09-10)

- Status: **code**. Syncs and list bulk (Coolify / Live / inventory / theme catalog / branch / compose / auto-deploy) queue `OpsBackgroundJob` on JSON and return immediately. HTML POST still redirects and runs inline. Other mutating ops stay in-request but return JSON instead of a reload (`ConvertOpsAjaxRedirect`). Bottom-right widget (`data-ops-jobs`) shows queue/progress/result; Live chips update from `result.sites`. Tests: OpsBackgroundJobTest (`Http::fake`).

## Sites list Sync + Live Sync (2026-09-10)

- Status: **code**. Identity marks use UTF-8 `IdentityMark` (Turkish `İzyem` → `İ`, not a replacement character). List topbar **Sync Coolify** (`POST /sites/bulk/sync`) and **Live Sync** (`POST /sites/bulk/live-sync`). Live column stores homepage status + favicon href. GET variants 302, no outbound HTTP. Viewer forbidden. Tests: IdentityMarkTest, SiteLiveProbeTest, SiteLiveSyncTest (`Http::fake`).

## Slice 5 — sync fills site Coolify targets (2026-09-10)

- Status: **ship**. `CoolifyInventorySync` still fills servers/projects/envs/git, then `CoolifySiteTargetSync` GETs each site’s Coolify app and writes project / env / server / git / allowlisted channel. Secrets and `status` unchanged. `develop` → `channel_needs_review` (channel kept). App 404 skips the site. Inventory `is_active` still not zeroed. Flash: `:sites` filled.

## Slice 3 — deploy show + copyable Coolify error (2026-09-10)

- Status: **ship**. Site Deployments rows open `GET /sites/{site}/deployments/{deployment}`. Poll + webhook failures store Coolify `message` + `errors` JSON and truncated redacted logs (`error_message` / `log_excerpt`). Copyable `<pre>` report. GET `/coolify/{id}/sync` stays a 302 to show (POST Sync button); not 405.

## Slice 2 — compose create without `fqdn` (2026-09-10)

- Status: **ship**. Compose create/PATCH send only `docker_compose_domains` (`app` + `https://{operator-host}`). No `fqdn` field (Coolify: `This field is not allowed.`). `CoolifyApplication::primaryDomain()` prefers operator host over `{uuid}.demo.codron.co` / `.random.codron.co`. Plane does not promote generate-domain to `site_domains` primary. Retry provision if app uuid exists — do not recreate.

## Site detail action menus + Coolify start/stop/hard delete (2026-09-10)

- Status: **code**. Site show topbar is three menus: Sync (Coolify / Live Site / health), Site (homepage / admin), Settings (edit / activate-or-deactivate / soft delete / hard delete). Activate = Coolify `POST .../start` + status `stopped`→`active`. Deactivate = `POST .../stop` + `active`→`stopped`. Soft delete unchanged. Hard delete is the only Coolify `DELETE ?delete_volumes=true`, then `forceDelete`; also on `/sites` bulk. Tests: SiteLifecycleTest, CoolifyClientTest (`Http::fake`).

## Site detail copy in i-hints (2026-09-10)

- Status: **code**. Site show keeps operational facts and actions; explanatory ledes live in `ops.dashboard._hint`. Decorative kickers, the duplicate Live release card, and “installed by agent” chips are gone. Idle “everything looks good” card and duplicate hero Open links are gone. Tests: SiteDetailTest, ChannelSwitchTest.

## Coolify environment name = main (2026-09-10)

- Status: **code + live Deamon env**. Default Coolify project environment name is `main` (1:1 with git channel / `sites.channel`). `config/ops.php` `COOLIFY_ENVIRONMENT_NAME`, `CreateComposeAppRequest`, provision fallback, and connection factory default to `main`. Leftover `production` / `prod` canonicalize to `main` on create; inventory still matches those aliases. Laravel `APP_ENV` stays `production` / `staging` / `local`. Live: Deamon project env `i0sw4kk0cogg4o08oscwcssk` renamed `production` → `main` (same uuid, 18 apps). Other Coolify projects’ `production` envs left alone. Plane inventory row updated. Tests: ChannelEnvironmentMapTest, ProvisionSiteTest, CoolifyClientTest (`Http::fake`).

## Slice 1 — server IP (2026-09-10)

- Status: **ship**. `coolify_servers.ip` from Coolify `public_ip`/`public` else `ip`. Connection show table has IP column. Sync does not write `is_active` (does not zero it).
