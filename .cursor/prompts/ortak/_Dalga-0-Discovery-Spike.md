# Dalga 0 — Discovery / Coolify API Spike

**Faz:** 0  
**Süre hedefi:** 1–3 gün  
**Kod:** Minimal PoC/script veya Http client denemesi — **full Laravel app yok**  
**Kapı:** Go / Hybrid / No-Go → Dalga 1’i bloklar

Ana orkestrasyon: `_Subagent-Plane-Orkestrasyon.md`  
Plan: `docs/plans/2026-08-13-deamon-plane.md` §5, §12 Faz 0, §18

---

## Amaç

Coolify Public API v4 ile fleet otomasyonunun gerçekten yapılabildiğini kanıtla. Kanıt yoksa “yarı otomatik” (hybrid) moda düş veya ertele.

---

## Önkoşullar (kullanıcı / secrets)

Orkestratör bunları **istemeden veya env’den** netleştirir; secret’ları git’e yazmaz:

- Coolify base URL
- API token (least privilege mümkünse)
- Staging server + project UUID
- Denenecek **staging** application (production fleet’e dokunma)

Eksikse: kullanıcıya tek mesajda iste; spike’ı uydurma.

---

## Ana agent checklist

- [ ] Scope + HARD RULES okundu
- [ ] Staging hedef seçildi (prod app’e branch switch yok)
- [ ] Aşağıdaki API kanıtları toplandı
- [ ] Volume koruma doğrulandı veya risk yazıldı
- [ ] Adapter method imzaları taslaklandı (`listApps`, `createComposeApp`, `updateEnvs`, `setDomains`, `updateBranch`, `deploy`, `getDeployment`, servers/projects list)
- [ ] Eksik endpoint → manual step checklist
- [ ] §18 decision gates kilidi (önerilen default veya kullanıcı onayı)
- [ ] Go/Hybrid/No-Go kararı + Dalga 0 raporu

---

## Spike adımları (sıralı)

### 1) List

- Token ile applications (ve servers/projects) listele
- Kanıt: response shape notu (uuid alan adları)

### 2) Branch + deploy + status

- Staging app’te branch’i `alpha`↔`beta` (veya staging branch seti) değiştir
- Deploy tetikle
- Status + log oku (poll veya UI çapraz kontrol)
- Kanıt: request/response özeti (secret yok)

### 3) Domain bind

- Domain set/sync API doğrula
- Conflict davranışı not et

### 4) Volume persistence (kritik)

- Branch switch + redeploy sonrası MySQL/Redis/themes/storage volume’ların **silinmediğini** doğrula
- Compose volume adlarının app’e scoped olduğunu not et (provision runbook’a taşınacak)
- Başarısızsa: **No-Go veya Hybrid + manuel checklist**; ADR-7 riski yükselt

### 5) Create compose app (mümkünse)

- **Yalnızca Docker Compose build pack.** Nixpacks / Dockerfile-only deneme.
- Staging **müşteri** app: repo `codron-co/deamon` + `docker_compose_location=docker-compose.coolify.yml`
- Env (müşteri): yalnızca `APP_KEY` + `DEAMON_SITE_NAME` (+ Coolify `SERVICE_*`)
- **Plane’in kendisi** ayrı Coolify app: repo `codron-co/deamon-plane` + aynı build pack + bu repodaki `docker-compose.coolify.yml` (Dalga 0’da sözleşme dosyaları hazır; ilk yeşil deploy Laravel/Task 0 sonrası)
- Olmazsa: create’i manual step yap; updateBranch/deploy varsa hâlâ Hybrid olabilir

### 6) Dokümantasyon çıktısı

Spike bulgularını şuraya yaz (secret’sız):

- Tercih: plan dosyasına “Faz 0 sonuçları” bölümü veya `docs/plans/2026-08-13-coolify-spike-notes.md`
- Method imzaları Dalga 2 COOLIFY-CLIENT için kilit

---

## Karar matrisi

| Koşul | Sonuç |
|-------|--------|
| list + updateBranch + deploy + getDeployment + setDomains OK; volume OK | **Go** |
| Kritik biri eksik ama checklist ile tamamlanabilir | **Hybrid** — UI’da “Coolify’de aç” + adım listesi; otomasyon kısmi |
| Branch/deploy yok veya volume siliniyor ve çözülemiyor | **No-Go** — fleet MVP ertele; kullanıcıya rapor |

Hybrid’de bile: paylaşımlı DB önermek **yasak**. Eksik API’yi “paylaşımlı stack” ile çözme.

---

## Decision gates (§18) — bu dalgada kilitle

| # | Default |
|---|---------|
| 1 Coolify project | Tek project + tag/slug |
| 2 Theme org | `deamon-themes` config; taşıma ayrı iş |
| 3 Agent network | Public HTTPS + HMAC (+ IP allowlist opsiyonel) |
| 4 Rollback | Manuel v1 |
| 5 Auto-update theme | Off |
| 6 Agent secret inject | Import sonrası toplu env patch + redeploy runbook |

Out of scope (sorma): Mailcow, müşteri self-serve, ZIP theme UI, shared MySQL/Redis.

---

## Subagent kullanımı

- Ana agent yeter; büyük API keşfinde `explore` / `shell` subagent OK
- Paralel implementer **yok** (henüz app yok)
- PoC script repo’da ise `tools/coolify-spike/` gibi izole path; production credentials commit etme

---

## Verification gate

```
[ ] Kanıt notları secret’sız dosyada
[ ] Go | Hybrid | No-Go yazılı
[ ] Volume sonucu yazılı
[ ] Adapter imza taslağı hazır
[ ] §18 defaults kilitli
[ ] Laravel create-project HENÜZ yapılmadı (Dalga 1)
```

---

## Dalga 0 rapor şablonu

```txt
## Dalga 0 raporu — Deamon Plane
Subagent-driven: evet (spike)
Dalga: 0 — Discovery-Spike
Karar: Go | Hybrid | No-Go
API:
  - listApps: ok|fail — <not>
  - updateBranch: ok|fail
  - deploy/status: ok|fail
  - setDomains: ok|fail
  - createComposeApp: ok|fail|skipped
Volume persistence: ok|risk|fail — <not>
Hybrid manual steps: <liste veya yok>
§18 locks: <özet>
Artifacts: <path>
Sonraki: Dalga 1 | DUR
```

**No-Go ise:** kullanıcı onayı olmadan Dalga 1 başlatma.
