# Sync After Deploy Host Guard — Plane Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Never run Plane theme sync against a site with an open Coolify deploy; cap concurrent Coolify builds at 1 per server; give Plane compose hard memory limits.

**Architecture:** Pending-sync flag on installation + `ThemeSyncAfterDeployJob` fired from deploy poll success; `CoolifyDeployGate` before deploy; compose resource blocks.

**Tech Stack:** Laravel 12, PHPUnit, Docker Compose.

**Spec:** `docs/superpowers/specs/2026-09-12-sync-after-deploy-host-guard-design.md`  
**Companion (CMS):** `../deamon/docs/superpowers/plans/2026-09-12-deploy-xml-host-guard.md`

## Global Constraints

- Sync still means agent `POST .../themes/sync` (queues CMS background task) — do not change CMS contract.
- Failed/cancelled deploy clears pending sync; does not auto-sync.
- `ops.coolify.deploy.max_concurrent_per_server` default `1`.

## File map

| File | Responsibility |
|------|----------------|
| migration `pending_sync_after_deploy` on `site_theme_installations` | persistence |
| `app/Services/Themes/ThemeRolloutService.php` | defer sync |
| `app/Jobs/ThemeSyncAfterDeployJob.php` | run deferred sync |
| `app/Jobs/PollDeploymentJob.php` / `CoolifyDeploymentSync.php` | trigger on success |
| `app/Services/Coolify/CoolifyDeployGate.php` | concurrency gate |
| `app/Services/Sites/CoolifyDeploySettings.php` | call gate |
| `config/ops.php` | max_concurrent_per_server |
| `docker-compose.coolify.yml` | mem limits |
| `tests/Feature/Themes/ThemeSyncAfterDeployTest.php` | defer + fire |
| `tests/Feature/Coolify/CoolifyDeployGateTest.php` | concurrency |
| `docs/modules/theme-agent-client.md` or runbook | operator note |

---

### Task 1: Pending sync column + defer in ThemeRolloutService

**Files:**
- Create: migration
- Modify: `SiteThemeInstallation` model fillable/casts
- Modify: `ThemeRolloutService::syncNow` + sync-after-install path
- Test: `tests/Feature/Themes/ThemeSyncAfterDeployTest.php`

**Interfaces:**
- Produces: `SiteThemeInstallation::$pending_sync_after_deploy` bool
- Produces: `ThemeRolloutService::requestSync(...): 'ran'|'deferred'` (or void + flash key)

- [ ] **Step 1: Failing test**

```php
public function test_sync_defers_when_site_has_open_deployment(): void
{
    // site + installation + Deployment with finished_at null
    // Http::fake agent should NOT be called
    // syncNow → installation.pending_sync_after_deploy true
}
```

- [ ] **Step 2: Migration + model + defer logic**

If open deployment:

```php
$installation->pending_sync_after_deploy = true;
$installation->save();
return; // or throw ThemeRolloutException with deferred message only if API needs hard fail — prefer soft defer + flash
```

Else existing `syncWithDataRepair`.

- [ ] **Step 3: Tests pass + commit**

```bash
git commit -m "$(cat <<'EOF'
feat: defer theme sync while Coolify deploy is open

Avoid stacking CMS theme sync on a site still finishing deploy.
EOF
)"
```

---

### Task 2: ThemeSyncAfterDeployJob + poll success hook

**Files:**
- Create: `app/Jobs/ThemeSyncAfterDeployJob.php`
- Modify: `CoolifyDeploymentSync` or `PollDeploymentJob` success path
- Extend: `ThemeSyncAfterDeployTest`

**Interfaces:**
- On deployment success for site_id: if any installation `pending_sync_after_deploy`, clear flag and call `ThemeRolloutService` sync (force run, skip defer if no other open deploys)
- On failure/cancel: clear `pending_sync_after_deploy` without syncing

- [ ] **Step 1: Failing test — finish deploy fires sync once**

- [ ] **Step 2: Implement job + hook**

- [ ] **Step 3: Commit**

```bash
git commit -m "$(cat <<'EOF'
feat: run deferred theme sync after Coolify deploy finishes
EOF
)"
```

---

### Task 3: CoolifyDeployGate concurrency = 1

**Files:**
- Create: `app/Services/Coolify/CoolifyDeployGate.php`
- Modify: `CoolifyDeploySettings` / `CoolifyApplicationService::deploy`
- Config: `ops.coolify.deploy.max_concurrent_per_server`
- Test: `tests/Feature/Coolify/CoolifyDeployGateTest.php`

**Interfaces:**
- `assertCanStartDeploy(Site $site): void` throws retryable exception if another unfinished deployment exists for same coolify connection
- Bulk path already serial; gate still protects overlapping single-site redeploys

- [ ] **Step 1–4: TDD + commit**

```bash
git commit -m "$(cat <<'EOF'
feat: limit Coolify builds to one per server connection

Prevent parallel compose builds from starving shared VPS memory.
EOF
)"
```

---

### Task 4: Plane compose limits + docs

**Files:**
- Modify: `docker-compose.coolify.yml` / `docker-compose.yml`
- Docs: short note in `docs/modules/deployment.md` or `docs/runbooks/`

**Limits:** app 768m / mysql 768m + innodb command / redis 128m (same family as CMS).

- [ ] **Step 1: Edit compose**
- [ ] **Step 2: Commit**

```bash
git commit -m "$(cat <<'EOF'
chore: add Plane compose mem/cpu/pids limits

Keep Plane MySQL from unbounded growth on the shared Coolify host.
EOF
)"
```

---

### Task 5: Push Plane + redeploy + SSH verify

- [ ] Push, redeploy Plane app, `docker inspect` Memory limits, smoke deferred-sync path in staging if available.

---

## Spec coverage

| Spec | Task |
|------|------|
| Defer sync while deploy open | 1 |
| Fire sync after success | 2 |
| Build concurrency 1 | 3 |
| Compose limits | 4 |
| Live verify | 5 |
