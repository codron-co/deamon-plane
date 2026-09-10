# Theme Git connections — implementation plan

> **For agentic workers:** Implement this plan task-by-task in the current session. Do **not** commit unless the operator explicitly asks (user git rule wins over frequent-commit habit). Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move theme GitHub credentials off Settings onto Themes. One Plane GitHub App (Manifest) with many user/org installations, plus an advanced PAT fallback. Catalog sync iterates every connection (`all` or `selected` repos). ZIP and Coolify `/github-apps` UUID stay out.

**Architecture:** Spec [../superpowers/specs/2026-09-10-theme-git-connections-design.md](../superpowers/specs/2026-09-10-theme-git-connections-design.md). Do not invent a different model.

**Tech stack:** Laravel 12, Blade + `ops.css` + vanilla JS, PHPUnit + `Http::fake`, encrypted Eloquent casts.

## Worker ownership (do not cross)

| Worker | Owns | Does not own |
|--------|------|----------------|
| **SDD docs** (this drop — **done**) | Spec, this plan, living Plane module/runbook/ledger/architecture/security/README, prompt index one-liners | `app/`, `database/`, `resources/`, `tests/`, `lang/` |
| **Code worker** (`deamon-plane`) | Migrations, models, GitHub client, sync, webhooks, Themes/Settings UI, routes, factories, `Http::fake` tests, `lang/{en,tr}` | CMS repo; rewriting the spec contract |
| **CMS worker** (`codron-co/deamon`) | **Done — CMS 1.2.7.** Theme git `repo` accepts any github.com `owner/name` (and https / `.git` forms). Do not edit `deamon` from this plan. | Plane tables, Manifest, Settings UI |

If code must diverge, update living Plane docs **in the same change set**. Do not touch `c:\workspace\deamon\deamon`.

## Global constraints

- Repo: `deamon-plane` only.
- One App, many installations. No new App per org unless the App was never created.
- Coolify GitHub App UUID ≠ theme catalog. Never copy `/github-apps` into `github_settings` or `theme_git_connections`.
- ZIP / marketplace / customer theme upload: out.
- First sync visibility: `private`. Do not reset existing visibility.
- `clone_token` = installation token only. PAT never sent to a site.
- Tests: `Http::fake` only. No live GitHub in CI.
- Connect/disconnect/select/sync: `ops.write`. Secrets never in Blade, logs, or audit `after`.
- Manifest needs a public `APP_URL`. Otherwise PAT-only.
- Do not commit or push unless the operator asks.

## File structure

**Create (code worker)**

- `database/migrations/2026_09_10_180000_create_theme_git_connections_tables.php` (tables + `themes.theme_git_connection_id` + `github_settings` slug/client columns + data backfill)
- `app/Models/ThemeGitConnection.php`
- `app/Models/ThemeGitConnectionRepo.php`
- `app/Enums/ThemeGitConnectionKind.php` (`github_app`, `pat`)
- `app/Enums/ThemeGitAccountType.php` (`user`, `organization`)
- `app/Enums/ThemeGitSelectionMode.php` (`all`, `selected`)
- `app/Enums/ThemeGitConnectionStatus.php` (`pending`, `connected`, `error`)
- `database/factories/ThemeGitConnectionFactory.php`
- `database/factories/ThemeGitConnectionRepoFactory.php`
- `app/Services/GitHub/GitHubAppManifest.php` (manifest JSON + conversion POST)
- `app/Services/GitHub/GitHubInstallationClient.php` or extend `GitHubAppClient` for per-installation list/mint (code worker picks one class; do not leave org-locked `listThemeRepos()` as SoT)
- `app/Http/Controllers/Ops/ThemeGitConnectionController.php`
- `resources/views/ops/themes/partials/connections.blade.php`
- `resources/views/ops/themes/github-manifest-redirect.blade.php` (auto-POST to GitHub `settings/apps/new` if needed)
- `tests/Feature/Themes/ThemeGitConnectionTest.php`
- `tests/Feature/Themes/ThemeGitManifestTest.php`
- `tests/Unit/GitHub/GitHubAppClientInstallationTest.php` (or fold into feature fakes)

**Modify (code worker)**

- `app/Models/GithubSetting.php` — `hasAppCredentials()` = app_id + PEM; fillable/hidden/casts for slug, client_id, client_secret
- `app/Models/Theme.php` — `theme_git_connection_id` + `connection()` belongsTo
- `app/Services/GitHub/GitHubAppClient.php` — list/mint per connection; stop `GET /orgs/{GITHUB_ORG}/repos` as the only list
- `app/Services/Themes/ThemeCatalogSync.php` — iterate every `connected` connection
- `app/Services/Themes/ThemeRolloutService.php` — mint from the theme’s connection installation
- `app/Services/GitHub/GitHubWebhookHandler.php` — route by `installation.id`
- `app/Http/Controllers/Ops/ThemeController.php` — pass connections to index
- `app/Http/Controllers/Ops/GithubSettingsController.php` — redirect leftover POSTs to Themes
- `app/Http/Controllers/Ops/SettingsController.php` — drop GitHub paste view data
- `app/Policies/ThemePolicy.php` or a `ThemeGitConnectionPolicy` — write = `canWriteOps()`
- `routes/web.php` / `routes/ops/themes.php` — Manifest, install, PAT, disconnect, selection
- `resources/views/ops/themes/index.blade.php` — connections panel
- `resources/views/ops/themes/show.blade.php` — owning connection
- `resources/views/ops/settings/index.blade.php` — pointer, no github partial form
- `resources/views/ops/settings/partials/github.blade.php` — replace with moved pointer **or** delete and inline
- `lang/en/themes.php`, `lang/tr/themes.php`, `lang/en/settings.php`, `lang/tr/settings.php`
- `config/ops.php` — comment that `themes.org` / `themes.repo_prefix` are legacy defaults, not a live lock
- `tests/Feature/Themes/ThemeCatalogSyncTest.php` — fake installation/PAT list, seed a connection
- `tests/Feature/Ops/GithubSettingsTest.php` — Settings no longer hosts secrets; leftover POST redirects
- `tests/Feature/Ops/NavPagesTest.php` / `CoolifySettingsTest.php` — stop asserting the old Settings GitHub form
- `tests/Feature/Themes/ThemeAssignTest.php` — mint uses connection installation id; PAT omits clone_token
- `tests/Feature/Webhooks/GitHubWebhookTest.php` — installation routing case
- `database/factories/GithubSettingFactory.php` — optional slug/client fields
- `database/factories/ThemeFactory.php` — optional `theme_git_connection_id`

**Already written (docs worker — do not rewrite the contract)**

- `docs/superpowers/specs/2026-09-10-theme-git-connections-design.md`
- `docs/plans/2026-09-10-theme-git-connections.md` (this file)
- `docs/modules/theme-catalog.md`, `theme-agent-client.md`, `github-webhooks.md`
- `docs/architecture.md`, `docs/security.md`, `docs/README.md`
- `docs/runbooks/token-rotation.md`, `docs/runbooks/theme-rollout.md`
- `docs/plans/progress-ledger.md`
- `.cursor/README.md` pointer

If implementation discovers a docs miss, patch the living module file in the same PR. Do not edit CMS docs from this repo.

---

### Task 1: Schema + models + legacy backfill

**Files:** migrations, models, enums, factories, `Theme`, `GithubSetting`, `config/ops.php`

**Interfaces:**

- `ThemeGitConnection` with encrypted hidden `token`; `hasPat()`, `isGithubApp()`, `displayName()`
- `ThemeGitConnectionRepo` unique per connection + `repo_full_name`
- `Theme::connection()` nullable belongsTo
- `GithubSetting` new fields; `hasAppCredentials()` **without** singleton `installation_id`

- [x] **Step 1: Write failing factory/model assertions** (included in Task 4 feature tests; factories used immediately)

- [x] **Step 2: Create tables + alter `github_settings` + `themes`**

```php
Schema::create('theme_git_connections', function (Blueprint $table) {
    $table->id();
    $table->string('name')->nullable();
    $table->string('account_login');
    $table->string('account_type'); // user | organization
    $table->string('installation_id')->nullable()->unique();
    $table->string('selection_mode')->default('all'); // all | selected
    $table->string('repo_name_prefix')->nullable();
    $table->string('status')->default('pending'); // pending | connected | error
    $table->text('last_error')->nullable();
    $table->string('kind'); // github_app | pat
    $table->text('token')->nullable();
    $table->timestamps();
    $table->unique(['account_login', 'kind']);
});

Schema::create('theme_git_connection_repos', function (Blueprint $table) {
    $table->id();
    $table->foreignId('theme_git_connection_id')->constrained('theme_git_connections')->cascadeOnDelete();
    $table->string('repo_full_name');
    $table->unsignedBigInteger('github_repo_id')->nullable();
    $table->string('default_branch')->default('main');
    $table->boolean('is_private')->default(true);
    $table->string('html_url')->nullable();
    $table->boolean('included')->default(true);
    $table->timestamp('last_seen_at')->nullable();
    $table->timestamps();
    $table->unique(['theme_git_connection_id', 'repo_full_name'], 'theme_git_conn_repos_unique');
});

// themes.theme_git_connection_id nullable FK nullOnDelete
// github_settings: slug, client_id, client_secret, html_url nullable; client_secret encrypted in model
```

- [x] **Step 3: Data backfill** (same migration, idempotent)

If zero connections and legacy credentials exist (DB or env): insert one connection as in the spec (`repo_name_prefix=deamon-theme-` or config prefix; `account_type=organization`; attach all existing `themes`). Then null singleton `installation_id` and copied PAT.

- [x] **Step 4: Config comment** — `ops.themes.org` / `repo_prefix` are legacy defaults only.

---

### Task 2: GitHub client — per installation / PAT

**Files:** `GitHubAppClient` (+ optional `GitHubInstallationClient`, `GitHubAppManifest`), unit/feature fakes

**Interfaces:**

- `listRepos(ThemeGitConnection $connection): list<repo>`
  - App: JWT + `POST /app/installations/{id}/access_tokens` + `GET /installation/repositories`
  - PAT: `GET /user/repos` and/or `GET /orgs/{login}/repos`
- `mintCloneToken(?ThemeGitConnection $connection): ?string` — App only; null for PAT / missing install
- `fetchThemeManifest` / `latestCommitSha` unchanged except they use the **connection** token
- `convertManifest(string $code): array` — `POST /app-manifests/{code}/conversions`
- `installationAccount(string $installationId): {login, type}` — `GET /app/installations/{id}`
- `appSlug(): string` — stored slug or `GET /app`
- Delete or stop calling org-locked `listThemeRepos()` / `org()` as catalog SoT
- Credentials exception copy: “Themes”, not “Settings”

- [x] **Step 1: Failing tests for installation list + PAT list + mint-per-install**
- [x] **Step 2: Implement**
- [x] **Step 3: Tests pass** (`Http::fake` only)

---

### Task 3: Catalog sync + webhook routing + assign clone_token

**Files:** `ThemeCatalogSync`, `GitHubWebhookHandler`, `ThemeRolloutService`, `SyncThemeCatalogCommand` (message only if needed)

**Sync:** every `status=connected` connection; prefix; `all` vs `selected`; collision rules from spec; visibility private on create only.

**Webhook:** HMAC unchanged. `installation.id` → connection → that connection’s themes. No installation id → global `repo_full_name` (PAT/manual). `installation` deleted/suspend → `status=error`. Fan-out / semver / concurrency unchanged.

**Assign:** `themePayload()` mints from `$theme->connection`. PAT → no `clone_token`. Audit snapshot still has no secrets.

- [x] **Step 1: Extend `ThemeCatalogSyncTest` + `GitHubWebhookTest` + `ThemeAssignTest`**
- [x] **Step 2: Implement**
- [x] **Step 3: Full theme/webhook/assign suites pass**

---

### Task 4: Themes UI + Settings pointer + i18n

**Files:** controllers, routes, Blade, lang en+tr, policy

**Routes (names are guidance; keep `ops.themes.*` prefix):**

| Method | Path | Name | Auth |
|--------|------|------|------|
| POST | `/themes/github/manifest` | `ops.themes.github.manifest` | write + CSRF |
| GET | `/themes/github/callback` | `ops.themes.github.callback` | write + state |
| POST | `/themes/github/install` | `ops.themes.github.install` | write + CSRF |
| GET | `/themes/github/installed` | `ops.themes.github.installed` | write + state |
| POST | `/themes/github/pat` | `ops.themes.github.pat` | write + CSRF |
| PUT | `/themes/connections/{connection}` | `ops.themes.connections.update` | write |
| POST | `/themes/connections/{connection}/sync` | `ops.themes.connections.sync` | write |
| DELETE | `/themes/connections/{connection}` | `ops.themes.connections.destroy` | write + confirm |
| POST | `/settings/github` | existing | **302 Themes** |
| POST | `/settings/github/test` | existing | **302 Themes** |

GET callbacks are auth’d ops session + `state`. They are not webhook-public.

UI rules: spec §UI. `PlaneConfirm` on disconnect. Webhook URL copyable. Manifest disabled copy when `APP_URL` is loopback. **Connect another** hidden until App exists.

Lang keys: spec table. Update `themes.lede`, `themes.empty.hint`, show-page sync hints that still say only `deamon-theme-*`.

- [x] **Step 1: Feature tests** (see test list below)
- [x] **Step 2: Implement UI + redirects + i18n**
- [x] **Step 3: Suites pass; Pint `--dirty` clean**

---

### Task 5: Docs touch-up (code worker only if behaviour drifted)

Living docs were written by the SDD worker. Code worker updates them **only** if a route name, column, or flash string changed.

- [x] **Step 1: Diff living docs against the shipped code**
- [x] **Step 2: Flip spec status to `approved` / `implemented` when the operator accepts**

---

## Test list (code worker)

`Http::fake` + `preventStrayRequests`. No live GitHub.

**Manifest / install**

- `test_manifest_start_requires_write_and_stores_state`
- `test_manifest_callback_rejects_bad_state`
- `test_manifest_conversion_stores_encrypted_app_fields_and_never_renders_them`
- `test_connect_another_does_not_convert_a_new_manifest_when_app_exists`
- `test_install_callback_creates_github_app_connection`
- `test_loopback_app_url_hides_manifest_offers_pat`

**Sync**

- `test_sync_all_without_prefix_upserts_every_installation_repo`
- `test_sync_selected_only_included`
- `test_legacy_prefix_skips_non_matching_repo_names`
- `test_two_connections_sync_with_two_installation_tokens`
- `test_theme_id_collision_skips_without_stealing_repo`
- `test_first_upsert_visibility_private_second_does_not_reset`
- `test_sync_with_zero_connections_does_not_call_orgs_repos`

**Webhook / assign / security**

- `test_webhook_routes_by_installation_id`
- `test_webhook_without_installation_matches_repo_full_name`
- `test_coolify_secret_does_not_validate_github` (keep)
- `test_assign_mints_clone_token_for_connection_installation`
- `test_pat_connection_assign_omits_clone_token_and_never_sends_pat`
- `test_viewer_forbidden_connect_disconnect_select_sync`
- `test_settings_page_has_pointer_not_pat_pem_fields`
- `test_legacy_settings_github_post_redirects_to_themes`
- `test_secrets_absent_from_audit_after_and_logs`

**Existing tests to repair**

- `ThemeCatalogSyncTest` (org list fake)
- `GithubSettingsTest`
- `NavPagesTest` / `CoolifySettingsTest` (Settings “GitHub theme catalog” form)
- `ThemeAssignTest` if mint URL is now per-installation

---

## Acceptance criteria

1. Settings has no GitHub PAT/PEM/org/installation paste form; one-line pointer to Themes.
2. Themes can connect a user and an org (same App, two `theme_git_connections` rows).
3. Per connection: `all` vs `selected` + optional prefix. Legacy migrated row keeps `deamon-theme-`.
4. Sync iterates every connected connection; first new theme is `private`.
5. `POST /webhooks/github` still HMAC-verified with the App secret; events route by installation id.
6. Assign sends `clone_token` only for App connections; PAT never leaves Plane toward a site.
7. Coolify `/github-apps` UUID is unused by this feature.
8. ZIP still absent (`rg` no theme `type=file` / ZipArchive).
9. Viewer cannot mutate connections.
10. Suite green with `Http::fake` only.
11. CMS repo untouched in the Plane change set.
12. Lang tr+en for new copy.

## Self-review

1. **Spec coverage:** Manifest, install, PAT, tables, sync, webhook, UI, security, CMS companion, migration, tests — each has a task or an explicit CMS-worker boundary.
2. **Placeholders:** none.
3. **Types:** `ThemeGitConnection`, `selection_mode`, `kind`, `mintCloneToken(connection)` used consistently.
4. **Existing tests:** org-list fakes and Settings GitHub form assertions **will fail** until Task 3–4 update them. Budget that repair in the same session.

## Deferred (intentional — do not gold-plate)

- Install callback does **not** auto-refresh `theme_git_connection_repos`. Operator clicks **Sync repos** on the connection.
- Disconnect deletes the Plane connection row only. The GitHub App on `github_settings` stays (no App deletion on last disconnect).
- Env-only credentials with no `github_settings` row are not auto-materialized; operator uses Themes → Connect GitHub / PAT.
- Shipped routes are `/themes/git/*` (`ops.themes.git.*`), not the plan’s `/themes/github/*` guidance names.

## Execution

Closer flip (2026-09-10): Plane code + living docs + `CMS_VERSION=1.2.7` agree. CMS 1.2.7 companion already landed — do not reopen `deamon` from this plan. Do not commit unless asked.
