# Wave 1 — Bulk App health fixes

> **For agentic workers:** Execute task-by-task. Spec: `docs/superpowers/specs/2026-09-11-bulk-app-health-fixes-design.md`

**Goal:** Sites list row + header can run App health fixes (one/all per site; category/all fleet).

**Architecture:** Reuse `SiteAppHealthFixer`; add `fix=all` + bulk job `sites.bulk_app_health_fix` via `OpsJobRunner`.

**Tech Stack:** Laravel Blade, `ops_background_jobs`, vanilla JS, Pest/PHPUnit `Http::fake`.

## Tasks

- [ ] T1: `SiteAppHealthFixer::neededFixes` / `fixAll` (ordered unique fixes)
- [ ] T2: Controller `fix=all`; bulk route + runner + langs
- [ ] T3: Index category counts + row/header menus UI
- [ ] T4: Tests + ops-sites.md + commit
