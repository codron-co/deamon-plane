# Deamon Plane

CodRon **internal ops** paneli: Coolify üzerindeki Deamon müşteri sitelerini (fleet), kanal (`main` / `beta` / `alpha`), domain, deploy durumu ve (sonraki faz) git-bağlı tema mağazasını yönetir.

Bu repo **Deamon CMS değildir.** CMS: [`codron-co/deamon`](https://github.com/codron-co/deamon). Plane is a Laravel 12 ops app in this repository.

## Deploy (Coolify)

Build pack **Docker Compose** → `docker-compose.coolify.yml` (app + own MySQL + Redis). Not Nixpacks. Not Dockerfile-only.

Steps: [docs/modules/deployment.md](docs/modules/deployment.md). Laravel scaffold (Task 0) is in tree — first Coolify **Deploy** can succeed once this branch has `composer.json` (image installs vendors in Docker).

Local stack:

```bash
cp .env.example .env
php artisan key:generate
docker compose --env-file .env up --build -d
# http://localhost:8088/login   health: http://localhost:8088/up
```

## Local login

After migrate + seed (entrypoint runs `migrate` on boot; seed once):

```bash
php artisan migrate --seed
```

Default **local** super_admin (from `.env.example`, not for production):

- Email: `ops@localhost.test`
- Password: `password`

Override with `OPS_SEED_EMAIL` / `OPS_SEED_PASSWORD`. Leave those unset in production.

Roles: `super_admin`, `operator`, `viewer` (viewer is read-only).

## Dokümantasyon

| Dosya | İçerik |
|-------|--------|
| [docs/README.md](docs/README.md) | İndeks |
| [docs/plans/2026-08-13-deamon-plane.md](docs/plans/2026-08-13-deamon-plane.md) | **Tam uygulama planı** (mimari, şema, task’lar) |
| [docs/architecture.md](docs/architecture.md) | Kısa mimari özet |
| [docs/subagent-orchestration.md](docs/subagent-orchestration.md) | Subagent dalgaları (pointer) |
| [docs/decisions/README.md](docs/decisions/README.md) | ADR / karar kaydı |
| [docs/security.md](docs/security.md) | Güvenlik modeli |
| [docs/modules/deployment.md](docs/modules/deployment.md) | Coolify Docker Compose (Plane) |

## Durum

- Plan, kararlar ve **orkestrasyon prompt paketi**: hazır
- Dalga 0: **Go** — [spike notes](docs/plans/2026-08-13-coolify-spike-notes.md)
- Laravel 12 ops scaffold (Task 0): login, roles, denser shell, `config/ops.php`
- Fleet schema (`sites` / deployments): Task 1 done; Coolify deploy webhooks + fleet KPI: Task 6 (HMAC `POST /webhooks/coolify`)
- Kullanıcı modeli v1: yalnızca internal ops (müşteri self-service yok)

## İlişkili sistemler

- **Coolify** — Plane ve her müşteri sitesi ayrı Compose app (`docker-compose.coolify.yml`; paylaşımlı DB yok; Nixpacks yok)
- **Deamon CMS agent** — imzalı `/internal/control/v1/*` (CMS reposunda; ayrı track)
- **Tema org** — `deamon-themes` / `deamon-theme-{id}` (git; ZIP yok)
- **Mailcow** — ortak mail sunucusu (plane v1 build dışı; bkz. related-infra)

## Geliştirme

1. Starter: [.cursor/prompts/000_START-Plane-Orkestrasyon.md](.cursor/prompts/000_START-Plane-Orkestrasyon.md)
2. SoT: [docs/plans/2026-08-13-deamon-plane.md](docs/plans/2026-08-13-deamon-plane.md)
3. Tests: `php artisan test`
