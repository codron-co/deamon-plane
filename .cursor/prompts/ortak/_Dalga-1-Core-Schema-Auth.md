# Dalga 1 — Core Schema + Auth (Task 0–1)

**Faz:** A (çekirdek)  
**Önkoşul:** Dalga 0 **Go** veya **Hybrid**  
**Task’lar:** 0 (Laravel bootstrap), 1 (sites/deployments/audit şema)

---

## Amaç

Boş ops uygulaması: login, roller, denser shell, `config/ops.php`, fleet şema + modeller. Coolify gerçek çağrı **yok**.

---

## Sıra (zorunlu)

```text
BOOTSTRAP (Task 0) ──merge+verify──► SCHEMA (Task 1)
```

Paralel: Task 0 içinde layout vs auth alt parçaları **dikkatli ownership** ile ayrılabilir; migration’lar Task 1’e bırak.

---

## Subagent: BOOTSTRAP

**Owns:** Laravel 12 iskelet (repo kökü), Breeze/Fortify minimal auth, role middleware, denser layout + nav (Fleet, Sites, Themes placeholder, Settings), `config/ops.php`, PHPUnit smoke `GET /login`. Coolify sözleşmesi **zaten var** — üzerine yazma.

**Coolify Compose (katı):** Plane Coolify’de **Docker Compose** build pack ile kurulur. Mevcut dosyalar: `docker-compose.coolify.yml`, `Dockerfile`, `docker/**`, `.env.production.example`, `.dockerignore`. `composer create-project` bunları, `docs/`, `.cursor/` silmesin (non-destructive bootstrap). `APP_URL` ← `env('SERVICE_URL_APP')` fallback. Health: `GET /up`. İlk Coolify **Deploy** bu task’tan sonra yeşil olabilir.

**Steps (plan Task 0):**

1. Laravel 12 (PHP 8.2+) — mevcut docs/docker/compose’u ezme
2. Auth + `super_admin` | `operator` | `viewer`
3. Layout shell
4. `.env.example` + `config/ops.php` (channels, deamon repo, `DEAMON_COMPOSE_FILE=docker-compose.coolify.yml`, theme org prefix)
5. `APP_URL` / trusted proxies Coolify `SERVICE_*` ile
6. Smoke test (`GET /login`, mümkünse compose `/up`)
7. Commit yalnızca istenirse: `chore: bootstrap deamon-plane ops app`

**Yasak:** Coolify token ile canlı çağrı; theme catalog; CMS agent kodu; Mailcow; Nixpacks; `docker-compose.coolify.yml` silmek

**Hybrid notu:** Settings’te “manual checklist” placeholder OK; implementasyon Dalga 2.

---

## Subagent: SCHEMA

**Bekler:** BOOTSTRAP merge

**Owns:** migrations (`sites`, `site_domains`, `deployments`, `audit_logs`, ops users/roles as needed), enums `SiteStatus` / `Channel`, models + factories, `tests/Unit/Models/SiteTest.php` (veya Feature draft create)

**Şema SoT:** plan §4.1  
**State machine:** plan §4.2

**Yasak:** `themes` / `theme_site_access` / `site_theme_installations` (Dalga 4) — şimdilik oluşturma

---

## Merge kuralları

- `docs/**` ve `.cursor/**` silinmez
- README’deki “Laravel yok” satırını bootstrap sonrası güncelle (BOOTSTRAP veya ana agent)
- `routes/web.php` tek merge noktası

---

## Verification gate

```
[ ] php artisan migrate --pretend veya migrate fresh (local)
[ ] GET /login 200 (feature/smoke)
[ ] Role gate: viewer write engeli (en az bir assert veya manuel not)
[ ] Nav linkleri kırık değil (Themes placeholder OK)
[ ] config/ops.php channels = main,beta,alpha
[ ] docker-compose.coolify.yml + Dockerfile hâlâ mevcut (ezilmedi)
[ ] APP_URL SERVICE_URL_APP fallback
[ ] Site factory + draft create test yeşil
```

---

## Rapor

```txt
## Dalga 1 raporu — Deamon Plane
Subagents: BOOTSTRAP — ; SCHEMA —
Verify: <tests>
Hybrid flags carried: <from Dalga 0>
Sonraki: Dalga 2
```
