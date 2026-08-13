# Acceptance Criteria — Faz 0 / A / B / C

SoT: plan §12. Orkestratör dalga sonunda bunlara karşı raporlar.

---

## Faz 0 — Discovery / spike

**Çıktı:** Coolify API kanıtı (script veya PoC notları).

| # | Kriter | Kanıt |
|---|--------|-------|
| 0.1 | Token ile list applications | Spike notu |
| 0.2 | Staging: branch değiştir + deploy + status | Spike notu |
| 0.3 | Domain bind API doğrulandı | Spike notu |
| 0.4 | Branch switch sonrası volume korundu | Spike notu veya risk+mitigation |
| 0.5 | Eksik API → manual checklist | Adapter/UI notu |

**Go/No-Go:** Branch + deploy + domain yoksa tam otomasyon ertelenir (Hybrid veya No-Go).

**Dalga:** 0

---

## Faz A — Fleet MVP

| # | Kriter | Kanıt |
|---|--------|-------|
| A.1 | Panelden yeni site → Coolify stack ayağa → domain yanıt | Feature fake + staging smoke |
| A.2 | `alpha`↔`beta`↔`main` → aynı domain; DB içeriği kaybolmaz | Tests + staging smoke |
| A.3 | Deploy listesi gerçek status | Webhook/poll + UI |
| A.4 | Mevcut sitelerin çoğu import | Dry-run → apply + sayım |
| A.5 | Agent `/health` çalışır (tema stub OK) | CMS + plane tests / staging |

**Dalga:** 1–3 (A.4 import Task 7; A.5 Dalga 3)

---

## Faz B — Theme ops

| # | Kriter | Kanıt |
|---|--------|-------|
| B.1 | Org temaları katalogda | Sync + UI |
| B.2 | Site’e git tema + sync (ZIP yok) | Assign job + UI assert no ZIP |
| B.3 | Allowlist / public visibility | Tests |
| B.4 | Push → allowlist sitelerde update (opt-in) | Webhook fan-out test |
| B.5 | `minimum_deamon_version` ihlali → skip + notify | Unit/feature |

**Dalga:** 4

---

## Faz C — Sertleştirme

| # | Kriter | Kanıt |
|---|--------|-------|
| C.1 | Audit + redaction + runbooks | docs + unit |
| C.2 | Plane Coolify Docker Compose deploy | `docker-compose.coolify.yml` + `docs/modules/deployment.md` + `/up` |
| C.3 | Access kısıtı (IP/VPN/SSO not) | security/runbook |
| C.4 | Staging smoke provision + channel | Ledger |
| C.5 | (Opsiyonel) DNS verify, notify, metrics, SSO | Bilinçli TODO OK |

**Dalga:** 5

Mailcow, self-serve, shared DB, ZIP plane — **acceptance’a dahil değil** (kapsam dışı başarı sayılmaz).

---

## Orkestratör işaretleme

Her faz için: `met` | `partial` | `blocked` + eksik madde listesi.  
Partial ile sonraki faza geçiş: yalnızca açık risk kabulü + kullanıcı bilgilendirmesi (özellikle Faz 0 Hybrid).
