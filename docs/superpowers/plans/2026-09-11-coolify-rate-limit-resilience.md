# Plan — Coolify / agent rate-limit resilience

**Spec:** [2026-09-11-coolify-rate-limit-resilience-design.md](../specs/2026-09-11-coolify-rate-limit-resilience-design.md)
**Date:** 2026-09-11
**Repo:** Deamon Plane only

## Task 1 — Config knobs

`config/ops.php`: add `coolify.rate`, `coolify.retry`, `agent.retry` blocks with env
overrides. No behavior yet.

## Task 2 — `CoolifyRateGuard`

`app/Services/Coolify/CoolifyRateGuard.php`, singleton in `AppServiceProvider`.

- `await(string $host): void` — sleep the later of (remaining spacing, active cooldown).
- `penalize(string $host, float $seconds): void` — clamp to `max_cooldown_ms`, store.
- `Illuminate\Support\Sleep` for every wait.

Tests: `tests/Unit/Coolify/CoolifyRateGuardTest.php` with `Sleep::fake()`.

## Task 3 — Retry loop in `CoolifyClient`

- Wrap the send in an attempt loop; resolve host from `CoolifyCredentials::apiRoot()`.
- Retry 429 for all methods; retry 5xx / `ConnectionException` for `GET` only.
- `Retry-After` (int seconds or HTTP-date) wins over exponential backoff + jitter.
- `Log::warning('Coolify API retry', ...)` per retry, no token.

Tests: extend `tests/Unit/Coolify/CoolifyClientTest.php`.

## Task 4 — Turkish rate-limit surfaces

- `CoolifyApiException::isRateLimited()`; `fromResponse()` localizes 429.
- `CoolifyClient` configuration errors use `coolify.errors.*`.
- `CoolifySiteSync` connection error uses `coolify.errors.not_configured`.
- Add `coolify.errors.*` to `lang/en` + `lang/tr`.

## Task 5 — Agent client 429

- `SiteAgentClient`: shared `dispatchWithRetry()` around health / theme / admin sends.
- `AgentHealthReason::RateLimited` + `AgentHealthResult` plumbing.
- `SiteHealthEvaluator` / `SiteAppHealthInspector`: a rate-limited probe is not
  `agent_unhealthy`.
- `ThemeAgentResult::fromCmsError` / `AdminAgentResult::fromCmsError` map 429 to
  `sites.agent.rate_limited`.

Tests: `tests/Unit/Agent/*`, `tests/Feature/Sites/*`.

## Task 6 — Ops job Turkish sweep

- `ops.bulk.result` / `ops.bulk.result_failed` in `lang/en` + `lang/tr`.
- `OpsJobRunner`: replace every `' ok'` / `', failed'` concatenation.

Tests: `tests/Feature/Ops/OpsJobRunner*` (or add one) asserting no English fragments.

## Task 7 — Docs + ledger

`docs/modules/coolify-client.md`, `docs/modules/agent-client.md`,
`docs/plans/progress-ledger.md`.

## Task 8 — Verify

`php artisan test`, `vendor/bin/pint --dirty`, then commit + push.

## Ordering

1 → 2 → 3 → 4 in sequence (each depends on the previous). 5 and 6 are independent of
2–4 and may run in parallel. 7 and 8 last.
