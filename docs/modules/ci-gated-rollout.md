# CI-gated rollout (CMS fleet + themes)

Goal: a red CI never reaches a customer site. A CMS channel push or a theme push deploys only after GitHub's `CI` workflow is green **for that exact commit**. Plane is the orchestrator: Coolify secrets stay in Plane, nothing Coolify-related goes into GitHub.

Plane itself is gated by its own workflow ([../runbooks/ci-cd-plane.md](../runbooks/ci-cd-plane.md)). This page is about **customer sites** and **themes**.

## Signal

`POST /webhooks/github` (same endpoint, HMAC and delivery dedupe as push — [github-webhooks.md](github-webhooks.md)), event `workflow_run`:

| Field | Rule |
|-------|------|
| `action` | `completed` only |
| `workflow_run.name` | `config('ops.ci.workflow_name')` (`CI`) — other workflows are ignored |
| `workflow_run.event` | `push` only (a PR / dispatch / schedule run does not vouch for a branch head) |
| `workflow_run.head_branch`, `head_sha`, `conclusion` | the commit and its verdict |
| `repository.full_name` | CMS repo (`DeamonRepo::fullName()`) or a catalog theme repo |

Only `conclusion = success` promotes. Anything else is recorded and audited; nothing deploys.

## "Is this still the head?" (no GitHub API)

`ci_branch_heads` (`CiBranchHeads`) keeps, per `repo_full_name` (lower-case) + `branch`: `head_sha`, `pushed_at`, and the last `ci_status` / `ci_sha` / `ci_at`.

- Every CMS push to a channel branch (`main` / `beta` / `alpha`) records the head (the same push that refreshes the env catalog).
- Every theme push to its default ref records the head (with or without the theme gate).
- A green run promotes only when `head_sha == workflow_run.head_sha`.

| Verdict (`CiRunVerdict`) | When | Plane does | Audit |
|--------------------------|------|------------|-------|
| `promote` | green, commit is the recorded head | CMS: start rollout · theme: move catalog + fan out | `fleet_rollout.started` / `theme.ci_promoted` |
| `red` | failure, cancelled, timed_out, … | nothing | `ci.run_failed` (on the branch head) / `theme.ci_failed` |
| `superseded` | green, a newer push moved the branch | nothing (the newer push has its own run) | `ci.run_superseded` / `theme.ci_held` |
| `head_unknown` | green, Plane never saw a push for the branch | nothing (cannot prove the commit is the head) | `ci.run_head_unknown` / `theme.ci_held` |

A late verdict for an older commit never overwrites the head's own verdict on the row.

## CMS fleet

### Deploy gate per site

`sites.deploy_gate`:

- `coolify` (default, every existing row): Coolify auto-deploys every push — CI is not consulted.
- `ci`: Coolify auto-deploy **off**, app follows **HEAD** (`git_commit_sha: HEAD`); Plane deploys after a green run.

`CoolifyDeploySettings::setDeployGate` does the switch (PATCH + verify, mirror columns, audit `site.deploy_gate_updated`). It never deploys. Switching to `ci` clears a pin (the app follows HEAD). While a site is on `ci`:

- turning auto-deploy on is refused (`rollouts.gate.auto_deploy_blocked`) — go back through the gate;
- **Deploy HEAD** (follow HEAD) keeps auto-deploy off;
- **Pin commit** still works (auto-deploy off); a pinned site is skipped by every rollout until it follows HEAD again.

`sites.deploy_canary` (default false) marks canary sites.

UI: site detail → Coolify panel → **Deploy kapısı** card (gate switch + canary toggle). Sites list bulk bar → **Oto-deploy** menu → **CI kapısına al** / **Coolify oto-deploy'a dön** (`POST /sites/bulk/deploy-gate`, ops job `sites.bulk_deploy_gate`, same per-site `update` policy as bulk auto-deploy).

### Rollout (`fleet_rollouts`, `FleetRolloutService`, `AdvanceFleetRolloutJob`)

One row per channel + green commit: `status` `canary` → `fanout` → `done`, or `halted` / `superseded`; `summary` JSON holds per-stage site ids, deployment ids, healthy / failed / skipped lists and errors; `halted_reason`, `halted_by`.

Eligible site: channel matches, `deploy_gate = ci`, `status = active`, has a Coolify app, **not pinned** (`coolify_pinned_sha` empty). `coolify`-gated, pinned and inactive sites are never touched.

1. **Start.** A promoted CMS run supersedes any open rollout of that channel, then creates the rollout (skipped when the channel has no eligible site; a re-run of the same commit starts nothing).
2. **Canary.** Every eligible site with `deploy_canary = true` gets `CoolifyDeploySettings::deployForRollout` — `POST /deploy` **without** `force` (build cache on), trigger `ci_rollout`, a `deployments` row + `PollDeploymentJob`, audit `site.ci_rollout_deployed`. Each tick waits until every canary deployment is terminal (poll / Coolify webhook) and the site passes health:
   - with an agent secret: a fresh `SiteHealthChecker::check` (the same signed health the Sites list and the fleet unhealthy card use) must be `ok` and the site not `error`;
   - without a secret: the Live Sync homepage probe must answer 2xx/3xx.
   A failed / cancelled canary build, a canary deploy request error, health failing `ops.ci.canary_health_attempts` times in a row, or `ops.ci.canary_timeout_minutes` (30) → `halted` with the site names; audit `fleet_rollout.canary_failed` on the rollout and `site.ci_rollout_canary_failed` on each site. Nothing else deploys. No canary → straight to fan-out.
3. **Fan-out.** The remaining eligible sites of the channel, `ops.ci.fanout_batch` (20) per tick, through `PacedFanout` (throttle wait-outs, per-host deploy gate). A host still building someone else's deploy leaves the site pending for the next tick. A failed deploy request is recorded, not a halt. Empty pending list → `done` (`fleet_rollout.done` with counts).
4. **Every tick and before every deploy call**: the rollout's commit must still be the branch head (Coolify builds HEAD, so after a newer push a deploy would build an untested commit) → otherwise `superseded`; the site must still qualify (else `skipped`); an operator halt that lands mid-sweep stops the sweep.

`AdvanceFleetRolloutJob` re-queues itself every `ops.ci.poll_seconds` (30) while a stage waits. It is unique until processing and does not overlap itself. `KickStalledFleetRolloutsJob` (scheduler, every 5 minutes) gives an open rollout that nothing advanced for `ops.ci.stall_minutes` a new tick (worker restart, Plane deploy).

Residual risk (same as Plane's own CI deploy): a push that lands between the head check and Coolify's clone is built before its own CI finishes. The next tick sees the moved head and stops the rollout; the newer commit's own green run rolls it out properly.

### Halt / resume

`/rollouts` (nav **CI yayınları**) lists rollouts (branch, commit, status, canary and fan-out counts, started / finished, halt reason) and the branch heads with their last CI verdict. `/rollouts/{id}` shows canary and fan-out site states.

| Action | Route | Gate |
|--------|-------|------|
| Halt an open rollout | `POST /rollouts/{id}/halt` | `ops.write` (operator) — stopping must never wait for a Super Admin |
| Resume fan-out of a halted rollout | `POST /rollouts/{id}/resume` | `ops.danger` (Super Admin) — it overrides a failed canary for the whole channel |

Both use the danger confirm modal and are audited (`fleet_rollout.halted`, `fleet_rollout.resumed`). Resume is refused once the branch moved on (the newer push rolls out with its own run). Resume goes to the fan-out stage; canary sites are not deployed again.

## Themes

`themes.ci_gate` (default false = unchanged: a push to the default ref moves `latest_sha` and fans out at once).

With `ci_gate = true`:

- a push to the default ref only records the branch head; `latest_sha` and installations stay;
- the green `CI` run for the head commit on the default ref sets `latest_sha`, `last_synced_at` and runs the same fan-out as a plain push (`ThemeWebhookFanout`: `auto_update` + Active/Error only, ref skip, `fanout_concurrency` waves, 200 cap, CMS version skip) — audit `theme.ci_promoted`;
- red / superseded / unknown-head runs are audited on the theme (`theme.ci_failed`, `theme.ci_held`).

Theme repos without a workflow named `CI` never send a `workflow_run`, so a gated theme would never update. **Only enable `ci_gate` after the theme repo has a GitHub Actions workflow named `CI` that runs on push to the default branch.** UI: theme detail → Sync tab → **CI yeşil olunca güncelle**.

## Config (`config/ops.php` → `ci`, no env keys)

| Key | Default | Meaning |
|-----|---------|---------|
| `workflow_name` | `CI` | the workflow whose verdict gates |
| `canary_timeout_minutes` | 30 | canary stage deadline |
| `canary_health_attempts` | 3 | consecutive failed health checks of a finished canary before halting |
| `poll_seconds` | 30 | delay between rollout ticks while waiting |
| `fanout_batch` | 20 | sites asked to deploy per fan-out tick |
| `stall_minutes` | 5 | watchdog threshold |

ADR-12: no customer env key is added; the gate is Plane + Coolify state only.

## Operator steps (order matters)

1. Deploy this Plane version (migrations are additive; every site stays `coolify`, every theme `ci_gate = false`).
2. Subscribe the GitHub App to **Workflow runs** — GitHub → Settings → Developer settings → GitHub Apps → the Plane App → **Permissions & events**: Repository permissions → **Actions: Read-only**; Subscribe to events → **Workflow run** → Save. Each installation (org / user) then has to **accept the new permission** (GitHub → Settings → Applications → Installed GitHub Apps → Review request). New Apps created from the manifest already ask for both. A PAT-only / org webhook setup: add **Workflow runs** to that webhook's events. Check under the App's **Advanced → Recent deliveries** that a `workflow_run` delivery gets `200`.
3. Push to a CMS channel branch once (or wait for the next push) so `/rollouts` → **Dal başları** shows a head and its CI verdict.
4. Mark one or two low-traffic sites per channel as **canary** (site detail → Deploy kapısı → Kanarya yap).
5. Switch the sites to the **CI gate** (Sites → select → Oto-deploy → CI kapısına al). The canaries first, then the rest. Nothing deploys during the switch.
6. Verify one rollout: push a commit to the channel branch, wait for CI green, watch `/rollouts` go canary → fan-out → done. A red CI should show `CI kırmızı: commit bekletildi` in Activity and no rollout.
7. Themes: enable **CI yeşil olunca güncelle** only for theme repos that have a `CI` workflow.

Rollback: switch the sites back to **Coolify oto-deploy** (bulk). Open rollouts stop at the next tick because their sites no longer qualify.

## Tests

`GitHubWorkflowRunTest` (signal, verdicts, dedupe, theme gate), `FleetRolloutTest` (canary pass → fan-out, canary build / health failure, timeout, supersede, batches, untouched pinned / coolify / other-channel sites, job + watchdog), `CiGateControlsTest` (gate + canary switches, bulk gate, rollouts pages, halt / resume roles, theme checkbox).
