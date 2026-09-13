# ADR-10 · Health / app-issue list filters need a persisted verdict

**Status:** accepted (2026-09-13) and **implemented** the same night. Do not implement a fleet scan to close this.

## Context

P1-7 shipped `/sites?deploy=failed` because failure is a row in `deployments`. The same item also asked for `health=unhealthy` and `app=issues` so the fleet unhealthy card and the Sites **Fix App issues** menu could drill down the way failed deploys now do.

Those two verdicts used to be PHP only:

- `SiteHealthEvaluator::countsAsUnhealthy()` walks stored agent payloads (`status`, stale window, missing secret).
- `SiteAppHealthFixer::neededFixes()` walks the same payloads plus compose / env / domain facts.

P0-2 took that walk off the Sites keystroke path. A list filter that re-ran it on every `q` / page / sort would put it back.

## Decision

Do **not** filter `/sites` by evaluating every site in PHP.

`health=` / `app=` exist only as **SQL** over a **persisted verdict** written on the same save as the payload:

- `sites.health_unhealthy` + `health_verdict_at` — stamped by `SiteHealthChecker::check()` and any other save that dirties status / last health / agent secret (`SiteFilterVerdict::applyHealth`).
- `sites.app_has_issues` + `app_health_issue_count` + `app_health_verdict_at` — stamped by `SiteAppHealthInspector::inspect()` / `refreshLocalCached()` (the path `InspectSiteAppHealthJob` already calls) and by health writes (`SiteFilterVerdict::applyApp`).

`Site::scopeUnhealthy()` is the single predicate the fleet card and `/sites?health=unhealthy` share: the column, `status=error`, stored agent-fail JSON, or the stale window. Stale is time-based, so it cannot live in the column alone. `app=issues` is `app_has_issues = 1`.

Unknown query values are dropped, never applied as a literal.

## Invalidation

Write the verdict in the same place the payload is written. A failed inspect still changes the site, so it must refresh the column (P0-2 already forgets the count cache on every `fix()`). Do not add a second fleet walk to “catch up” the column on dashboard render.

## Consequences

- The operator can click the unhealthy number and land on that set. The `+N site daha` line and **Fix App issues → Sorunlu siteleri gör** do the same for health / app.
- Cost: one migration + one write on the existing inspect / health path. Done.
- The fleet dashboard toolbar (P1-14) may still search attention cards in memory. That is not a Sites filter and must not become one.

Tests: `HealthAppFilterTest`.
