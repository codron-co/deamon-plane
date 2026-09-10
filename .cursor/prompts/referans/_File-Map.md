# File Map & Ownership

Kaynak: plan §11. Subagent’lar **exclusive path** ile fırlatılır; çakışmada ana agent merge eder.

---

## Bu repo: `deamon-plane`

| Path | Role | Primary owner (task) |
|------|------|----------------------|
| `app/Models/Site.php` | Fleet site | SCHEMA (1); sonra ana agent sıra |
| `app/Models/Deployment.php` | Deploy kaydı | SCHEMA (1) |
| `app/Models/Theme.php` | Katalog (`theme_git_connection_id`) | THEME-CATALOG (10) + 2026-09-10 |
| `app/Models/ThemeGitConnection.php` | Themes GitHub user/org connection | 2026-09-10 theme-git-connections |
| `app/Models/SiteThemeInstallation.php` | Kurulum | THEME-CATALOG (10) |
| `app/Models/AuditLog.php` | Audit | SCHEMA (1) |
| `app/Services/Coolify/CoolifyClient.php` | HTTP API | COOLIFY-CLIENT (2) |
| `app/Services/Coolify/CoolifyApplicationService.php` | Provision/branch/domain | COOLIFY-CLIENT / PROVISION (2/4) |
| `app/Services/GitHub/GitHubAppClient.php` | App auth + repos (per `theme_git_connections`; not Coolify `/github-apps`) | THEME-CATALOG (10) + 2026-09-10 connections |
| `app/Services/Agent/SiteAgentClient.php` | İmzalı HTTP | PLANE-AGENT-CLIENT (9) |
| `app/Services/Sites/SiteProvisioner.php` | Orkestrasyon | PROVISION (4) |
| `app/Services/Sites/ChannelSwitcher.php` | Channel policy | CHANNEL (5) |
| `app/Services/Themes/ThemeCatalogSync.php` | Connections → DB (not a single `GITHUB_ORG` lock) | THEME-CATALOG (10) + 2026-09-10 |
| `app/Services/Themes/ThemeRolloutService.php` | Fan-out | THEME-ASSIGN (12) |
| `app/Jobs/*` | Provision, poll, switch, theme | ilgili task |
| `app/Http/Controllers/Ops/*` | Web UI | UI-SITES / themes / settings |
| `app/Http/Controllers/Webhooks/CoolifyWebhookController.php` | Deploy events | WEBHOOKS-DEPLOY (6) |
| `app/Http/Controllers/Webhooks/GitHubWebhookController.php` | Theme push | THEME-WEBHOOKS (13) |
| `app/Http/Middleware/VerifyCoolifyWebhook.php` | | WEBHOOKS-DEPLOY (6) |
| `app/Policies/*` | Role gates | BOOTSTRAP + UI |
| `database/migrations/*` | Şema | SCHEMA (1) + THEME (10) ayrı migration dosyaları |
| `resources/views/ops/*` | Blade | UI owners by area |
| `routes/web.php` / `routes/webhooks.php` | | Ana agent merge hub tercih |
| `config/ops.php` | | BOOTSTRAP; sonraki patch tek agent |
| `docs/**` | | SECURITY (14) + mevcut; silme |
| `.cursor/**` | Orkestrasyon | Dokunma (build sırasında) |
| `tests/Feature/*`, `tests/Unit/*` | | ilgili task |
| `docker-compose.yml` | Yerel plane (`APP_PORT` 8088) | BOOTSTRAP — ezme |
| `docker-compose.coolify.yml` | Coolify Compose (plane app+mysql+redis) | Dalga 0 sözleşme; BOOTSTRAP bağlar; Task 15 ezmez |
| `Dockerfile` + `docker/**` | Plane image (nginx+fpm+queue) | Dalga 0 sözleşme; BOOTSTRAP Laravel’i bağlar; Task 15 ezmez |
| `.env.production.example` | Coolify env (`APP_KEY` + sonraki secret’lar) | Dalga 0; BOOTSTRAP `APP_URL`/`SERVICE_*` |

---

## Deamon CMS repo (ayrı track)

| Path | Role | Task |
|------|------|------|
| `routes/internal.php` (veya conditional) | Agent routes | 8 / 11 |
| `app/Http/Middleware/EnsureControlPlaneSignature.php` | HMAC | 8 |
| `app/Http/Controllers/Internal/Control/*` | health, themes | 8 / 11 |
| `app/Services/ControlPlane/ThemeGitInstaller.php` | clone/pull | 11 |
| `config/deamon.php` | `control_plane` keys | 8 |
| `.env.example` / production example | `CONTROL_PLANE_*` | 8 |
| `docs/modules/deployment.md` | Agent bölümü | 8 / 11 |
| `docs/modules/theme-repositories.md` | Agent install | 11 |
| `tests/Feature/ControlPlane/*` | | 8 / 11 |
| `.cursor/rules/deamon-project.mdc` | Sınır satırı | 8 |
| `.cursor/skills/deamon-mvp/SKILL.md` | Sınır notu | 8 |
| `CHANGELOG.md` + version | | 8 / 11 ship |

---

## Çakışma önleme kuralları

1. Theme migrations ≠ fleet migrations — ayrı dosya adları / timestamp sırası ana agent.
2. Coolify webhook ≠ GitHub webhook — ayrı controller/middleware.
3. `SiteController`: CRUD (Task 3) bitmeden Channel action (Task 5) paralel yazdırma.
4. `config/ops.php`: tek yazar per dalga veya ana agent uygular.
5. CMS path’leri plane checkout’unda oluşturma.

---

## İstisna: doküman koruması

Mevcut `docs/plans/2026-08-13-deamon-plane.md`, ADR, related-infra, `.cursor/prompts/**`, **`docker-compose.coolify.yml` / `Dockerfile` / `docker/**` / `.env.production.example`** bootstrap sırasında **silinmez / ezilmez**. Laravel create-project boş kökteyse: docs + docker’ı önce stash/taşı veya non-destructive bootstrap prosedürü uygula.
