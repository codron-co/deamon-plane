# Security

Kaynak plan: [plans/2026-08-13-deamon-plane.md](plans/2026-08-13-deamon-plane.md) §8.

## Trust boundary

Ops browser → **Deamon Plane** → (Coolify API | GitHub | Site agent).  
Müşteri CMS admin’i plane’e erişmez.

## v1 checklist (Task 14 — closed)

| Alan | Durum | Kural |
|------|--------|--------|
| Auth / CSRF | **done** + hybrid | Fortify session + Blade CSRF. Login rate-limited by Fortify. Theme Manifest start is a CSRF POST; GitHub GET callbacks use one-time session `state` (not a Blade CSRF token). |
| Coolify / GitHub secret | **done** + hybrid | Encrypted columns; never rendered after save; [token-rotation.md](runbooks/token-rotation.md). Theme App: Manifest conversion stores PEM / `client_secret` / `webhook_secret` on `github_settings`; PAT lives on `theme_git_connections.token` only. |
| Coolify deploy webhook | **done** | HMAC raw body **or** query `token`/`secret` (`hash_equals`); empty secret rejected; `X-Coolify-Signature` |
| GitHub theme webhook | **done** | Distinct App-level secret (≠ Coolify); `X-Hub-Signature-256`; empty rejected; throttle 60/min; route by `installation.id` → connection |
| Site agent | **done** | Per-site HMAC; `{timestamp}.{nonce}.{rawBody}`; headers `X-Deamon-*`; clone_token never logged |
| Hostinger mail | **done** | Token encrypted on `mail_servers`; never sent to CMS/Blade; reverse HMAC `/internal/site/v1/mail`; audit omits passwords |
| Tema | **done** + hybrid | Connected GitHub user/org installs only (Plane picker); auto-update default **off**; no ZIP in Plane UI. Catalog is not a single `GITHUB_ORG` lock. |
| Channel downgrade | **done** | Confirm + version gate; backup reminder copy on the form |
| Plane erişimi | **done (docs + optional IP)** | `OPS_IP_ALLOWLIST` middleware; Coolify/VPN/SSO in [deploy-plane.md](runbooks/deploy-plane.md). SSO not mandatory in v1. |
| `APP_DEBUG` | **done** | Compose + Dockerfile force `false`; `ProductionDebugGuard` throws if production + debug |
| Audit | **done** | Create/provision/switch/domain/deploy/theme assign/update/activate/sync/auto-update; secrets omitted from `before`/`after` |
| Confirm | **done** | Destroy, channel leave-main, theme activate/assign-activate, theme git disconnect — `PlaneConfirm` (`window.confirm` yok) |
| Rate limits | **done** | Fortify login; `/webhooks/coolify` + `/webhooks/github` throttle 60/min |
| Secret leakage tests | **done** | Settings, Coolify webhook, GitHub webhook, `SecretRedactor` unit |

## Blast radius

- Plane ele geçirilirse → tüm Coolify fleet riski → token least-privilege + host izolasyonu kritik.
- Plane kendi Coolify Compose stack’inde (ayrı MySQL/Redis); müşteri siteleriyle paylaşmaz. `APP_DEBUG=false`.
- Tek Mailcow (ayrı sunucu) ele geçirilirse → mail domain’leri; plane ile aynı host’ta tutma. Mailcow API is **not implemented** (Coming soon in UI). Hostinger mail tokens live only in Plane; a compromised CMS site cannot mint Hostinger calls without the site HMAC secret, and even then only for that site’s assigned order.

## Residual / hybrid

- Coolify Notifications webhook POSTs are **unsigned** — Plane accepts `?token=` matching the webhook signing secret. HMAC remains preferred when a signer exists. `PollDeploymentJob` remains the backup.
- Agent secret inject after import is still **manual**.
- Live GitHub App Manifest + install is an operator step (catalog + tests do not require live credentials). Manifest needs a public Plane URL; PAT fallback is local/dev only. State/CSRF on Manifest and install callbacks. `clone_token` is the connection’s installation token — PAT is never sent to a site.
- Theme GitHub credentials moved Settings paste → Themes Manifest (2026-09-10). Plane connect/sync is shipped. CMS **1.2.7** already accepts any github.com `{owner}/{repo}` for `source=git` (ZIP/ssh/non-github still 422).
- Live Plane Coolify deploy (Task 15) is optional/high-risk if the spike app is unhealthy — prefer the UI checklist.
