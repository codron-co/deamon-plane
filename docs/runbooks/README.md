# Runbooks

Uygulama ilerledikçe doldurulur. Özet akışlar plan §15’te.

| Runbook | Durum |
|---------|--------|
| [deploy-plane.md](deploy-plane.md) | Coolify Compose ile Plane kurulumu |
| [provision-site.md](provision-site.md) | Task 4 — draft/error → Coolify compose provision + poll / webhook |
| [import-coolify-apps.md](import-coolify-apps.md) | Task 7 — `ops:import-coolify-apps` dry-run → `--apply` |
| [channel-switch.md](channel-switch.md) | Task 5 — PATCH git_branch + deploy; confirm / version gate from last health; no DELETE |
| [agent-secret-inject.md](agent-secret-inject.md) | Task 9 — manual `CONTROL_PLANE_AGENT_SECRET` on CMS Coolify (import does not invent secrets) |
| [theme-rollout.md](theme-rollout.md) | Task 12–13 — git assign via CMS agent; webhook opt-in |
| [token-rotation.md](token-rotation.md) | Task 14 — Coolify / GitHub / agent / APP_KEY |
