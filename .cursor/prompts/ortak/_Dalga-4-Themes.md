# Dalga 4 — Themes (Task 10–13)

**Faz:** B  
**Önkoşul:** Dalga 3 health sözleşmesi; GitHub App credentials planı  
**Task’lar:** 10 catalog · 11 CMS theme git agent · 12 assign/rollout · 13 GitHub webhooks

---

## Amaç

`deamon-themes` (config) kataloğu; site’e git-bağlı tema; allowlist/public/private; push → opt-in auto update. **ZIP yok.**

---

## Paralel plan

```text
Phase 4a (paralel):
  THEME-CATALOG (Task 10)     — plane
  CMS-THEME-AGENT (Task 11)   — deamon repo

Phase 4b:
  THEME-ASSIGN (Task 12)      — plane; bekler 10 + 11 sözleşmesi

Phase 4c:
  THEME-WEBHOOKS (Task 13)    — plane; bekler 12
```

---

## THEME-CATALOG (Task 10)

**Owns:** migrations `themes`, `theme_site_access`, `site_theme_installations`; models; `GitHubAppClient`; `ThemeCatalogSync`; Themes index UI; parse `theme.json`

**Rules:**

- Repo prefix `deamon-theme-`
- Visibility: `public_catalog` | `allowlist` | `private`
- `minimum_deamon_version` nullable

---

## CMS-THEME-AGENT (Task 11) — ayrı repo

**Endpoints:** `POST .../themes/install|update|activate|sync`

**Owns (CMS):** `ThemeGitInstaller`, controllers, realpath under `themes/`, Process fake tests, docs `theme-repositories.md` + deployment

**Güvenlik:** path traversal test zorunlu; one-time clone token log’lanmaz; org dışı repo yok (plane zaten kısıtlar)

**Handoff:** request/response JSON şeması

---

## THEME-ASSIGN (Task 12)

**Owns:** visibility checks, site Themes tab, `ThemeRolloutService`, ThemeInstall/Update jobs, Sync now / Update to latest

**Default:** `auto_update` **off**

---

## THEME-WEBHOOKS (Task 13)

**Owns:** `GitHubWebhookController`, secret verify, match repo → theme → installations, fan-out rate limit, min version skip + ops notify

**Yasak:** Coolify webhook dosyalarını bozma (ayrı controller)

---

## Hard gates

```
[ ] Plane UI’da theme ZIP upload yok (rg file input / ZipArchive theme)
[ ] Müşteri rastgele repo bağlayamaz
[ ] Auto-update default off
[ ] Shared DB “optimizasyonu” yok
```

---

## Verification gate (Faz B)

```
[ ] Catalog sync test
[ ] Assign → agent fake → active
[ ] Webhook signature + fan-out + skip semver
[ ] CMS path traversal tests yeşil
[ ] Allowlist/public/private enforced
```

---

## Rapor

```txt
## Dalga 4 raporu — Deamon Plane
Subagents: CATALOG / CMS-THEME / ASSIGN / WEBHOOKS
CMS handoff Task 11: <branch>
ZIP in plane: yok (doğrulandı)
Auto-update default: off
Sonraki: Dalga 5
```
