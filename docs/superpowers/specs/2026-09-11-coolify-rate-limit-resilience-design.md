# Coolify / agent rate-limit resilience (429 "Too Many Attempts.")

**Date:** 2026-09-11
**Status:** draft
**Repo:** Deamon Plane only
**Order:** Wave 4 (after bulk App health fixes, deploy-status sync, domain registry)

## Context

Bulk ops on the Sites list fan out one HTTP call per site with **no spacing**:

| Op | Coolify calls per site |
|----|------------------------|
| `sites.coolify_sync` | `GET /applications/{uuid}` + `GET /deployments/applications/{uuid}` + domain reconcile |
| `sites.bulk_app_health_fix` | inspect (`GET app`, `GET envs`) + fix (`PATCH`, `POST /deploy`) + re-inspect |
| `sites.bulk_auto_deploy` | `snapshot` (`GET app`) then `PATCH` then verify `GET app` |
| `sites.bulk_deploy` / `bulk_pin` / `bulk_follow_head` | env sync (`GET envs` + `PATCH envs/bulk`) then `POST /deploy` |

Coolify's API is Laravel with `throttle` middleware, so a fleet-wide sweep trips
`429 Too Many Attempts.`. Today `CoolifyClient::decode()` turns any failure into
`CoolifyApiException` with the **raw remote message**, which is how the English
string `Too Many Attempts.` reaches Turkish flash messages, App health rows,
`deployments.error_message`, and the ops job drawer.

Same class of failure exists on the CMS site agent (`SiteAgentClient`): the CMS is
also Laravel, its `/internal/control/v1/*` routes are throttled, and a theme
rollout or bulk health check hits many sites in a row.

Nothing in the repo currently reads `Retry-After`, retries a 429, or paces calls:
grep for `429`, `Retry-After`, `RateLimit` matches only `HostingerMailException`.

## Goals

- A 429 from Coolify or a CMS agent is **absorbed** (retried after the advertised
  delay) instead of surfacing as a failed site row, when a retry can still succeed.
- Bulk loops **self-pace**, so a 30-site sweep does not burst 90 requests instantly.
- After one site is rate limited, the **rest of the same sweep** backs off too
  rather than each site independently discovering the limit.
- Any rate-limit error that does reach the operator is **Turkish** and actionable.
  No raw `Too Many Attempts.` in flash, App health, or deployment error text.
- Retries must never turn one operator click into **two deploys**.
- Everything stays `Http::fake`-testable; no real sleeping in CI.

## Non-goals

- A distributed/cross-worker rate limiter (Redis token bucket). Plane runs a single
  queue worker; an in-process guard plus retry is enough for v1.
- Changing Coolify's own throttle configuration or the CMS's route throttles.
- Queue-level `backoff()` / `tries` redesign for `PollDeploymentJob`.
- Cloudflare, GitHub, and Hostinger clients (different limits, separate wave).

## Approaches considered

| Approach | Pros | Cons |
|----------|------|------|
| A. Per-call `Http::retry()` on the Laravel PendingRequest | One line | Retries every method incl. `POST /deploy`; ignores cross-site cooldown; no Turkish mapping |
| B. **Retry loop + shared rate guard in `CoolifyClient::request` / `SiteAgentClient`** (recommended) | Central, method-aware, shares cooldown across a bulk sweep, one place to localize | New small class to own and fake |
| C. Chunk bulk jobs and `sleep()` between chunks in `OpsJobRunner` | Simple | Only helps bulk paths; single-site clicks and nested calls still burst; guesses at the limit |

**Recommendation:** B. It covers every caller (bulk, single-site, webhook, poll,
console commands) because they all funnel through the two clients.

## Design

### 1. `CoolifyRateGuard`

New `App\Services\Coolify\CoolifyRateGuard`, bound as a container **singleton** so
one bulk job shares it across sites.

```php
$guard->await($host);                 // spacing + active cooldown, before send
$guard->penalize($host, $seconds);    // after a 429, for every later call
```

- **Spacing:** remembers the last send time per host and sleeps the remainder of
  `ops.coolify.rate.min_interval_ms` (default 120 ms). At ~8 req/s a 30-site sync
  stays under a typical `throttle:60,1`.
- **Cooldown:** a 429 sets `cooldownUntil = now + retryAfter`. Every later `await()`
  for that host waits it out, so sites 5..30 of a sweep do not each re-trip the limit.
- Sleeps through `Illuminate\Support\Sleep`, so tests use `Sleep::fake()` and assert
  the durations instead of actually waiting.
- Host key is the credential base URL, so multiple Coolify connections are throttled
  independently.

### 2. Retry loop in `CoolifyClient::request`

```
attempt 1..max_attempts:
    guard->await(host)
    send
    on 429                          -> penalize(host, retryAfter); retry
    on 5xx / ConnectionException    -> retry only when the method is idempotent
    otherwise                       -> return / throw as today
```

- **Idempotence rule:** `GET` retries on 5xx and connection errors. `POST`, `PATCH`,
  and `DELETE` do **not** — a `POST /deploy` that timed out may already have started
  a build, and retrying would double-deploy. A `429` is safe to retry for every
  method because the request was rejected by middleware before the controller ran.
- **Delay:** `Retry-After` when present (seconds or HTTP-date), else exponential
  `base_delay_ms * 2^(attempt-1)` with jitter, clamped to `max_delay_ms`.
- Exhausted retries throw the normal `CoolifyApiException`, so every existing
  `catch (CoolifyApiException)` in bulk runners, `SiteAppHealthInspector`, and
  `CoolifyDeploySettings` keeps working — sites degrade one by one, not the sweep.
- One `Log::warning` per retry with host, method, path, status, delay. No token.

### 3. Turkish rate-limit text

`CoolifyApiException`:

- `isRateLimited()` — `status === 429`.
- `fromResponse()` replaces the remote body message with
  `__('coolify.errors.rate_limited')` when the status is 429, so the raw
  `Too Many Attempts.` never leaves the client. The raw body stays in `payload`
  for logs and the pasteable deployment dump.

`SiteAgentClient` results:

- `ThemeAgentResult::fromCmsError` and `AdminAgentResult::fromCmsError` map 429 to
  `__('sites.agent.rate_limited')`.
- `AgentHealthResult` gains reason `AgentHealthReason::RateLimited` so App health
  can distinguish "CMS throttled us" from "agent is down" — a throttled probe must
  not flag the site `agent_unhealthy`.

New keys (en + tr):

- `coolify.errors.rate_limited`, `coolify.errors.unreachable`, `coolify.errors.not_configured`
- `sites.agent.rate_limited`, `sites.agent.timeout`, `sites.agent.failed`
- `ops.bulk.result` / `ops.bulk.result_failed` for the job runner summary

### 4. Agent-side retry

`SiteAgentClient` gets the same treatment through a small private `send()` helper:
respect `Retry-After` on 429, retry up to `ops.agent.retry.max_attempts` (default 2),
`GET` only for 5xx. Per-site spacing is unnecessary — each site is a different host —
but a 429 retry still matters for theme rollout, which hits one CMS repeatedly.

### 5. Config

```php
'coolify' => [
    'rate' => ['min_interval_ms' => 120, 'max_cooldown_ms' => 30000],
    'retry' => ['max_attempts' => 3, 'base_delay_ms' => 500, 'max_delay_ms' => 8000],
],
'agent' => [
    'retry' => ['max_attempts' => 2, 'base_delay_ms' => 400, 'max_delay_ms' => 5000],
],
```

All env-overridable so a noisy Coolify instance can be slowed without a deploy.

### 6. Ops job runner Turkish sweep

`OpsJobRunner` currently concatenates `' ok'` / `', failed'` onto translated
prefixes, so a Turkish operator reads `Tekrar deploy: 3 ok, 1 failed`. Replace with
`__('ops.bulk.result')` / `__('ops.bulk.result_failed')`.
`CoolifySiteSync`'s hardcoded `'Coolify connection is not configured.'` and
`CoolifyClient`'s configuration errors move to `coolify.errors.*`.

## Tests

- `CoolifyClient` retries a 429 and succeeds on the second response; `Sleep` asserts
  the `Retry-After` delay was honored.
- 429 on every attempt throws `CoolifyApiException` whose message is the Turkish
  string, not `Too Many Attempts.`, and `isRateLimited()` is true.
- `POST /deploy` returning 500 is **not** retried (exactly one request sent).
- `GET /applications` returning 503 then 200 is retried.
- `CoolifyRateGuard` spaces two consecutive calls by `min_interval_ms` and applies a
  cooldown to the next call after `penalize()`.
- Bulk Coolify sync over two sites where site 1 gets 429: site 2's call waits for the
  cooldown, and both sites end up synced.
- `ThemeAgentResult::fromCmsError(null, 429)` and `AdminAgentResult` return Turkish.
- Agent health 429 yields `AgentHealthReason::RateLimited`, and
  `SiteAppHealthInspector` does not add `agent_unhealthy` for it.
- `OpsJobRunner` bulk summaries contain no English `ok` / `failed` fragments.

## Docs

- `docs/modules/coolify-client.md` — retry, pacing, idempotence rule, config knobs.
- `docs/modules/agent-client.md` — 429 handling and the new health reason.
- `docs/plans/progress-ledger.md` — Wave 4 entry.

## Acceptance

- A 30-site Coolify sync or bulk App health fix completes without a wall of
  rate-limit failures on a `throttle:60,1` Coolify.
- No operator-facing surface renders `Too Many Attempts.`.
- No bulk action produces duplicate Coolify deployments.
- `php artisan test` green.

## Risks

- Pacing makes large sweeps slower by design (30 sites x 3 calls x 120 ms ≈ 11 s of
  added wall clock). Acceptable for a queued background job; tune via env.
- The guard is per-process, so two concurrent queue workers could still trip the
  limit. Retry + cooldown then absorbs it; a shared cache-backed guard is the
  follow-up if Plane ever scales the worker pool.
