# Sync After Deploy Host Guard — Plane Design

**Date:** 2026-09-12  
**Status:** approved (Approach B)  
**Companion:** CMS `docs/superpowers/specs/2026-09-12-deploy-xml-host-guard-design.md`

## Problem

Plane can fire theme sync (and health) while a Coolify deploy is still settling, stacking agent/DB load on the same site that just ran entrypoint work. Coolify API is paced; **build concurrency and per-site deploy↔sync mutex are not**.

## Goals

1. Theme sync runs only when the site has **no unfinished** `Deployment` rows; otherwise defer until deploy finishes.
2. At most **one in-flight Coolify build per Coolify connection/server**.
3. Plane compose gets the same class of `mem_limit` / MySQL buffer caps as CMS.

## Design

### Deferred sync

- `ThemeRolloutService::syncNow` / install sync path: if `Deployment::query()->where('site_id', $site->id)->whereNull('finished_at')->exists()`, persist a pending sync marker (cache key or `site_theme_installations.pending_sync_after_deploy=true`) and return ok deferred message.
- `PollDeploymentJob` (or `CoolifyDeploymentSync` success path): when a deployment finishes successfully, dispatch `ThemeSyncAfterDeployJob` for that site if pending.
- Failed/cancelled deploy: clear pending sync (do not sync on failed deploy) unless operator explicitly Syncs again.

### Build concurrency

- Before `CoolifyClient::deploy` / `CoolifyDeploySettings::redeploy`, count unfinished deployments for sites sharing the same `coolify_connection_id` (or server uuid). If ≥1 other in-flight, throw retryable / queue delayed job instead of starting another build.
- Config: `ops.coolify.deploy.max_concurrent_per_server` default `1`.

### Compose

Plane `docker-compose.coolify.yml`: app 768m, mysql 768m + small innodb command, redis 128m, pids/cpus aligned with CMS.

## Success

- Feature test: sync while deploy open → deferred; after finish → agent sync called once.
- Feature test: second deploy blocked/deferred while first in-flight.
- Live Plane containers show Memory limits after redeploy.
