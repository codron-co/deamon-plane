# Security

Kaynak plan: [plans/2026-08-13-deamon-plane.md](plans/2026-08-13-deamon-plane.md) §8.

## Trust boundary

Ops browser → **Deamon Plane** → (Coolify API | GitHub | Site agent).  
Müşteri CMS admin’i plane’e erişmez.

## v1 checklist (Task 14 — closed)

| Alan | Durum | Kural |
|------|--------|--------|
| Auth / CSRF | **done** | Fortify session + Blade CSRF. Login rate-limited by Fortify. |
| Coolify / GitHub secret | **done** | Encrypted columns; never rendered after save; [token-rotation.md](runbooks/token-rotation.md) |
| Coolify deploy webhook | **done** | HMAC raw body **or** query `token`/`secret` (`hash_equals`); empty secret rejected; `X-Coolify-Signature` |
| GitHub theme webhook | **done** | Distinct secret; `X-Hub-Signature-256`; empty rejected; throttle 60/min |
| Site agent | **done** | Per-site HMAC; `{timestamp}.{nonce}.{rawBody}`; headers `X-Deamon-*`; clone_token never logged |
| Tema | **done** | Org repos only; auto-update default **off**; no ZIP in Plane UI |
| Channel downgrade | **done** | Confirm + version gate; backup reminder copy on the form |
| Plane erişimi | **done (docs + optional IP)** | `OPS_IP_ALLOWLIST` middleware; Coolify/VPN/SSO in [deploy-plane.md](runbooks/deploy-plane.md). SSO not mandatory in v1. |
| `APP_DEBUG` | **done** | Compose + Dockerfile force `false`; `ProductionDebugGuard` throws if production + debug |
| Audit | **done** | Create/provision/switch/domain/deploy/theme assign/update/activate/sync/auto-update; secrets omitted from `before`/`after` |
| Confirm | **done** | Destroy, channel leave-main, theme activate/assign-activate — `PlaneConfirm` (`window.confirm` yok) |
| Rate limits | **done** | Fortify login; `/webhooks/coolify` + `/webhooks/github` throttle 60/min |
| Secret leakage tests | **done** | Settings, Coolify webhook, GitHub webhook, `SecretRedactor` unit |

## Blast radius

- Plane ele geçirilirse → tüm Coolify fleet riski → token least-privilege + host izolasyonu kritik.
- Plane kendi Coolify Compose stack’inde (ayrı MySQL/Redis); müşteri siteleriyle paylaşmaz. `APP_DEBUG=false`.
- Tek Mailcow (ayrı sunucu) ele geçirilirse → mail domain’leri; plane ile aynı host’ta tutma. Mailcow is **out of scope** for Plane v1.

## Residual / hybrid

- Coolify Notifications webhook POSTs are **unsigned** — Plane accepts `?token=` matching the webhook signing secret. HMAC remains preferred when a signer exists. `PollDeploymentJob` remains the backup.
- Agent secret inject after import is still **manual**.
- Live GitHub App install is an operator step (catalog + tests do not require live credentials).
- Live Plane Coolify deploy (Task 15) is optional/high-risk if the spike app is unhealthy — prefer the UI checklist.
