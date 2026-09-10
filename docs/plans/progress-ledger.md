# Plane progress ledger

Durable orchestrator state. Do not re-dispatch completed tasks.

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
- Theme agent: **locked to CMS v1.2.5** (`ControlPlaneAgentContract`). Paths `/internal/control/v1/themes` + install/update/activate/sync. HMAC `X-Deamon-*`. Install body includes `source=git`. Sync body `{action, mode, theme_id}`. Assign = install → activate → sync as separate POSTs.
- HMAC: same `X-Deamon-*` helper as Task 9. Coolify webhook secret is separate.
- Suite after CMS 1.2.5 lock: **163 passed** (805 assertions). Pint `--dirty` clean.
- Commit: not requested

```txt
## Dalga 4 raporu — Deamon Plane
Subagents: closer implemented CATALOG / ASSIGN / WEBHOOKS in-repo (CMS-THEME is the other repo)
CMS handoff Task 11: **done** (deamon v1.2.5) — Plane Task 12 headers/paths/bodies locked
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
| 10 theme catalog | THEME-CATALOG | **done** (schema + GitHubAppClient + sync UI + Http::fake; live GitHub creds optional) |
| 11 CMS theme agent | CMS-THEME-AGENT (deamon) | **done** (CMS **v1.2.5**; Plane client locked) |
| 12 assign | THEME-ASSIGN | **done** (headers/paths/bodies locked to CMS 1.2.5; install→activate→sync separate) |
| 13 GH webhooks | THEME-WEBHOOKS | **done** (`POST /webhooks/github`, distinct secret, fan-out + semver skip) |
| 14 security | SECURITY | **done** (checklist closed in `docs/security.md`) |
| 15 prod deploy plane | PROD-DEPLOY | **partial** — runbook complete; **live deploy skipped**; read-only status recorded above |

## Settings: Coolify panel removed (2026-09-10)

- Settings (`/settings`) is GitHub theme catalog + customer defaults only. Coolify token / webhook / UUID / Test connection live under **Coolify** (`/coolify`).
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
- GitHub App/PAT + org webhook secret must be pasted in Settings (not in git). Theme catalog ≠ Coolify Git source. Coolify API token lives under **Coolify** (`/coolify`), not Settings.
- CMS Task 11 is live at v1.2.5 — site still needs `CONTROL_PLANE_AGENT_SECRET` injected before theme assign 200s.
- Plane app `d6ovbjzxgpao23faam3vrcve` domain **https://plane.codron.co**. Deploy that uuid only. Never touch Susa `crxguq6nodorlzy88wf9x305`.

## Open in Coolify — environment uuid (2026-09-10)

- Status: **ship**. Deep link is `{base}/project/{project_uuid}/environment/{environment_uuid}/application/{app_uuid}`. Site uuids first, then connection defaults. Environment **name** or git channel (`alpha`) is never a path segment.

## Slice 6 — dockerfile → compose + auto-deploy + pin (2026-09-10)

- Status: **code**. PATCH existing Coolify app to `dockercompose` + `/docker-compose.coolify.yml`. No DELETE. `listEnvs` snapshot restores `APP_KEY`/`APP_URL`/`DEAMON_*` onto service `app` only; `DB_*` not copied. Recreate → abort. Auto-deploy `is_auto_deploy`. Pin SHA/tag + auto-deploy off; Follow HEAD unpins + auto-deploy on + branch deploy. ChannelSwitcher unchanged. Bulk selected + all Dockerfile, confirm on dangerous actions.

## Slice 5 — sync fills site Coolify targets (2026-09-10)

- Status: **ship**. `CoolifyInventorySync` still fills servers/projects/envs/git, then `CoolifySiteTargetSync` GETs each site’s Coolify app and writes project / env / server / git / allowlisted channel. Secrets and `status` unchanged. `develop` → `channel_needs_review` (channel kept). App 404 skips the site. Inventory `is_active` still not zeroed. Flash: `:sites` filled.

## Slice 3 — deploy show + copyable Coolify error (2026-09-10)

- Status: **ship**. Site Deployments rows open `GET /sites/{site}/deployments/{deployment}`. Poll + webhook failures store Coolify `message` + `errors` JSON and truncated redacted logs (`error_message` / `log_excerpt`). Copyable `<pre>` report. GET `/coolify/{id}/sync` stays a 302 to show (POST Sync button); not 405.

## Slice 2 — compose create without `fqdn` (2026-09-10)

- Status: **ship**. Compose create/PATCH send only `docker_compose_domains` (`app` + `https://{operator-host}`). No `fqdn` field (Coolify: `This field is not allowed.`). `CoolifyApplication::primaryDomain()` prefers operator host over `{uuid}.demo.codron.co` / `.random.codron.co`. Plane does not promote generate-domain to `site_domains` primary. Retry provision if app uuid exists — do not recreate.

## Slice 1 — server IP (2026-09-10)

- Status: **ship**. `coolify_servers.ip` from Coolify `public_ip`/`public` else `ip`. Connection show table has IP column. Sync does not write `is_active` (does not zero it).
