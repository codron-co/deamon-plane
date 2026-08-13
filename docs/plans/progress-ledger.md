# Plane progress ledger

Durable orchestrator state. Do not re-dispatch completed tasks.

## Dalga 0 — Discovery / Spike

- Status: **in progress / BLOCKED on live Coolify credentials**
- OpenAPI method map: `docs/plans/2026-08-13-coolify-spike-notes.md`
- Spike script: `tools/coolify-spike/`
- Go/Hybrid/No-Go: **not declared** (live proof missing)
- Coolify Compose sözleşmesi (Dalga 0): `docker-compose.coolify.yml` + `Dockerfile` + `docker/` — **Laravel yok; ilk Deploy Task 0 sonrası**
- Laravel create-project: **not started**
- CMS code in this repo: none
- Mailcow: out of scope
- Commit: none (user did not request)

## Task 0–15

| Task | Role | Status |
|------|------|--------|
| Spike | Dalga 0 | blocked — need COOLIFY_BASE_URL + token + staging UUID |
| 0 bootstrap | BOOTSTRAP | not started (needs Go/Hybrid) |
| 1 schema | SCHEMA | not started |
| 2 Coolify client | COOLIFY-CLIENT | not started |
| 3 site CRUD | UI-SITES | not started |
| 4 provision | PROVISION | not started |
| 5 channel | CHANNEL | not started |
| 6 webhooks | WEBHOOKS-DEPLOY | not started |
| 7 import | IMPORT | not started |
| 8 CMS health | CMS-AGENT-HEALTH (deamon) | not started — separate repo |
| 9 plane agent client | PLANE-AGENT-CLIENT | not started |
| 10 theme catalog | THEME-CATALOG | not started |
| 11 CMS theme agent | CMS-THEME-AGENT (deamon) | not started — separate repo |
| 12 assign | THEME-ASSIGN | not started |
| 13 GH webhooks | THEME-WEBHOOKS | not started |
| 14 security | SECURITY | not started |
| 15 prod deploy plane | PROD-DEPLOY | not started |
