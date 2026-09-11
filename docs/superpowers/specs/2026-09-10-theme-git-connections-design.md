# Theme Git connections (GitHub App Manifest)

**Date:** 2026-09-10  
**Status:** implemented — Plane code + CMS **1.2.7** allowlist landed; Settings paste retired  
**Repo:** Deamon Plane only. CMS (`deamon`) is a documented companion contract; this spec does not implement CMS.

SDD docs worker owns this file and the living Plane docs. Application code, migrations, Blade, tests, and lang files are the **code worker**. CMS allowlist (**1.2.7**) already landed in `deamon` — do not edit that repo from Plane.

## Context

Task 10 today treats the theme catalog as one GitHub org (`GITHUB_ORG` / `github_settings.org`, default `deamon-themes`) plus a pasted PAT or App id + installation id + PEM on **Settings**. `GitHubAppClient` lists `GET /orgs/{org}/repos` and keeps the `deamon-theme-` prefix. `POST /webhooks/github` matches `repository.full_name` → `themes.repo_full_name`. Assign mints a short-lived installation token from the **singleton** `github_settings.installation_id`.

That model cannot connect a user account and an organization at once, cannot pick a subset of repos, and keeps credentials on Settings next to env defaults.

**Locked product decision:** Coolify-style GitHub App connect (option A / Manifest). One Plane GitHub App, many installations. Connections live under **Themes**. ZIP and marketplace / customer theme upload stay out of scope.

Coolify’s own `GET /github-apps` UUID (customer compose app git source) is a **different object**. Never store or reuse it on `github_settings` or `theme_git_connections`.

## Goals

- Operator connects a GitHub **user** and/or **organization** from Themes (not a locked single `GITHUB_ORG` + paste form).
- First connect creates **one** Plane GitHub App via Manifest (same idea as Coolify). Later “Connect another” installs that **same** App on another account.
- Per connection: include **all** accessible repos **or** a selected subset. Optional repo-name prefix filter.
- Multiple accounts/orgs connected at once.
- First catalog upsert visibility stays **`private`**.
- `clone_token` stays a short-lived **GitHub App installation token**. A PAT is never sent to a site.
- Tests stay `Http::fake` only. Live GitHub is optional for operators.
- Settings GitHub paste form is removed; Settings shows a one-line pointer to Themes.

## Non-goals

- ZIP in Plane. Marketplace / customer theme ZIP upload.
- Creating a new GitHub App per org (unless the App was never created).
- Reusing Coolify `/github-apps` records or UUIDs.
- Changing fan-out concurrency, semver skip, or `auto_update` default (**off**).
- Re-implementing the CMS repo allowlist (shipped in CMS **1.2.7**; document the contract only).
- GitHub Enterprise Server, GitLab, SSH remotes, or unsigned git HTTP.
- Letting a customer CMS admin connect GitHub to Plane.

## Architecture

```
Ops browser → Plane Themes
  → GitHub App Manifest (once)     POST github.com/settings/apps/new
  → Manifest conversion            POST /app-manifests/{code}/conversions
  → App install (N times)          github.com/apps/{slug}/installations/select_target
  → Installation API               GET /installation/repositories
  → Catalog upsert                 themes + theme_git_connection_repos
  → CMS agent (unchanged HMAC)     clone_token = installation token only

POST /webhooks/github
  HMAC = github_settings.webhook_secret (App-level, ≠ Coolify)
  installation.id → theme_git_connections → that connection’s theme rows
```

Trust boundary stays: Ops → Plane → (Coolify | GitHub | Site agent | Cloudflare). Plane remains the only place that decides which repos enter the catalog and which catalog rows are assigned. CMS **v1.2.7** clones whatever `repo` Plane sends when it is a github.com `owner/name` (or the `https://github.com/…` / `.git` forms).

## Data model

### `github_settings` (keep; singleton App row)

Do **not** drop this table. Stop treating `org` as a hard catalog lock. Stop treating `installation_id` as the only install.

| Column | Notes |
|--------|--------|
| `org` | Legacy label only. Not used to list or filter the catalog. |
| `token` | Column remains (encrypted + hidden). New PATs are **not** saved here. Migration may copy a legacy PAT onto a connection, then leave this null. Env `GITHUB_TOKEN` is the same class of leftover. |
| `app_id` | Numeric GitHub App id from Manifest conversion (or legacy paste). |
| `slug` | **New.** From conversion / `GET /app`. Needed for `https://github.com/apps/{slug}/installations/select_target`. |
| `client_id` | **New.** From conversion. Not shown after save. |
| `client_secret` | **New.** Encrypted + hidden. From conversion. Not used for clone. |
| `installation_id` | Legacy singleton. Migration copies it onto the first connection, then Plane must not read this for list/mint. Column may stay nullable. |
| `private_key` | Encrypted PEM (`pem` from conversion). Hidden. |
| `webhook_secret` | Encrypted. App-level. Distinct from every Coolify `webhook_secret`. Env fallback `GITHUB_WEBHOOK_SECRET` unchanged. |
| `html_url` | **New, optional.** App settings URL for operators. |

`GithubSetting::hasAppCredentials()` becomes **`app_id` + `private_key`** (PEM). Installation id is per connection, not required to prove the App exists.

`GithubSetting::current()` remains the one App row.

### `theme_git_connections` (new)

| Column | Notes |
|--------|--------|
| `id` | bigIncrements |
| `name` | Operator label, nullable. Default display = `account_login`. |
| `account_login` | User or org login (`octocat`, `deamon-themes`). |
| `account_type` | `user` \| `organization` |
| `installation_id` | Nullable. Required when `kind=github_app` and `status=connected`. |
| `selection_mode` | `all` \| `selected` |
| `repo_name_prefix` | Nullable. Applied to the **repo name** (last path segment), not `owner/`. |
| `status` | `pending` \| `connected` \| `error` |
| `last_error` | Nullable safe string. No secrets. |
| `kind` | `github_app` \| `pat` |
| `token` | Encrypted + hidden. **PAT connections only.** Never sent to CMS. |
| timestamps | |

Indexes / uniqueness:

- Unique `installation_id` where not null.
- Unique (`account_login`, `kind`) so the same login is not connected twice as the same kind.
- Do not unique `account_login` alone (App + PAT on the same login is allowed but discouraged; UI warns).

### `theme_git_connection_repos` (new)

| Column | Notes |
|--------|--------|
| `id` | bigIncrements |
| `theme_git_connection_id` | FK cascade delete |
| `repo_full_name` | `owner/name` |
| `github_repo_id` | Nullable numeric GitHub id |
| `default_branch` | |
| `is_private` | bool |
| `html_url` | |
| `included` | bool. When `selection_mode=selected`, only `included=true` upsert into `themes`. |
| `last_seen_at` | |
| unique(`theme_git_connection_id`, `repo_full_name`) | |

A repo may appear on two connections’ picker lists. `themes.repo_full_name` stays **globally unique** (see sync rules).

### `themes`

Add nullable `theme_git_connection_id` FK (`nullOnDelete`). Existing columns unchanged: `theme_id` unique, `repo_full_name` unique, visibility default `private`.

Disconnect or connection delete **nulls** the FK. Catalog rows and `site_theme_installations` stay. Sync simply stops updating those rows until they belong to a connection again.

## Manifest / install flows

### Preconditions

Manifest requires a **public** Plane URL GitHub can redirect to (`APP_URL` https, host not `localhost` / `127.0.0.1` / `*.local`). If that is false, the UI offers only the **advanced PAT** path and explains why Manifest is disabled.

`ops.write` (Operator / Super Admin) for every connect, disconnect, select, and sync. `ops.view` (Viewer) sees the list with secrets blank.

### One App, many installations

1. **App missing** (`github_settings` has no `app_id` + PEM): **Connect GitHub** starts Manifest. Do not create a second App if those fields are already set.
2. **App exists:** **Connect GitHub** / **Connect another** skips Manifest and starts an install of the same App.
3. Re-running Manifest is allowed only when the App was never created (or the operator deleted PEM+`app_id` — break-glass). The conversion response **overwrites** App fields on the singleton row.

### Flow A — first connect (Manifest)

1. `POST /themes/github/manifest` (session CSRF). Plane stores a one-time `state` (32+ random bytes, session, 10 minutes).
2. Browser POSTs to `https://github.com/settings/apps/new` with `manifest` (JSON string) and `state`. Not an org-locked `organizations/{org}/settings/apps/new` — the operator picks the App owner on GitHub.
3. Manifest body (exact intent):

```json
{
  "name": "Deamon Plane Themes ({host})",
  "url": "{APP_URL}",
  "hook_attributes": {
    "url": "{APP_URL}/webhooks/github",
    "active": true
  },
  "redirect_url": "{APP_URL}/themes/github/callback",
  "callback_urls": ["{APP_URL}/themes/github/callback"],
  "setup_url": "{APP_URL}/themes/github/installed",
  "public": false,
  "default_permissions": {
    "contents": "read",
    "metadata": "read"
  },
  "default_events": ["push", "release"]
}
```

`{host}` is the Plane host so two Plane instances do not collide on App name. Permissions are read-only contents + metadata. Events match today’s webhook handler (`push`, `release`; `ping` is automatic).

4. GitHub redirects `GET /themes/github/callback?code={code}&state={state}`.
5. Plane rejects missing/mismatched/replayed `state` (403). Then `POST https://api.github.com/app-manifests/{code}/conversions` (no Plane user token; GitHub documents this as unauthenticated).
6. Store on `github_settings` (encrypted where marked): `app_id` ← `id`, `slug`, `client_id`, `client_secret`, `webhook_secret`, `private_key` ← `pem`, optional `html_url`. **Never** log or put these in audit `after` or Blade.
7. If `webhook_secret` was already set (legacy paste), Manifest **replaces** it — the new App signs with the new secret. Operator must not keep an old org webhook that used the previous secret. The App webhook URL is now the SoT.
8. Redirect the browser to `https://github.com/apps/{slug}/installations/select_target` with a fresh `state` bound to “pending install” (forces personal-vs-org picker; `/installations/new` often skips to the already-installed personal `target_id`).

### Flow B — install (first or “Connect another”)

1. Operator picks a **user account or organization** on GitHub and which repos that installation can see (GitHub’s own all/selected). Plane cannot see repos GitHub did not grant.
2. GitHub redirects `GET /themes/github/installed?installation_id={id}&setup_action={install|update}&state={state}`.
3. Validate `state`. `GET /app/installations/{id}` with App JWT → `account.login`, `account.type` (`User` → `user`, `Organization` → `organization`).
4. Upsert `theme_git_connections`: `kind=github_app`, `installation_id`, `account_login`, `account_type`, `status=connected`, `last_error=null`. Default `selection_mode=all`. Default `repo_name_prefix=null` on **new** connections. Default `name=account_login`.
5. Immediately list installation repos into `theme_git_connection_repos` (`last_seen_at=now`). Do not upsert `themes` until the operator hits Sync (or keep today’s “Sync catalog” as the only catalog write — **resolved:** listing on connect refreshes the picker only; catalog upsert runs on Sync).
6. Audit `theme.git_connection_connected` with `account_login`, `account_type`, `installation_id`, `kind`. No PEM, no token, no client_secret.

`setup_action=update` (operator changed GitHub repo grant): refresh `theme_git_connection_repos` for that installation; do not delete catalog themes.

### Flow C — advanced PAT (local / no public callback)

1. Themes → **Connect with token (advanced)**. Fields: `name` (optional), `account_login`, `account_type`, `token` (password, blank = keep), optional `repo_name_prefix`, `selection_mode`.
2. `POST /user` (or `GET /orgs/{login}` when type is organization) to verify the token. Store `kind=pat`, `token` encrypted on the **connection** row, `installation_id=null`, `status=connected`.
3. List repos with the PAT (`GET /user/repos` and/or `GET /orgs/{login}/repos`). Same picker + Sync rules.
4. `clone_token` on assign is **always null** for `kind=pat`. Public repos still clone; private repos need an App connection.
5. PAT connections do not create GitHub App webhook deliveries. Operator may still point a manual repo/org webhook at `POST /webhooks/github` using `github_settings.webhook_secret` (see webhooks).

### Flow D — disconnect

1. Confirm (`PlaneConfirm`). `DELETE` connection + picker rows.
2. `themes.theme_git_connection_id` set null. Installations stay.
3. Do **not** delete the GitHub App. Do **not** call GitHub uninstall (other connections share the App). Uninstall on GitHub is operator work; `installation.deleted` marks `status=error`.
4. Audit `theme.git_connection_disconnected` (login + kind only).

### Legacy slug

If `app_id` + PEM exist but `slug` is empty (old Settings paste), `GET /app` with JWT fills `slug` before showing **Connect another**.

## Sync rules

`ThemeCatalogSync` (UI **Sync catalog**, `php artisan ops:sync-theme-catalog`, background job `themes.catalog_sync`) iterates **every** `status=connected` connection.

Per connection:

1. Authenticate:
   - `github_app`: App JWT → `POST /app/installations/{installation_id}/access_tokens` → `GET /installation/repositories` (paginate, same 100/page and 20-page cap as today).
   - `pat`: PAT → `GET /user/repos` and/or `GET /orgs/{account_login}/repos`. **Not** `GET /orgs/{GITHUB_ORG}/repos` as a global lock.
2. Upsert every seen repo onto `theme_git_connection_repos` (`last_seen_at`). Repos that disappeared are left on the picker (`included` preserved); they are not deleted (GitHub pagination / grant flicker must not wipe selections).
3. Apply optional `repo_name_prefix` to the repo **name**. Empty prefix = no name filter.
4. Catalog candidates:
   - `all`: every prefix-matching seen repo.
   - `selected`: prefix-matching **and** `included=true`.
5. For each candidate, read `theme.json` then `theme/theme.json` on `default_branch` (unchanged). `latestCommitSha` unchanged.
6. Upsert `themes`:
   - Match existing by `repo_full_name` first, then by `theme_id` **only if that row’s `repo_full_name` is the same**.
   - **`theme_id` collision** (same `theme.json` id or fallback id, different `repo_full_name`): skip that repo, increment `skipped`, set connection `last_error` to a safe message. Do **not** steal the row or change `repo_full_name`.
   - **`repo_full_name` already owned by another connection:** update sha/manifest on the existing theme; do **not** move `theme_git_connection_id` (first owner keeps it). Still record the repo on this connection’s picker.
   - New row: `visibility=private`. Existing visibility is never overwritten (today’s `applyRepo` rule).
   - Set `theme_git_connection_id` on **new** rows to this connection.
7. Fallback `theme_id`: `theme.json` `id`, else repo name with this connection’s prefix stripped when the name starts with that prefix, else the raw repo name. Do not invent a new prefix globally.

`GITHUB_ORG` / `config('ops.themes.org')` / `github_settings.org` are **not** consulted during list or upsert. `config('ops.themes.repo_prefix')` is the **migration default** for the legacy connection only, not a global filter for new connections.

Sync with zero connections: flash that the operator must connect GitHub under Themes (not Settings).

## Webhook routing

`POST /webhooks/github` stays. Middleware still verifies `X-Hub-Signature-256` against `github_settings.webhook_secret` or `GITHUB_WEBHOOK_SECRET`. Empty secret rejected. Throttle 60/minute. Coolify secret must not validate.

After verify:

| Event | Behaviour |
|-------|-----------|
| `ping` | `{ok:true}` — unchanged |
| `push` / `release` | Resolve theme, update `latest_sha` / `latest_tag`, fan-out `ThemeUpdateJob` where `auto_update=true`, semver skip + `theme.update_skipped_version` — **unchanged in spirit** |
| `installation` `deleted` / `suspend` | Match `installation.id` → connection; `status=error`; `last_error` safe; no secret in logs |
| `installation_repositories` | Refresh that connection’s picker rows; catalog upsert still only on Sync |
| other | `{ok:true}` ignore |

**Theme resolve (push/release):**

1. If `payload.installation.id` is present: find `theme_git_connections.installation_id`. If missing, return ok without catalog writes (unknown install). Then match `repository.full_name` among **that connection’s** `themes` (or picker `included` rows that already have a theme). Fan-out only those installations.
2. If `installation.id` is absent (manual PAT/org webhook): today’s global `themes.repo_full_name` match.

Configure the **GitHub App** webhook via Manifest (`hook_attributes.url`). Do not tell operators to create a second org webhook unless they use PAT-only.

## UI

### Themes (SoT)

On `/themes` (above or beside the catalog table):

- Connections list: name/login, type, kind, selection_mode, prefix, status, last_error, repo counts. No tokens, no PEM, no client_secret.
- **Connect GitHub** (Manifest or install, depending on whether the App exists).
- **Connect another account/org** (same App install) when the App exists.
- **Connect with token (advanced)** — PAT fallback; collapsed.
- Per connection: selection_mode, optional prefix, repo picker (checkboxes when `selected`), **Sync**, **Disconnect** (confirm).
- Copyable webhook URL `https://{plane}/webhooks/github` + “secret is stored on the App; never shown.”
- Existing **Sync catalog** still syncs **all** connected connections (and still queues `themes.catalog_sync` on JSON).
- Catalog empty state: point to **Connect GitHub** on this page, not Settings.

Theme show: display owning connection login when `theme_git_connection_id` is set. Sync copy talks about connected accounts, not `deamon-theme-*` as the only shape.

Reuse `ops.css` / Coolify connection patterns. No CMS UI skills.

### Settings

Remove the GitHub paste form (`ops/settings/partials/github.blade.php` fields: org, PAT, app id, installation id, PEM, webhook secret, Save, Test).

Replace with one panel: catalog connections moved to Themes, link to `/themes`. Env defaults + customer defaults + Coolify pointer stay.

Leftover `POST /settings/github` and `POST /settings/github/test` **302 to Themes** (same leftover pattern as Coolify routes that already redirect).

### i18n (code worker owns files)

Specify keys/intent only. `lang/tr` + `lang/en`. Do not hardcode English in Blade.

| Key | Intent |
|-----|--------|
| `themes.connections.title` | GitHub connections heading |
| `themes.connections.lede` | One App, many user/org installs; Coolify GitHub App is different; ZIP still out |
| `themes.connections.empty` | No connections — use Connect GitHub |
| `themes.connections.connect` | Connect GitHub |
| `themes.connections.connect_another` | Connect another account/org |
| `themes.connections.connect_pat` | Connect with token (advanced) |
| `themes.connections.pat_hint` | Local/dev when Plane has no public callback URL; PAT never sent to a site |
| `themes.connections.manifest_requires_public_url` | Manifest needs a public `APP_URL` |
| `themes.connections.selection_all` | All accessible repos |
| `themes.connections.selection_selected` | Selected repos only |
| `themes.connections.prefix` | Optional repo name prefix |
| `themes.connections.prefix_hint` | Empty = no name filter. Legacy migrated row keeps `deamon-theme-` |
| `themes.connections.sync` | Sync this connection |
| `themes.connections.disconnect` | Disconnect |
| `themes.connections.disconnect_confirm` | Catalog rows stay; App is not deleted |
| `themes.connections.webhook_url` | Theme webhook URL (App-level) |
| `themes.connections.status_*` | pending / connected / error |
| `themes.empty.hint` | Was “Settings altında GitHub…”. Now: connect under Themes, then Sync |
| `themes.lede` | Drop hardcoded “org :org, repos :prefix”. Say connected accounts + git-only + auto-update off |
| `settings.github.moved` | One-line pointer + link label to Themes |
| `settings.github.moved_hint` | Coolify GitHub App UUID is still under Coolify, not here |

Keep unused `settings.github.*` paste strings out of the Settings view. Code worker may delete or leave them unused; do not keep the paste form “just in case.”

## Security

| Rule | Detail |
|------|--------|
| Auth | Connect / disconnect / select / sync / PAT save = `ops.write`. Viewer read-only, secrets blank. |
| Secrets | PEM, `client_secret`, connection `token`, `webhook_secret`, `clone_token` encrypted where stored; `$hidden`; never re-rendered; never in audit `before`/`after`; never in logs, flash, or Blade. |
| State | Manifest + install callbacks require one-time session `state`. Reject missing/mismatch/replay. GET callbacks have no CSRF token; `state` is the CSRF stand-in. |
| Manifest start | Session CSRF on the Plane POST that issues `state`. |
| Clone | `ThemeRolloutService` mints via the **theme’s connection** `installation_id`. `kind=pat` → omit `clone_token`. Never send `github_settings.token` or `theme_git_connections.token` to CMS. |
| Webhook | App-level secret only. Distinct from Coolify. Empty rejected. |
| Coolify | `GET /github-apps` UUID must not be written to Plane GitHub tables. |
| Confirm | Disconnect uses `PlaneConfirm`. |
| Public callback | Manifest off on loopback `APP_URL`. |

## CMS contract (landed in `deamon` — do not re-implement here)

CMS Task 11 theme git install is **Deamon v1.2.7** (SoT: CMS `docs/modules/control-plane-agent.md`, Sürüm 1.2.7). HMAC and `clone_token` unchanged. `source` omit or `"git"` only.

When `source=git`, `repo` accepts any github.com owner:

- `owner/name`
- `https://github.com/owner/name`
- `https://github.com/owner/name.git`

Owner/name = `[A-Za-z0-9_.-]+`, no `..`. ZIP / ssh / `file://` / `http://` / non-github hosts → 422 `unsupported_source`. `theme_id=default` → `system_theme`. Repo name no longer must match `theme_id` (`theme.json` id still must). Legacy `deamon-themes/premium-*` and `deamon-themes/deamon-theme-*` remain valid.

Plane is the trust boundary: only connected/selected catalog repos are assigned. Plane still sends `repo` as `owner/repo` (today’s `installBody`) and a short-lived `clone_token` when the connection is `github_app`. A PAT is never sent to the site.

No SSH. No ZIP. No copying theme PHP onto Coolify volumes. Plane `ControlPlaneAgentContract::CMS_VERSION` is **1.2.7**.

## Migration of existing `github_settings`

Run in the same schema migration (or an immediately following data migration). Idempotent: skip if any `theme_git_connections` row already exists.

When `github_settings` has a token **or** (`app_id` + PEM + `installation_id`), or the equivalent env (`GITHUB_TOKEN` / `GITHUB_APP_*`):

1. Insert one `theme_git_connections` row:
   - `account_login` = `github_settings.org` or `config('ops.themes.org')` or `deamon-themes`
   - `account_type` = `organization` (legacy path was org-locked)
   - `installation_id` = singleton / env installation id when present
   - `selection_mode` = `all`
   - `repo_name_prefix` = `deamon-theme-` (or `config('ops.themes.repo_prefix')` if set) — **so existing orgs do not suddenly ingest every repo**
   - `status` = `connected`
   - `kind` = `github_app` if App PEM+id exist, else `pat`
   - `token` = copy of singleton/env PAT when `kind=pat`
   - `name` = `account_login`
2. Set `themes.theme_git_connection_id` to that row for every existing theme.
3. Keep the `github_settings` row. Do not delete PEM / `app_id` / `webhook_secret`.
4. After a successful copy, null `github_settings.installation_id` (source of truth is the connection). Null `github_settings.token` if it was copied onto a PAT connection (column remains). Leave `org` as-is.
5. Backfill `slug` via `GET /app` when App credentials exist and the operator later clicks Connect another; migration itself does not call live GitHub.

Env-only credentials (no DB row) materialize a `github_settings` row **and** the legacy connection so the UI matches what `GitHubAppClient` used to resolve from config.

## Test strategy

`Http::fake` only. `Http::preventStrayRequests()` in the new suite. No live GitHub in CI.

Cover:

- Manifest start stores state; callback with bad state 403; conversion fake stores encrypted App fields; HTML/JSON/audit/log omit PEM and `client_secret`.
- Second connect does **not** POST `/app-manifests/.../conversions`; it hits the install URL for the same `slug`.
- Install callback creates a `theme_git_connections` row from `GET /app/installations/{id}`.
- Sync `all` + prefix null upserts every installation repo; sync `selected` upserts only `included`.
- Migrated legacy connection keeps prefix `deamon-theme-` and does not ingest `other-tooling`.
- Two connections sync independently (two installation tokens).
- `theme_id` collision across owners skips without stealing `repo_full_name`.
- First sync visibility `private`; second sync does not reset visibility.
- Webhook HMAC still App-level; Coolify secret fails; `installation.id` routes to that connection’s themes only.
- Assign mints `POST /app/installations/{connection.installation_id}/access_tokens`; PAT connection omits `clone_token`; PAT value never in agent body.
- Viewer forbidden on connect/disconnect/select/sync.
- Settings page shows the moved pointer and does **not** render PAT/PEM inputs; leftover Settings GitHub POSTs redirect to Themes.
- Settings / Themes HTML never contains live secrets (existing `SecretRedactor` / GithubSettings leakage cases move here).

Existing `ThemeCatalogSyncTest` fakes `GET /orgs/deamon-themes/repos` — that path is no longer the catalog SoT. Code worker updates those fakes to `/installation/repositories` or PAT `/user/repos` plus a factory connection.

## Open questions

None blocking. Resolutions:

| Topic | Resolution |
|-------|------------|
| Prefix on new connections | `null` (no filter). Legacy migrated row keeps `deamon-theme-`. |
| `GITHUB_ORG` | Not a catalog lock. Migration default for `account_login` only. |
| Catalog write on connect | Picker refresh only. Upsert `themes` on Sync. |
| Disconnect | Null FK; keep themes and installations; do not delete the App. |
| Same repo on two connections | First `theme_git_connection_id` wins; no steal. |
| `theme_id` clash | Skip + connection `last_error`. |
| PAT clone | Never send PAT; `clone_token` omitted. |
| PAT webhooks | No App installation id → global `repo_full_name` match if HMAC matches App/legacy secret. |
| Manifest vs existing App | Create App only when `app_id`+PEM missing. |
| Webhook secret on Manifest | Conversion value becomes SoT (replaces leftover Settings paste). |
| Coolify GitHub App | Untouched; different UUID space. |
| CMS allowlist | **Shipped** CMS 1.2.7 (any github.com owner). Plane does not re-implement it. |
| Loopback Plane | PAT fallback only. |

---

## Spec self-review

- **Placeholder:** none (`TODO` / `TBD` / `FIXME` avoided).
- **Contradiction vs historical Task 10 code (pre-implementation):** `GitHubAppClient` was org-locked (`GET /orgs/{org}/repos` + global prefix + singleton `installation_id` for mint). This spec replaced that. Shipped code lists per connection; `hasAppCredentials()` is app_id + PEM only; Settings is a Themes pointer.
- **Scope:** ZIP, marketplace, Coolify UUID reuse, CMS code, new App per org — out.
- **Ambiguity:** GitHub-side install selection (what the token can see) and Plane `selection_mode` (what enters the catalog) are both real; Plane never lists repos the installation cannot see.
