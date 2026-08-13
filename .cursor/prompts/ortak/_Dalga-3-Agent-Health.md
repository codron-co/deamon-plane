# Dalga 3 — Agent Health (Task 8–9)

**Faz:** A (agent)  
**Önkoşul:** Dalga 2 (en az Site + encrypted `agent_secret` alanları)  
**Task’lar:** 8 CMS `/health` + HMAC · 9 plane `SiteAgentClient` + jobs/UI

---

## Amaç

Her Deamon instance’ta imzalı health; plane’de poll + dashboard unhealthy. Tema agent uçları stub kalabilir (Dalga 4).

---

## İki track (paralel)

```text
Track CMS (ayrı repo codron-co/deamon):
  CMS-AGENT-HEALTH (Task 8)

Track Plane (bu repo):
  PLANE-AGENT-CLIENT (Task 9) — imza stub ile başlar; CMS handoff sonrası kilitle

Ana agent:
  Sözleşmeyi birleştir → entegrasyon smoke (staging site veya Http::fake + CMS test fixtures)
```

**Asla:** CMS middleware/controller’ı `deamon-plane` altına kopyalama.

---

## CMS-AGENT-HEALTH (Task 8)

**Workspace:** Deamon CMS checkout (kullanıcı path’i; örn. `C:\wamp64\www\deamon\deamon`)

**Endpoints:**

| Method | Path | Amaç |
|--------|------|------|
| GET | `/internal/control/v1/health` | version, channel_hint, active_theme_id, php, queue_ok |

**Güvenlik:**

- HMAC-SHA256: `timestamp.nonce.body`; skew ±60s; nonce replay cache (Redis/cache TTL ~2 dk)
- `CONTROL_PLANE_AGENT_SECRET` yoksa route register etme
- Opsiyonel `CONTROL_PLANE_IP_ALLOWLIST`
- Response’ta secret yok

**Docs (CMS):** `docs/modules/deployment.md`, rules/skill sınır satırı, CHANGELOG + version bump

**Tests:** valid/invalid signature, skew, missing secret

**Handoff:** orkestratöre imza header adları, canonical string, örnek curl (secret maskeli)

---

## PLANE-AGENT-CLIENT (Task 9)

**Owns:** `app/Services/Agent/SiteAgentClient.php`, signing helper, `CheckSiteHealthJob`, schedule 5–15 dk, on-demand button, `last_health_*` update, dashboard unhealthy

**Tests:** agent fake

**Version gate hazırlığı:** health’ten `deamon_version` oku — channel switch (Task 5) ile bağla (yoksa TODO + wire)

---

## Merge / koordinasyon

1. CMS PR/branch notu → plane client constants
2. Plane `.env.example` site-side değil; site secret Coolify env’de (`CONTROL_PLANE_*` CMS tarafı)
3. Import edilmiş siteler: secret inject runbook’u başlat (tam otomasyon Dalga 5’te sertleştir)

---

## Verification gate

```
[ ] CMS Feature tests ControlPlane yeşil
[ ] Plane health client tests yeşil
[ ] Handoff dokümanı ledger’da
[ ] Dashboard unhealthy chip (fake data OK)
[ ] Plane tree’de CMS kopyası yok (rg EnsureControlPlaneSignature yalnızca deamon’da)
```

---

## Rapor + handoff

Starter’daki CMS handoff şablonunu doldur.

```txt
## Dalga 3 raporu — Deamon Plane
CMS track: <branch/PR> — Task 8
Plane track: Task 9
Sözleşme: <özet>
Verify: ...
Sonraki: Dalga 4 Themes
```
