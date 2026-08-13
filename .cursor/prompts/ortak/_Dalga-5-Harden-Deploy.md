# Dalga 5 — Harden + Deploy (Task 14–15)

**Faz:** C  
**Önkoşul:** Faz A (ve mümkünse B) fake/test yeşil  
**Task’lar:** 14 security/runbooks · 15 production deploy control plane

---

## Amaç

Audit/redaction/IP allowlist; runbook’lar; plane’i Coolify’de kendi stack’i ile ayağa kaldırma; fleet import dry-run→apply disiplini; staging smoke.

---

## Sıra

```text
SECURITY (Task 14) ──► PROD-DEPLOY (Task 15)
```

Dar paralel: runbook yazımı ∥ redaction unit — aynı `docs/security.md` için tek yazar tercih et.

---

## SECURITY (Task 14)

**Owns:**

- Audit tüm mutasyonlarda (gap kapat)
- Opsiyonel IP allowlist middleware (plane UI)
- Token rotation runbook
- Channel switch backup reminder copy
- Secret-not-in-logs unit
- `docs/security.md`, `docs/runbooks/provision-site.md`, `channel-switch.md`, `theme-rollout.md` doldur

**Out of scope:** Mailcow mailbox API, DNS registrar, SSO zorunluluğu (SSO opsiyonel not)

---

## PROD-DEPLOY (Task 15)

**Owns:**

- Coolify’de Plane’i **Docker Compose** ile ayağa kaldır (`docker-compose.coolify.yml` — dosyayı sıfırdan yazma)
- Plane kendi MySQL+Redis (`plane_*` volumes; müşteri `deamon_*` ile paylaşmaz)
- Access: VPN/IP/SSO notları
- Import 35 sites: dry-run → apply checklist
- Smoke: 1 staging **CMS** site provision + channel switch (kullanıcı onayı + gerçek Coolify)
- Docs: `docs/modules/deployment.md` + `docs/runbooks/deploy-plane.md`

**Yasak:** Müşteri sitelerini paylaşımlı DB’ye taşıma; plane’i Deamon CMS container’ına gömme; Nixpacks’e geçmek

---

## Staging smoke checklist

- [ ] Plane login (ops user)
- [ ] Coolify test connection
- [ ] Staging site provision → `/up` 200
- [ ] Health yeşil (agent secret inject edilmiş)
- [ ] Channel switch → içerik/DB duruyor
- [ ] (Faz B) Tema assign opt-in
- [ ] Audit satırları görünür

---

## Verification gate

```
[ ] Redaction test yeşil
[ ] Runbooks boş değil
[ ] Dockerfile build (local) OK veya dokümante kısıt
[ ] Import dry-run runbook
[ ] Smoke sonucu ledger’da (pass/fail/blocked)
```

---

## Bitiş (ürün)

Ana agent:

1. Plan Task 0–15 checkbox durumu
2. Acceptance Faz A/B/C (`_Acceptance-Criteria.md`) işaretle
3. Broad code review subagent (opsiyonel skill)
4. Kullanıcıya finishing options: merge/PR (istemedikçe push/PR yok)

```txt
## Dalga 5 raporu — Deamon Plane
SECURITY: ...
PROD-DEPLOY: ...
Faz A/B/C: met / partial / blocked
Open follow-ups: ...
Subagent-driven: evet — tamamlandı | kaldı: <liste>
```
