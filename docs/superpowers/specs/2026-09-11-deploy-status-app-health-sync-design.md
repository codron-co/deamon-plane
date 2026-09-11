# Deploy status + App health sync freshness

**Date:** 2026-09-11  
**Status:** draft — amended 2026-09-11 (auto-deploy ↔ Coolify git deploy)  
**Repo:** Deamon Plane only  
**Order:** Wave 2 of 3 (after bulk App health fixes; before domain reconcile)

## Context

Operators see **Son Coolify deploy’u başarısız** on site detail / App health after a later deploy has succeeded in Coolify, even after **Sync**. Example path: `/sites/{site}/deployments/640` may still be a real historical failure (MySQL `exited (1)`), while a newer Coolify deploy finished green.

Today:

1. `CoolifySiteSync` fills app targets and upserts the last 25 Coolify deployments via `CoolifyDeploymentSync`.
2. It does **not** refresh `sites.last_app_health_*`.
3. `SiteAppHealthReport::forDisplay()` prefers the **cached** inspect payload whenever `checked` is true — so a stale `deploy_failed` issue survives until the operator clicks **Check Coolify** / an inspect job runs.
4. `SiteAppHealthInspector::localIssues()` correctly keys off `latestDeployment` (`latestOfMany` = highest local id). Cache bypasses that for display.
5. Webhooks + `PollDeploymentJob` update deployment rows, but App health cache is independent.

So “sync yaptım hâlâ başarısız” is expected with the current cache rule, even when the latest local deployment is `finished`.

## Goals

- After Coolify deployment sync (single site, bulk, webhook, poll), App health **display** must not claim `deploy_failed` when the site’s latest deployment is not failed.
- Prefer Coolify’s **most recent** deployment (by Coolify start/finish time, then uuid) as the source of truth for “latest”, not only local autoincrement surprises.
- Sync / poll paths remain `Http::fake`-testable; no live Coolify in CI.
- Keep historical failed rows visible in the Deployments table (do not delete or rewrite finished→failed terminal history incorrectly).
- Plane **Otomatik güncelleme** (auto-deploy) on/off must control Coolify’s **git auto-deploy** (`is_auto_deploy_enabled`) so push/webhook-triggered Coolify deploys follow Plane. Verify after write; detect drift on Sync.

## Non-goals

- Bulk App health fix UI (Wave 1).
- Domain inventory (Wave 3).
- Changing Coolify build/mysql root-cause of a true failed deploy.
- Flipping `sites.status` from historical failed rows (existing rule stays).
- Turning off Plane’s **inbound** Coolify Notifications webhook (`POST /webhooks/coolify`) when auto-deploy is off — that webhook is status reporting, not git auto-deploy.

## Approaches considered

| Approach | Pros | Cons |
|----------|------|------|
| A. Drop App health cache entirely; always recompute local + optional live | Always fresh | Live Coolify on every list render is too slow/expensive |
| B. **Merge local deploy/agent signals into cached display + refresh cache on deploy sync** (recommended) | Cheap list; fixes stale `deploy_failed`; small surface | Must define merge rules carefully |
| C. Only invalidate cache (`last_app_health_at = null`) on sync | Simple | Loses pack/env issues until next inspect; list flickers to local-only |

**Recommendation:** B.

## Design

### 1. Display merge

Add `SiteAppHealthReport::forDisplay(Site $site)` behavior:

1. Start from stored payload if present.
2. Recompute **local-only** issues via `SiteAppHealthInspector::localIssues($site)` (deploy_failed, missing_agent_secret, dockerfile notes flag, agent_unhealthy, missing_app).
3. From the stored issues, **drop** any whose code is in the local-owned set, then **append** fresh local issues.
4. Recompute `ok` from the merged list.
5. Do **not** change `last_app_health_payload` on read (display-only merge). Writers still persist full inspect results.

Local-owned codes: `missing_app`, `dockerfile_pack` (notes flag path), `missing_agent_secret`, `deploy_failed`, `agent_unhealthy`.

Live-owned codes stay cached until inspect: `wrong_compose_file`, `missing_env`, `wrong_env`, `coolify_unreachable`, live `dockerfile_pack` from Coolify pack.

### 2. Latest deployment definition

- Keep `latestDeployment()` as `latestOfMany()` for Eloquent convenience.
- For App health `deploy_failed` and site failure chip, resolve “latest” as:

  1. Among the site’s deployments, prefer the row with the greatest `started_at` (nulls last), then greatest `id`.
  2. After Coolify list sync, ensure the remote list’s newest-by-`created_at`/`started_at` row exists locally and is that candidate when its mapped status is terminal or in-progress.

Document in code: do not use “any failed row in last 25” for App health.

### 3. Sync / poll hooks

| Trigger | Extra step |
|---------|------------|
| `CoolifySiteSync::sync` | After deployment upserts: `InspectSiteAppHealthJob::dispatch($site->id)` **or** synchronous `inspect(live: false)` + optional live if cheap. Prefer `inspect(live: false)` inline for single-site Sync so UI is immediately correct; dispatch live inspect job for pack/env. |
| Bulk site Coolify sync | Same per site inside the job runner (live:false merge persist). |
| `CoolifyDeploymentSync::writeRemoteState` when status becomes Finished/Failed/Cancelled | Invalidate or patch stored payload’s `deploy_failed` (call a small `SiteAppHealthInspector::refreshLocalCached($site)`). |
| Webhook + PollDeploymentJob | Same via `writeRemoteState` / `applyExisting`. |

`refreshLocalCached`: load stored payload (or empty), replace local-owned issues, set `ok`, save `last_app_health_payload` + `last_app_health_at` without Coolify HTTP.

### 4. Deployment show / list UX

- Site detail Deployments table: highlight **latest** row (badge), not merely first in id order if sort differs.
- Failed historical rows remain listed; copy clarifies “latest deploy failed” vs “an older deploy failed”.
- App health issue message stays `deploy_failed` only when **latest** is failed.

### 5. Ordering / Coolify API

- Confirm `listAppDeployments` uses Coolify’s newest-first page; if not, sort client-side by started/finished before upsert accounting.
- When mapping remote status, keep `shouldKeepTerminal` (do not regress finished→queued).

### 6. Auto-deploy ↔ Coolify git deploy

Today `CoolifyDeploySettings::setAutoDeploy` already `PATCH`es `{ is_auto_deploy_enabled }`. Pin forces off; Follow HEAD forces on. Operators still ask whether “webhook deployları” follow Plane — meaning Coolify **application auto-deploy on git push**, not Plane’s notification webhook.

Wave 2 hardens this:

1. **After toggle:** re-`GET` the app (or trust PATCH response via `autoDeployState()`) and assert the flag matches the requested bool; if not, throw / flash error (do not silently show On while Coolify is Off).
2. **Persist last intent (optional small column or audit-only):** prefer audit `site.auto_deploy_updated` + live Coolify as source of truth for the chip. No second Plane-only boolean that can diverge unless we already store one — do not invent a shadow flag.
3. **Sync:** when filling the site from Coolify, refresh the Infrastructure auto-deploy chip from `autoDeployState()`. If an operator just set Plane and Coolify drifted (manual Coolify UI edit), chip shows Coolify truth; optional App health `auto_deploy_unknown` only when flag is missing (already “Unknown”).
4. **Docs / UI hint:** Infrastructure copy states that On/Off controls Coolify git auto-deploy (push → deploy). Plane `POST /webhooks/coolify` keeps receiving deploy status either way.
5. **Bulk auto-deploy:** same verify-after-write per site in the job runner.
6. **Live debug:** if PATCH appears to succeed but Coolify UI disagrees, use `ssh coolify` to inspect the application row — not a product dependency.

### Tests

- Cached payload has `deploy_failed`; latest deployment becomes `finished` → `forDisplay` has no `deploy_failed` without live Coolify.
- Site Sync after upserting a finished remote clears persisted `deploy_failed` via refreshLocalCached / inspect(live:false).
- Older failed + newer finished → issue absent; only latest failed → issue present.
- `latestOfMany` vs started_at: if an older-started row has higher id but earlier started_at than a finished row, health uses started_at rule (factory coverage).
- Poll/webhook path refreshing local cache unit/feature test with Http::fake.
- Auto-deploy On PATCHes `is_auto_deploy_enabled: true` and fails closed if subsequent GET reports false (Http::fake sequence).
- Auto-deploy Off same for `false`.
- Hint/docs assert notification webhook is out of scope for the toggle.

### Docs

- `docs/modules/ops-sites.md` — App health cache + sync refresh.
- `docs/modules/coolify-webhooks.md` — note cache refresh on terminal status.
- Progress ledger Wave 2.

## Acceptance

- After Sync (or poll/webhook finish), site App health does not show deploy failed when the latest Coolify-synced deploy is finished.
- Historical failed deployment pages remain accurate for that uuid.
- List App column updates after refresh without requiring a full live Coolify inspect for deploy-only changes.
- Plane auto-deploy On/Off updates Coolify `is_auto_deploy_enabled` and errors if Coolify does not reflect the change.
- UI/docs make clear this is git auto-deploy, not the Plane notification webhook.

## Handoff

Wave 1 bulk category counts for `redeploy` become correct once this merge lands; if Wave 1 ships first, counts may briefly over-count until Wave 2.

## Live debug

Stuck Coolify state: `ssh coolify` is allowed for implementers during this wave.
