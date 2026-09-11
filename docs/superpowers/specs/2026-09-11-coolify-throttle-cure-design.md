# Curing "Too Many Attempts." — poll storms, bulk pacing, queue discipline

**Date:** 2026-09-11
**Status:** draft — supersedes the client-only part of
[2026-09-11-coolify-rate-limit-resilience-design.md](2026-09-11-coolify-rate-limit-resilience-design.md)
**Repo:** Deamon Plane only

## Field evidence

After one bulk **Commit pin** over ~23 sites, the panel showed:

- `manuel · kuyrukta` (queued) on izyem, Lökçe Tekstil, ltscanta, makermak.
- English **Too Many Attempts.** on Meyyit, Deamon Test, Deamon Landing Page,
  Basut Silo, adanapelet, Demo Basut, curtis.tr, beyazlar, Demo Bizim Usta,
  beyazogluotomotiv, Bizim Usta Car Service, Echo Door, doremanmedya,
  cinarotokurtarici, CodRon.

The operator's read — "some deploys actually started" — is correct, and the split
between the two groups is the tell.

## Root cause: two separate bursts, one of which corrupts state

### Burst 1 — the bulk loop (annoying)

`OpsJobRunner::bulkPin` iterates sites in a tight loop. Each site costs
`PATCH /applications/{uuid}` + `GET envs` + often `PATCH envs/bulk` + `POST /deploy`
— roughly 3–4 requests with **zero spacing**. Twenty-three sites is ~80 requests in a
couple of seconds against Coolify's Laravel `throttle` middleware. The first N sites
succeed and get a deployment row; the rest throw `CoolifyApiException('Too Many
Attempts.')`. `applyMany()` collects those into `errors`, and `bulkPin` then
**discards `errors`** and reports only `'Pin: 19 ok'`. That is the misleading
partial-success summary.

### Burst 2 — the poll storm (the real damage)

Every site that *did* deploy dispatches `PollDeploymentJob`, which re-dispatches
itself every `poll_seconds` (15) with **no jitter**. Nineteen pollers created within
the same second stay phase-locked forever, so every 15 s Coolify receives 19
simultaneous `GET /deployments/{uuid}` calls. That alone re-trips the throttle.

Then the fatal line — `PollDeploymentJob::handle()` catches `CoolifyApiException`
and goes straight to `failOpen()`, which calls
`CoolifyDeploymentSync::failWithoutSiteChange($deployment, 'Too Many Attempts.')`.

**A transient 429 on a status read is written into `deployments.status = failed`
and `deployments.error_message = 'Too Many Attempts.'`** — while the build is still
running happily inside Coolify. That is exactly the English string on the panel, and
it explains why healthy deploys look failed.

So: burst 1 loses work, burst 2 **fabricates failures**. Fixing pacing alone would
not have prevented the bad rows.

## Answers to the operator's two questions

**1. How do we permanently get off "Too Many Attempts."?**
By never treating a throttle response as a deploy outcome, and by making Plane's
outbound call rate a property of the client rather than of how fast a loop runs.
Four layers below; layer 1 stops the false failures, layer 2 stops most 429s from
happening at all, layer 3 stops two bulk jobs from stacking, layer 4 makes anything
that still leaks through Turkish and honest.

**2. Is there a smarter approach than hammering the HTTP API?**
Yes, and it is mostly about *not asking*:

- **Webhooks already tell us.** `POST /webhooks/coolify` calls
  `CoolifyDeploymentSync::applyExisting()` with the terminal status. Polling exists
  only as a fallback for instances whose notification webhook is not wired. So the
  poller should **exit immediately when the local row is already terminal** (the
  webhook won the race) and should back off geometrically rather than hitting a
  fixed 15 s forever.
- **Coolify has no bulk/batch endpoint** for deploy or application reads, so a
  fan-in query is not available; the lever we do have is call rate and call count.
- **Deployment rows are the cache.** Bulk App health and the Sites list already read
  local `deployments`; they should not re-derive status from Coolify per render.

A distributed token bucket is not warranted: Plane runs a single queue worker, so an
in-process guard plus a shared cooldown covers it.

## Design

### Layer 1 — `PollDeploymentJob` must not fail on transient errors

- Add `CoolifyApiException::isTransient()` — 429, 502/503/504, or status 0.
- New constructor arg `transientAttempt`. On a transient exception, **reschedule**
  instead of failing, up to `ops.provision.poll_transient_max_attempts` (default 8),
  delaying by the Coolify cooldown (from `Retry-After`) or exponential backoff.
- Only a **non-transient** Coolify error (404, 422, auth) still fails the row.
- After the transient budget is exhausted, fail with Turkish
  `coolify.errors.rate_limited_deploy`, never the raw remote string.
- **Early exit** when the local row is already terminal — the webhook resolved it.
- **Jitter** the normal poll delay by ±40% so sibling pollers de-phase after one
  cycle instead of staying locked together.
- Poll delay grows with attempt count (15 s → capped at
  `poll_max_seconds`, default 60) so long builds cost far fewer requests.

### Layer 2 — pace and retry every Coolify call

`CoolifyRateGuard` (container singleton, `Illuminate\Support\Sleep`):

- `await($host)` — enforce `ops.coolify.rate.min_interval_ms` (default 120 ms)
  between calls to one Coolify host, and wait out any active cooldown.
- `penalize($host, $seconds)` — a 429 anywhere puts the **whole host** on cooldown,
  so the remaining sites in a sweep wait rather than each rediscovering the limit.

`CoolifyClient::request()` retries inside that guard:

- 429 retries for **every** method (middleware rejected it before the controller, so
  nothing happened server-side).
- 5xx / connection errors retry for **GET only** — retrying `POST /deploy` could
  start a second build. This is the rule that keeps "no duplicate deploys" true.
- `Retry-After` beats exponential backoff; both clamp to `max_delay_ms`.

### Layer 3 — one Coolify-touching bulk job at a time

`ProcessOpsBackgroundJob` takes a `Cache::lock('ops:coolify-bulk')` for job types
that talk to Coolify. If the lock is held, the job **re-dispatches itself** with a
short delay instead of running concurrently. Bulk ops therefore serialize per Plane
instance, which combined with layer 2 gives a predictable ceiling of roughly
`1000 / min_interval_ms` requests per second regardless of how many buttons an
operator mashes.

### Layer 4 — Turkish copy and honest summaries

- `CoolifyApiException::fromResponse()` localizes 429 to
  `coolify.errors.rate_limited`; the raw body stays in `payload` for logs.
- `ThemeAgentResult` / `AdminAgentResult` map 429 to `sites.agent.rate_limited`;
  agent health 429 becomes `AgentHealthReason::RateLimited` with status `unknown`,
  so a throttled probe no longer marks a site `agent_unhealthy`.
- `OpsJobRunner` stops concatenating `' ok'` / `', failed'`; summaries use
  `ops.bulk.result` / `ops.bulk.result_failed`, and a sweep that hit a throttle
  appends `ops.bulk.rate_limited` telling the operator the rest were skipped, not
  broken. `'Pin: 19 ok'` becomes `'Commit pin: 19 tamam, 4 hata — Coolify istek
  sınırı…'`.

### Layer 5 — heal the rows already poisoned

`php artisan ops:heal-throttled-deploys` finds deployments marked `failed` whose
`error_message` matches a throttle marker (raw `Too Many Attempts` or the Turkish
string), re-reads each one from Coolify through the paced client, and rewrites the
true status — recovering `sites.status` via the existing
`recoverSiteIfLatestFinished()`. `--dry-run` lists without writing.

## Tests

- Poll job: 429 reschedules and leaves status untouched; 404 still fails;
  transient budget exhaustion fails with Turkish text; already-terminal row exits
  without an HTTP call; delay is jittered and grows.
- Client: 429 then 200 succeeds honoring `Retry-After`; 429 everywhere throws
  Turkish + `isRateLimited()`; `POST /deploy` 500 is sent exactly once; `GET` 503
  retries.
- Guard: spacing between two calls; cooldown applies to the next call.
- `ProcessOpsBackgroundJob` re-dispatches when the lock is held.
- Ops summaries carry no English `ok` / `failed`.
- Heal command flips a throttle-failed row to finished from a faked Coolify.

## Acceptance

- A bulk pin/deploy over 25 sites produces no `failed` rows caused by throttling.
- No operator surface renders `Too Many Attempts.`.
- No duplicate Coolify deployments from retries.
- Existing poisoned rows are recoverable with one command.

## Operator note (stuck rows from the incident)

1. Nothing needs to be re-pinned. The sites showing **Too Many Attempts.** were
   *status-read* failures; Coolify very likely finished those builds.
2. Run `php artisan ops:heal-throttled-deploys --dry-run` to list affected rows,
   then without the flag to rewrite them from Coolify truth.
3. Sites left at `manuel · kuyrukta` whose Coolify build already ended are healed by
   the same command (or by **Sync** on the site, which now paces itself).
4. Only re-run a bulk action for sites the summary reports as `hata` after healing.
