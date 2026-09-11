# Wave 2 — Deploy status + auto-deploy sync

> Spec: `docs/superpowers/specs/2026-09-11-deploy-status-app-health-sync-design.md`

**Goal:** App health deploy_failed tracks latest deploy; auto-deploy verifies Coolify flag.

## Tasks

- [ ] T1: `forDisplay` merge local issues; `refreshLocalCached`; latest-by-started_at
- [ ] T2: Hook Sync / writeRemoteState → refresh cache
- [ ] T3: Auto-deploy verify-after-PATCH
- [ ] T4: Tests + docs + commit
