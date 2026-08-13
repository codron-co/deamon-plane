# Security

Kaynak plan: [plans/2026-08-13-deamon-plane.md](plans/2026-08-13-deamon-plane.md) §8.

## Trust boundary

Ops browser → **Deamon Plane** → (Coolify API | GitHub | Site agent).  
Müşteri CMS admin’i plane’e erişmez.

## Zorunlu kontroller

| Alan | Kural |
|------|--------|
| Coolify / GitHub secret | Encrypted storage; log’a yazma; rotation runbook |
| Site agent | Per-site HMAC secret; timestamp + nonce; opsiyonel IP allowlist |
| Tema | Yalnızca org repoları; auto-update varsayılan **off** |
| Channel downgrade | Confirm + version gate |
| Plane erişimi | Az kullanıcı; 2FA; VPN/IP allowlist; `APP_DEBUG=false` |
| Audit | Create/switch/domain/deploy/theme mutasyonları append-only |

## Blast radius

- Plane ele geçirilirse → tüm Coolify fleet riski → token least-privilege + host izolasyonu kritik.
- Plane kendi Coolify Compose stack’inde (ayrı MySQL/Redis); müşteri siteleriyle paylaşmaz. `APP_DEBUG=false`.
- Tek Mailcow (ayrı sunucu) ele geçirilirse → mail domain’leri; plane ile aynı host’ta tutma.
